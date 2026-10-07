<?php

namespace App\Services\Users;

use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\Topology\DryRunRollback;
use App\Support\AuditContext;
use App\Support\InMemorySharedStrings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates user accounts from a staff list (XLSX or CSV). Only the e-mail address is needed:
 *
 *   E-mail | Name | Username | Password | Department | Roles | Team | Status | Phone
 *
 * - no name      → read from the address: john.doe@… is "John Doe";
 * - no username  → the part before the @, with dashes: "john-doe" (numbered if already taken);
 * - department, roles, team — the column, or the default given for the whole file; matched
 *   without case against what exists (a role, team or department is never created here);
 * - several roles or teams in one cell are separated by a comma or a semicolon.
 *
 * People already in the system (same e-mail address) are left alone unless asked to update them;
 * their password is never touched. Passwords are never shown, logged or put in the report.
 * All or nothing: one bad row and no account is created.
 */
class UserImporter
{
    /** column key => accepted header spellings (letters and digits only, lower case) */
    public const HEADERS = [
        'email' => ['email', 'emailaddress', 'mail', 'workemail', 'column1'],
        'name' => ['name', 'fullname', 'names', 'employee', 'staffname'],
        'username' => ['username', 'user', 'login', 'userid'],
        'password' => ['password', 'pass', 'pwd', 'initialpassword'],
        'department' => ['department', 'dept'],
        'roles' => ['roles', 'role'],
        'teams' => ['team', 'teams'],
        'status' => ['status', 'active', 'state'],
        'phone' => ['phone', 'phonenumber', 'mobile', 'telephone', 'contact'],
    ];

    public const ERRORS_LISTED = 200;

    /**
     * @param  array{dry_run?: bool, update?: bool, department?: ?string, roles?: ?string, teams?: ?string,
     *     random_passwords?: bool, allow_weak_passwords?: bool}  $options
     * @return array{rows: int, created: int, updated: int, skipped: int, names_from_email: int, usernames_made: int,
     *     random_passwords: int, weak_passwords: int, errors: list<string>, error_rows: int, dry_run: bool}
     */
    public function import(string $path, array $options = []): array
    {
        $options += ['dry_run' => false, 'update' => false, 'department' => null, 'roles' => null, 'teams' => null, 'random_passwords' => false, 'allow_weak_passwords' => false];
        $report = ['rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'names_from_email' => 0, 'usernames_made' => 0,
            'random_passwords' => 0, 'weak_passwords' => 0, 'errors' => [], 'error_rows' => 0, 'dry_run' => (bool) $options['dry_run']];

        $people = $this->prepare($this->read($path), $options, $report);

        if ($report['rows'] === 0) {
            throw new InvalidArgumentException('The file has no e-mail addresses.');
        }

        if ($report['error_rows'] > 0) {
            return $report;
        }

        try {
            DB::transaction(function () use ($people, $options, &$report): void {
                // A closure, not an arrow function: the counts are written back into the report.
                AuditContext::as('user-import', function () use ($people, $options, &$report): void {
                    $this->save($people, (bool) $options['update'], $report);
                });

                if ($options['dry_run']) {
                    throw new DryRunRollback;
                }
            });
        } catch (DryRunRollback) {
            // Preview only: everything above was rolled back.
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $report;
    }

    /** Name as it reads in an address: "john.doe" → "John Doe", "mary-ann_smith" → "Mary Ann Smith". */
    public static function nameFromEmail(string $email): string
    {
        $words = preg_split('/[^\pL\pN]+/u', Str::before($email, '@'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $word) => Str::ucfirst(Str::lower($word)), $words));
    }

    /** "john.doe@…" → "john-doe": letters, digits and dashes only, as usernames must be. */
    public static function usernameFromEmail(string $email): string
    {
        return Str::limit(trim((string) preg_replace('/[^a-z0-9]+/', '-', Str::lower(Str::ascii(Str::before($email, '@')))), '-'), 56, '');
    }

    // ------------------------------------------------------------------ reading

    /** @return list<array<string, mixed>> one record per row that has an e-mail address, with its line number */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $reader = match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => InMemorySharedStrings::reader(),
            'csv', 'txt' => new CsvReader,
            default => throw new InvalidArgumentException('Use an .xlsx or .csv file.'),
        };

        $reader->open($path);
        $records = [];
        $columns = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($cell) => $this->clean($cell), $row->toArray());

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        // A bare list of addresses has no header row: its first line is a person too.
                        if (! $this->looksLikeEmail($cells[$columns['email']] ?? '')) {
                            continue;
                        }
                    }

                    $record = ['line' => $line];
                    foreach ($columns as $key => $index) {
                        $record[$key] = $cells[$index] ?? '';
                    }

                    $records[] = $record;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $records;
    }

    /** @return array<string, int> column key => index */
    private function columns(array $first): array
    {
        $normalised = array_map(fn (string $cell) => Str::lower((string) preg_replace('/[^a-z0-9]/i', '', $cell)), $first);
        $columns = [];

        foreach (self::HEADERS as $column => $spellings) {
            foreach ($spellings as $spelling) {
                if (($index = array_search($spelling, $normalised, true)) !== false) {
                    $columns[$column] = $index;

                    break;
                }
            }
        }

        if (! isset($columns['email'])) {
            // No heading for it: the column whose first line is an address (a list without a header row).
            foreach ($first as $index => $cell) {
                if ($this->looksLikeEmail($cell)) {
                    return ['email' => $index];
                }
            }

            throw new InvalidArgumentException('No e-mail column found. Give the column a heading “Email”, or use a file whose first column holds the addresses.');
        }

        return $columns;
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    private function clean(mixed $value): string
    {
        // A value typed as a number arrives as a float: 752026684.0 → "752026684".
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (string) (int) $value;
        }

        return trim(is_scalar($value) ? (string) $value : '');
    }

    // ------------------------------------------------------------------ checking

    /**
     * Turn the rows into what will be saved, collecting every problem on the way.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function prepare(array $records, array $options, array &$report): array
    {
        $departments = Department::query()->get(['id', 'name', 'code']);
        $roles = Role::query()->where('guard_name', 'web')->get(['id', 'name']);
        $teams = Team::query()->get(['id', 'name']);
        $existing = User::query()->toBase()->get(['id', 'email', 'username']);
        $byEmail = $existing->keyBy(fn ($user) => Str::lower($user->email));
        $usernames = $existing->mapWithKeys(fn ($user) => [Str::lower($user->username) => Str::lower($user->email)])->all();

        $people = [];
        $seen = [];

        foreach ($records as $record) {
            $report['rows']++;
            $line = $record['line'];
            $email = Str::lower($record['email'] ?? '');
            $problems = [];
            $current = $byEmail[$email] ?? null;

            if (! $this->looksLikeEmail($email) || mb_strlen($email) > 255) {
                $problems[] = 'the e-mail address is not valid';
            } elseif (isset($seen[$email])) {
                $problems[] = "the e-mail address is also on row {$seen[$email]}";
            }
            $seen[$email] ??= $line;

            // Someone already in the system: nothing of theirs is made up again.
            $name = $record['name'] ?? '';
            if ($name === '' && ! $current) {
                $name = self::nameFromEmail($email);
                $report['names_from_email']++;
            }
            if (! $current && $name === '') {
                $problems[] = 'no name, and none can be read from the address';
            } elseif (mb_strlen($name) > 255) {
                $problems[] = 'the name is longer than 255 characters';
            }

            $username = Str::lower($record['username'] ?? '');
            if ($username === '' && ! $current) {
                $base = self::usernameFromEmail($email) ?: 'user';
                $username = $base;
                for ($n = 2; isset($usernames[$username]) && $usernames[$username] !== $email; $n++) {
                    $username = "{$base}-{$n}";
                }
                $report['usernames_made']++;
            }
            if ($username !== '') {
                if (! preg_match('/^[\pL\pM\pN_-]+$/u', $username) || mb_strlen($username) > 60) {
                    $problems[] = 'the username may only have letters, numbers, dashes and underscores (60 at most)';
                } elseif (isset($usernames[$username]) && $usernames[$username] !== $email) {
                    $problems[] = "the username “{$username}” is already used by another person";
                }
                $usernames[$username] = $email;
            }

            [$department, $problem] = $this->match(($record['department'] ?? '') ?: (string) $options['department'], $departments, 'department', codes: true, single: true);
            $problems = [...$problems, ...$problem];
            [$roleIds, $problem] = $this->match(($record['roles'] ?? '') ?: (string) $options['roles'], $roles, 'role');
            $problems = [...$problems, ...$problem];
            [$teamIds, $problem] = $this->match(($record['teams'] ?? '') ?: (string) $options['teams'], $teams, 'team');
            $problems = [...$problems, ...$problem];

            $status = Str::lower($record['status'] ?? '');
            $active = match (true) {
                $status === '' || in_array($status, ['active', 'yes', 'y', '1', 'true', 'enabled'], true) => true,
                in_array($status, ['inactive', 'no', 'n', '0', 'false', 'disabled', 'suspended'], true) => false,
                default => null,
            };
            if ($active === null) {
                $problems[] = "status “{$record['status']}” is not Active or Inactive";
            }

            if (mb_strlen($record['phone'] ?? '') > 30) {
                $problems[] = 'the phone number is longer than 30 characters';
            }

            // A password is only ever set on a new account.
            $password = null;
            if (! $current) {
                $given = $record['password'] ?? '';

                if ($options['random_passwords']) {
                    $password = Str::password(32);
                    $report['random_passwords']++;
                } elseif ($given === '') {
                    $problems[] = 'no password — add one, or use random passwords (people then set their own with “Forgot your password?”)';
                } else {
                    $password = $given;
                    $weakness = $this->weakness($given);

                    if ($weakness !== null) {
                        $report['weak_passwords']++;

                        if (! $options['allow_weak_passwords']) {
                            $problems[] = 'the password does not meet the password rule ('.$weakness.')';
                        }
                    }
                }
            }

            if ($problems !== []) {
                $report['error_rows']++;

                if (count($report['errors']) < self::ERRORS_LISTED) {
                    $report['errors'][] = "Row {$line}: ".implode('; ', $problems);
                }

                continue;
            }

            $people[] = [
                'id' => $current?->id, 'email' => $email, 'name' => $name, 'username' => $username, 'password' => $password,
                'department_id' => $department[0] ?? null, 'role_ids' => $roleIds, 'team_ids' => $teamIds,
                'is_active' => $active, 'phone' => $record['phone'] ?? '',
                // Which columns the row filled in: an update changes only those.
                'given' => array_keys(array_filter($record, fn ($value) => $value !== '')),
            ];
        }

        return $people;
    }

    /**
     * Names in a cell → ids of what exists, matched without case.
     *
     * @return array{0: list<int>, 1: list<string>} ids, problems
     */
    private function match(string $cell, $existing, string $what, bool $codes = false, bool $single = false): array
    {
        $names = array_values(array_filter(array_map('trim', $single ? [$cell] : (preg_split('/[;,|]+/', $cell) ?: []))));
        $ids = [];
        $problems = [];

        foreach ($names as $name) {
            $found = $existing->first(fn ($item) => Str::lower($item->name) === Str::lower($name) || ($codes && Str::lower((string) $item->code) === Str::lower($name)));

            if ($found) {
                $ids[] = (int) $found->id;
            } else {
                $problems[] = "there is no {$what} “{$name}”";
            }
        }

        return [array_values(array_unique($ids)), $problems];
    }

    /** Null when the value meets the system's password rule; otherwise what it lacks — never the value itself. */
    private function weakness(string $password): ?string
    {
        $validator = Validator::make(['password' => $password], ['password' => [Password::defaults()]]);

        return $validator->fails()
            ? Str::of(implode(' ', $validator->errors()->get('password')))->replace('The password field ', '')->replace('must ', '')->rtrim('.')->toString()
            : null;
    }

    // ------------------------------------------------------------------ saving

    /** @param list<array<string, mixed>> $people */
    private function save(array $people, bool $update, array &$report): void
    {
        foreach ($people as $person) {
            if ($person['id'] !== null) {
                if (! $update) {
                    $report['skipped']++;

                    continue;
                }

                // Only what the file says: an empty cell changes nothing, and never the password.
                $user = User::query()->findOrFail($person['id']);
                $given = $person['given'];
                $user->fill(array_filter([
                    'name' => in_array('name', $given, true) ? $person['name'] : null,
                    'username' => in_array('username', $given, true) ? $person['username'] : null,
                    'phone' => in_array('phone', $given, true) ? $person['phone'] : null,
                    'department_id' => $person['department_id'],
                ], fn ($value) => $value !== null));

                if (in_array('status', $given, true)) {
                    $user->is_active = $person['is_active'];
                }

                $changed = $user->isDirty();
                $user->save();
                $memberships = fn () => [$user->roles()->pluck('id')->sort()->values()->all(), $user->teams()->pluck('teams.id')->sort()->values()->all()];
                $before = $memberships();
                $user->roles()->syncWithoutDetaching($person['role_ids']);
                $user->teams()->syncWithoutDetaching($person['team_ids']);

                $changed || $before !== $memberships() ? $report['updated']++ : $report['skipped']++;

                continue;
            }

            $user = User::query()->create([
                'name' => $person['name'],
                'username' => $person['username'],
                'email' => $person['email'],
                'phone' => $person['phone'] ?: null,
                'password' => $person['password'],
                'department_id' => $person['department_id'],
                'is_active' => $person['is_active'],
            ]);
            // Created by an administrator from the staff list: the address is taken as theirs.
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->sync($person['role_ids']);
            $user->teams()->sync($person['team_ids']);
            $report['created']++;
        }
    }
}
