<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\Users\UserImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * oms:import-users — accounts from a staff list. An e-mail address is enough: the name and the
 * username are read from it; department, role and team come from the file or from the command.
 */
class UserImportTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->makeTeam('Customer Experience', 'CX');
    }

    private function file(string $content, string $extension = 'csv'): string
    {
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'staff').'.'.$extension, $content);

        return $path;
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_a_bare_list_of_addresses_becomes_accounts_named_from_the_address(): void
    {
        // No header row, nothing but addresses: who they are comes from the address, the rest from the command.
        $path = $this->file("John.Doe@savannafibre.example\nmary-ann.smith@savannafibre.example\n\npeter_okello2@savannafibre.example\n");
        $before = User::query()->count();

        $this->artisan('oms:import-users', ['file' => $path, '--preview' => true, '--role' => 'cx agent', '--department' => 'CX', '--team' => 'customer experience', '--random-passwords' => true])
            ->expectsOutputToContain('PREVIEW ONLY — nothing was saved.')
            ->expectsOutputToContain('3 people in the file: 3 account(s) would be created')
            ->assertSuccessful();
        $this->assertSame($before, User::query()->count());

        $this->artisan('oms:import-users', ['file' => $path, '--role' => 'cx agent', '--department' => 'CX', '--team' => 'customer experience', '--random-passwords' => true])
            ->expectsOutputToContain('3 people in the file: 3 account(s) created')
            ->expectsOutputToContain('3 name(s) and 3 username(s) were read from the e-mail address.')
            ->expectsOutputToContain('“Forgot your password?”')
            ->assertSuccessful();
        @unlink($path);

        $john = $this->user('john.doe@savannafibre.example');
        $this->assertSame(['John Doe', 'john-doe'], [$john->name, $john->username]);
        $this->assertSame(['Mary Ann Smith', 'mary-ann-smith'], [$this->user('mary-ann.smith@savannafibre.example')->name, $this->user('mary-ann.smith@savannafibre.example')->username]);
        $this->assertSame(['Peter Okello2', 'peter-okello2'], [$this->user('peter_okello2@savannafibre.example')->name, $this->user('peter_okello2@savannafibre.example')->username]);

        // Assigned as the command said — matched without case — active, and able to sign in to the panel.
        $this->assertSame(['CX Agent'], $john->roles->pluck('name')->all());
        $this->assertSame(Department::query()->where('code', 'CX')->value('id'), $john->department_id);
        $this->assertSame(['Customer Experience'], $john->teams->pluck('name')->all());
        $this->assertTrue($john->is_active);
        $this->assertNotNull($john->email_verified_at);
        $this->assertTrue($john->can('ticket.create'));
        // A random password nobody knows; FIFO offers them nothing until they set themselves available.
        $this->assertSame(60, strlen($john->password));
        $this->assertFalse(Hash::check('', $john->password));
        $this->assertSame('offline', $john->availability_status->value);
    }

    public function test_the_columns_of_the_file_decide_and_passwords_are_never_shown(): void
    {
        $existing = $this->makeUser('NOC Tier I', 'NOC', attributes: ['email' => 'already.here@savannafibre.example', 'username' => 'already-here', 'name' => 'Already Here']);
        $hash = $existing->password;

        $path = $this->file(
            "Column1,Name,Username,Password,Department,Roles,Team,Status,Phone\n"
            ."grace.akello@savannafibre.example,Grace Akello,grace-akello,Sunrise#Kampala42,Customer Experience,cx agent,Customer Experience,Active,0772000001\n"
            ."tom.lead@savannafibre.example,,,Harvest!Season2026,cx,\"CX Agent; cx team leader\",,inactive,\n"
            ."Already.Here@savannafibre.example,Renamed Person,,Different#Pass99,Customer Experience,CX Agent,Customer Experience,Active,\n"
        );

        Artisan::call('oms:import-users', ['file' => $path]);
        $output = Artisan::output();
        $this->assertStringContainsString('3 people in the file: 2 account(s) created, 0 updated, 1 already in the system and left as they are.', $output);
        $this->assertStringContainsString('delete it, or at least its Password column', $output);
        foreach (['Sunrise#Kampala42', 'Harvest!Season2026', 'Different#Pass99'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        $grace = $this->user('grace.akello@savannafibre.example');
        $this->assertSame(['Grace Akello', 'grace-akello', '0772000001'], [$grace->name, $grace->username, $grace->phone]);
        $this->assertTrue(Hash::check('Sunrise#Kampala42', $grace->password), 'the password of the file, stored as a hash');
        $this->assertNotSame('Sunrise#Kampala42', $grace->getRawOriginal('password'));

        $tom = $this->user('tom.lead@savannafibre.example');
        $this->assertSame(['Tom Lead', 'tom-lead'], [$tom->name, $tom->username], 'blank cells: read from the address');
        $this->assertEqualsCanonicalizing(['CX Agent', 'CX Team Leader'], $tom->roles->pluck('name')->all());
        $this->assertFalse($tom->is_active);
        $this->assertSame([], $tom->teams->pluck('name')->all());

        // Someone who already has an account is left exactly as they are…
        $existing->refresh();
        $this->assertSame(['Already Here', $hash, ['NOC Tier I']], [$existing->name, $existing->password, $existing->roles->pluck('name')->all()]);

        // …unless asked: then what the file says is applied, roles and teams are added, the password stays.
        $this->artisan('oms:import-users', ['file' => $path, '--update' => true])
            ->expectsOutputToContain('0 account(s) created, 1 updated, 2 already in the system')
            ->assertSuccessful();
        @unlink($path);

        $existing->refresh();
        $this->assertSame('Renamed Person', $existing->name);
        $this->assertSame('already-here', $existing->username, 'an empty cell changes nothing');
        $this->assertSame($hash, $existing->password);
        $this->assertFalse(Hash::check('Different#Pass99', $existing->password));
        $this->assertEqualsCanonicalizing(['NOC Tier I', 'CX Agent'], $existing->roles->pluck('name')->all());
        $this->assertSame(Department::query()->where('code', 'CX')->value('id'), $existing->department_id);
    }

    public function test_one_bad_row_and_nothing_is_created(): void
    {
        $taken = $this->makeUser('CX Agent', 'CX', attributes: ['username' => 'sam-otieno']);
        $before = User::query()->count();

        $path = $this->file(
            "Email,Name,Username,Password,Department,Roles,Team,Status\n"
            ."fine.person@savannafibre.example,,,Sunrise#Kampala42,CX,CX Agent,,Active\n"
            ."not-an-address,,,Sunrise#Kampala42,CX,CX Agent,,Active\n"
            ."fine.person@savannafibre.example,,,Sunrise#Kampala42,CX,CX Agent,,Active\n"
            ."role.unknown@savannafibre.example,,,Sunrise#Kampala42,Marketing,Astronaut,Moon Crew,Active\n"
            ."odd.status@savannafibre.example,,,Sunrise#Kampala42,CX,CX Agent,,Maybe\n"
            ."no.password@savannafibre.example,,,,CX,CX Agent,,Active\n"
            ."sam.other@savannafibre.example,,sam-otieno,Sunrise#Kampala42,CX,CX Agent,,Active\n"
        );

        $report = app(UserImporter::class)->import($path);
        @unlink($path);

        $this->assertSame([7, 6, 0], [$report['rows'], $report['error_rows'], $report['created']]);
        $this->assertSame($before, User::query()->count());
        $errors = implode("\n", $report['errors']);
        $this->assertStringContainsString('Row 3: the e-mail address is not valid', $errors);
        $this->assertStringContainsString('Row 4: the e-mail address is also on row 2', $errors);
        $this->assertStringContainsString('Row 5: there is no department “Marketing”; there is no role “Astronaut”; there is no team “Moon Crew”', $errors);
        $this->assertStringContainsString('Row 6: status “Maybe” is not Active or Inactive', $errors);
        $this->assertStringContainsString('Row 7: no password', $errors);
        $this->assertStringContainsString('Row 8: the username “sam-otieno” is already used by another person', $errors);
        $this->assertStringNotContainsString('Sunrise#Kampala42', $errors);
        $this->assertSame($taken->id, User::query()->where('username', 'sam-otieno')->value('id'));

        // A username read from the address never collides: it is numbered instead.
        $path = $this->file("sam.otieno@savannafibre.example\nsam_otieno@savannafibre.example\n");
        $report = app(UserImporter::class)->import($path, ['random_passwords' => true]);
        @unlink($path);
        $this->assertSame(2, $report['created']);
        $this->assertSame('sam-otieno-2', $this->user('sam.otieno@savannafibre.example')->username);
        $this->assertSame('sam-otieno-3', $this->user('sam_otieno@savannafibre.example')->username);
    }

    public function test_passwords_of_the_file_must_meet_the_password_rule_unless_told_otherwise(): void
    {
        // The rule of a live system.
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        $path = $this->file(
            "Email,Password,Roles\n"
            ."weak.one@savannafibre.example,weak@2026,CX Agent\n"
            ."strong.one@savannafibre.example,Sunrise#Kampala42,CX Agent\n"
        );

        Artisan::call('oms:import-users', ['file' => $path]);
        $output = Artisan::output();
        $this->assertStringContainsString('Nothing imported — 1 of 2 rows need fixing', $output);
        $this->assertStringContainsString('Row 2: the password does not meet the password rule (be at least 12 characters', $output);
        $this->assertStringContainsString('--random-passwords', $output);
        $this->assertStringNotContainsString('weak@2026', $output);
        $this->assertFalse(User::query()->where('email', 'strong.one@savannafibre.example')->exists(), 'all or nothing');

        // Accepted as they are, on request — and said so.
        $this->artisan('oms:import-users', ['file' => $path, '--allow-weak-passwords' => true])
            ->expectsOutputToContain('2 account(s) created')
            ->expectsOutputToContain('1 password(s) from the file do not meet the system\'s password rule')
            ->assertSuccessful();
        @unlink($path);
        $this->assertTrue(Hash::check('weak@2026', $this->user('weak.one@savannafibre.example')->password));

        // Or ignored altogether: random passwords, each person sets their own.
        $path = $this->file("Email,Password,Roles\nother.one@savannafibre.example,weak@2026,CX Agent\n");
        $this->artisan('oms:import-users', ['file' => $path, '--random-passwords' => true])->assertSuccessful();
        @unlink($path);
        $this->assertFalse(Hash::check('weak@2026', $this->user('other.one@savannafibre.example')->password));

        $this->artisan('oms:import-users', ['file' => $path.'.missing'])->expectsOutputToContain('File not found')->assertFailed();
        $this->assertSame('Mary Ann Smith', UserImporter::nameFromEmail('MARY-ANN_smith@example.com'));
        $this->assertSame('jose-nino', UserImporter::usernameFromEmail('José.Niño@example.com'));
    }

    public function test_teams_named_in_the_file_must_exist(): void
    {
        $path = $this->file("Email,Team\nnew.person@savannafibre.example,\"Customer Experience, Night Shift\"\n");

        $report = app(UserImporter::class)->import($path, ['random_passwords' => true]);
        $this->assertSame(['Row 2: there is no team “Night Shift”'], $report['errors']);

        Team::query()->create(['name' => 'Night Shift', 'department_id' => Department::query()->where('code', 'CX')->value('id'), 'team_type' => 'in_house', 'is_active' => true]);
        $report = app(UserImporter::class)->import($path, ['random_passwords' => true]);
        @unlink($path);

        $this->assertSame(1, $report['created']);
        $this->assertEqualsCanonicalizing(['Customer Experience', 'Night Shift'], $this->user('new.person@savannafibre.example')->teams->pluck('name')->all());
    }
}
