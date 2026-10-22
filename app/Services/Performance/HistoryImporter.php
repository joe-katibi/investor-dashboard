<?php

namespace App\Services\Performance;

use App\Enums\DataSource;
use App\Enums\EngagementType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Enums\TeamType;
use App\Enums\TechnicianCompany;
use App\Enums\TechnicianRole;
use App\Models\Department;
use App\Models\MajorOutageType;
use App\Models\Olt;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Team;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VisitType;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Loads the real history into the system, as closed (or still open) outages and site visits:
 *
 *   incidents()   "INCIDENTS JAN TO SEPT 2026.xlsx" — one network outage per incident. Restoration time =
 *                 fault clearance − fault occurrence; the SLA status is the one recorded in the log (4-hour
 *                 rule when the log has none).
 *   tickets()     the hidden "Ticket Tracker" tab of "SFL Ticketing System with Dashboard.xlsx" — one site
 *                 visit per ticket, with the tracker's own business-hours SLA clock and SLA status. Duplicates
 *                 are left out; tickets whose times do not add up ("CHECK TIMES") come in unmeasured.
 *   technicians() the technician lists — accounts with a random password, role, team and in-house / outsourced.
 *
 * Nothing here is altered to look better: every figure is the spreadsheet's. Re-running replaces the rows of
 * the same source.
 */
class HistoryImporter
{
    /** The internal restoration SLA of the incident log (hours), used only when a row records no SLA status. */
    public const INCIDENT_SLA_HOURS = 4;

    private const CHUNK = 500;

    // ------------------------------------------------------------------ incidents

    /** @return array<string, int> */
    public function incidents(string $file): array
    {
        $rows = [];
        $reader = new Reader;
        $reader->open($file);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $c = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                $number = trim($this->text($c[2] ?? ''));

                // Row 2 is the heading; a row whose "incident number" is a date is a stray line.
                if ($i < 3 || $number === '' || ($c[2] ?? null) instanceof DateTimeInterface || ! ($c[1] ?? null)) {
                    continue;
                }

                // From June the log has one more column (the affected area) before "No. of clients".
                $o = ($c[10] ?? null) instanceof DateTimeInterface && (($c[11] ?? null) instanceof DateTimeInterface || ! (($c[9] ?? null) instanceof DateTimeInterface)) ? 1 : 0;
                $rows[] = [
                    'number' => $number,
                    'date' => $this->sheetDate($c[1]),
                    'area' => $o ? trim($this->text($c[3])) : null,
                    'clients' => $c[3 + $o] ?? null,
                    'olt' => trim($this->text($c[4 + $o] ?? '')),
                    'ports' => trim($this->text($c[5 + $o] ?? '')),
                    'fdt' => trim($this->text($c[6 + $o] ?? '')),
                    'incident' => trim(preg_replace('/\s+/', ' ', $this->text($c[7 + $o] ?? ''))),
                    'sub_type' => trim(preg_replace('/\s+/', ' ', $this->text($c[8 + $o] ?? ''))),
                    'occurred' => $this->sheetDate($c[9 + $o] ?? null),
                    'cleared' => $this->sheetDate($c[10 + $o] ?? null),
                    'rfo' => trim($this->text($c[12 + $o] ?? '')),
                    'resolution' => trim($this->text($c[13 + $o] ?? '')),
                    'sla' => strtoupper(trim($this->text($c[14 + $o] ?? ''))),
                ];
            }
        }
        $reader->close();

        DB::table('outages')->where('data_source', DataSource::IncidentLog->value)->delete();

        $types = OutageType::query()->pluck('id', 'code');
        $majors = OutageType::query()->pluck('major_outage_type_id', 'code');
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');
        $resolved = OutageStatus::forCode(StatusCode::Resolved)->id;
        $numbers = [];
        $insert = [];
        $counts = ['rows' => count($rows), 'imported' => 0, 'open' => 0, 'no_times' => 0];

        foreach ($rows as $r) {
            $start = $r['occurred'] ?? $r['date'];
            if (! $start) {
                $counts['no_times']++;

                continue;
            }

            $cleared = $r['cleared'] && $r['cleared']->gte($start) ? $r['cleared'] : null;
            $hours = $cleared ? round($start->diffInMinutes($cleared) / 60, 2) : null;
            $state = match (true) {
                $hours === null => SlaState::NotApplicable,
                str_contains($r['sla'], 'WITHIN') => SlaState::Met,
                str_contains($r['sla'], 'BREACH') => SlaState::Missed,
                default => $hours <= self::INCIDENT_SLA_HOURS ? SlaState::Met : SlaState::Missed,
            };
            $code = $this->incidentType($r['incident'].' '.$r['sub_type']);
            // Past incidents without a (valid) clearance time are closed in the system — they are not live work —
            // but have no restoration time: the figures count them as neither restored nor within SLA.
            $counts['open'] += $cleared ? 0 : 1;

            $insert[] = [
                'ticket_number' => $this->uniqueNumber($r['number'], $numbers),
                'title' => Str::limit($r['sub_type'] ?: ($r['incident'] ?: 'Incident'), 250, ''),
                'description' => Str::limit(collect([$r['rfo'] ? 'RFO: '.$r['rfo'] : null, $r['resolution'] ? 'Resolution: '.$r['resolution'] : null])->filter()->implode("\n"), 4000, '') ?: null,
                'location' => Str::limit(collect([$r['olt'], $r['fdt'], $r['ports'], $r['area']])->filter(fn ($v) => $v && strtoupper($v) !== 'N/A')->implode(' · '), 250, '') ?: null,
                'customers_affected' => $this->clients($r['clients']),
                'outage_type_id' => $types[$code],
                'major_outage_type_id' => $majors[$code],
                'vertical_id' => $ftth,
                'status_id' => $resolved,
                'is_closed' => true,
                'start_time' => $start,
                'detected_at' => $start,
                'end_time' => $cleared,
                'restored_at' => $cleared,
                'resolved_at' => $cleared,
                'sla_due_at' => $start->copy()->addHours(self::INCIDENT_SLA_HOURS),
                'sla_state' => $state->value,
                'elapsed_hours' => $hours,
                'final_reason' => $r['resolution'] ? Str::limit($r['resolution'], 1000, '') : null,
                'data_source' => DataSource::IncidentLog->value,
                'created_at' => $start,
                'updated_at' => $cleared ?? $start,
            ];
        }

        foreach (array_chunk($insert, self::CHUNK) as $chunk) {
            DB::table('outages')->insert($chunk);
        }
        $counts['imported'] = count($insert);

        return $counts;
    }

    // ------------------------------------------------------------------ tickets

    /** @return array<string, int> */
    public function tickets(string $file): array
    {
        // The shared-strings table of this workbook alone is several hundred thousand strings.
        ini_set('memory_limit', '2G');
        $file = str_replace('\\', '/', $file);
        $sheet = $this->sheetPath($file, 'Ticket Tracker');
        $shared = $this->sharedStrings($file);

        $x = new XMLReader;
        if (! $x->open("zip://{$file}#{$sheet}")) {
            throw new RuntimeException("Cannot open the Ticket Tracker tab of {$file}.");
        }

        $rows = [];
        while ($x->read()) {
            if ($x->nodeType !== XMLReader::ELEMENT || $x->name !== 'row') {
                continue;
            }
            $row = new SimpleXMLElement($x->readOuterXml());
            $x->next();
            $v = [];
            foreach ($row->c as $c) {
                $value = isset($c->v) ? (string) $c->v : (isset($c->is) ? (string) $c->is->t : null);
                if ((string) $c['t'] === 's' && $value !== null) {
                    $value = $shared[(int) $value] ?? $value;
                }
                $v[preg_replace('/\d/', '', (string) $c['r'])] = $value;
            }

            $number = trim((string) ($v['A'] ?? ''));
            if ($number !== '' && str_contains($number, '-') && is_numeric($v['J'] ?? null)) {
                // Only the columns used (the tracker has 40-odd).
                $rows[] = array_intersect_key($v, array_flip(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'V', 'W', 'X']));
            }
        }
        $x->close();

        DB::table('outages')->where('data_source', DataSource::TicketTracker->value)->delete();

        $counts = ['rows' => count($rows), 'imported' => 0, 'duplicates' => 0, 'unmeasured' => 0, 'open' => 0];
        $numbers = [];
        $outages = [];
        $tickets = [];

        foreach ($rows as $v) {
            $status = trim((string) ($v['X'] ?? ''));
            if ($status === 'Duplicate' || trim((string) ($v['I'] ?? '')) === 'Duplicate') {
                $counts['duplicates']++;

                continue;
            }

            $outage = $this->visitRow($v, $status, $numbers);
            $counts['open'] += $outage['outage']['is_closed'] ? 0 : 1;
            $counts['unmeasured'] += $outage['outage']['elapsed_hours'] === null ? 1 : 0;
            $outages[] = $outage['outage'];
            $tickets[$outage['outage']['ticket_number']] = $outage['ticket'];
        }

        $this->insertVisits($outages, $tickets);
        $counts['imported'] = count($outages);

        return $counts;
    }

    /**
     * One tracker row → the outage and ticket columns of a site visit.
     *
     * @param  array<string, ?string>  $v
     * @param  array<string, true>  $numbers
     * @return array{outage: array<string, mixed>, ticket: array<string, mixed>}
     */
    private function visitRow(array $v, string $status, array &$numbers): array
    {
        $lookup = $this->lookups();
        $reported = $this->serialDate($v['J']);
        $assigned = $this->serialDate($v['K'] ?? null);
        $restored = $this->serialDate($v['L'] ?? null);
        $closed = strcasecmp(trim((string) ($v['I'] ?? '')), 'Closed') === 0;
        $issue = trim((string) ($v['E'] ?? ''));
        $department = trim((string) ($v['G'] ?? ''));
        $measured = in_array($status, ['Met', 'Breached', 'Breached - still open', 'At risk'], true) && is_numeric($v['V'] ?? null) && (float) $v['V'] >= 0;

        $prefix = match (true) {
            str_contains(strtolower($department.' '.($v['C'] ?? '')), 'shifting') && strcasecmp($issue, 'Inhouse Shifting') !== 0 => 'SHI',
            str_contains(strtolower($department), 'installation') => 'INS',
            default => 'SUP',
        };
        $subcategory = $lookup['subcategories'][strtolower($issue)] ?? null;
        $account = Str::limit(trim((string) ($v['B'] ?? '')), 60, '');
        $start = $reported;
        $end = $closed ? ($restored && $restored->gte($start) ? $restored : $start) : null;

        return [
            'outage' => [
                'ticket_number' => $this->uniqueNumber(trim((string) $v['A']), $numbers),
                'title' => Str::limit(($issue ?: 'Site visit').($account ? " — {$account}" : ''), 250, ''),
                'workflow' => OutageType::WORKFLOW_SITE_VISIT,
                'outage_type_id' => $lookup['visit_type'],
                'major_outage_type_id' => $lookup['customer'],
                'vertical_id' => $lookup['ftth'],
                'status_id' => $closed ? $lookup['closed'] : $lookup['open'],
                'is_closed' => $closed,
                'customers_affected' => 1,
                'location' => trim((string) ($v['D'] ?? '')) ?: null,
                'team_id' => $this->crew(trim((string) ($v['H'] ?? ''))),
                'start_time' => $start,
                'detected_at' => $start,
                'dispatched_at' => $assigned,
                'end_time' => $end,
                'resolved_at' => $end,
                'sla_due_at' => is_numeric($v['W'] ?? null) ? $start->copy()->addHours((float) $v['W']) : null,
                'sla_state' => match (true) {
                    ! $measured => SlaState::NotApplicable->value,
                    $status === 'Met' => SlaState::Met->value,
                    $closed => SlaState::Missed->value,
                    default => SlaState::Breached->value,
                },
                'elapsed_hours' => $measured ? round((float) $v['V'], 2) : null,
                'data_source' => DataSource::TicketTracker->value,
                'created_at' => $start,
                'updated_at' => $end ?? $start,
            ],
            'ticket' => [
                'account_number' => $account ?: null,
                'olt_id' => $this->olt((string) ($v['D'] ?? '')),
                'category_id' => $subcategory?->ticket_category_id,
                'subcategory_id' => $subcategory?->id,
                'visit_type_id' => $lookup['visit_types'][$prefix] ?? null,
                'ticket_type' => match (strtolower(trim((string) ($v['F'] ?? '')))) {
                    'proactive' => 'proactive',
                    'reactive repeat' => 'reactive_repeat',
                    default => 'reactive',
                },
                'first_assigned_at' => $assigned,
                'assigned_at' => $assigned,
                'completed_at' => $end,
                'confirmed_at' => $end,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $outages
     * @param  array<string, array<string, mixed>>  $tickets  by ticket number
     */
    public function insertVisits(array $outages, array $tickets): void
    {
        foreach (array_chunk($outages, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $tickets): void {
                DB::table('outages')->insert($chunk);
                $ids = DB::table('outages')->whereIn('ticket_number', array_column($chunk, 'ticket_number'))->pluck('id', 'ticket_number');
                $now = now();

                DB::table('tickets')->insert(array_map(fn (array $o) => $tickets[$o['ticket_number']] + [
                    'outage_id' => $ids[$o['ticket_number']],
                    'created_at' => $o['created_at'] ?? $now,
                    'updated_at' => $o['updated_at'] ?? $now,
                ], $chunk));
            });
        }
    }

    // ------------------------------------------------------------------ technicians

    /**
     * Accounts for the people of the technician lists (later files win for the same e-mail). Each gets a
     * long random password — they set their own with "Forgot your password?"; the lists' passwords are not used.
     *
     * @return array<string, int>
     */
    public function technicians(string ...$files): array
    {
        $people = [];
        foreach ($files as $file) {
            $reader = new Reader;
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                $head = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $c = array_map(fn ($cell) => trim((string) $cell->getValue()), $row->getCells());
                    if ($head === null) {
                        $head = array_map(fn ($h) => strtolower($h), $c);

                        continue;
                    }
                    $get = fn (string $name) => $c[array_search($name, $head, true)] ?? '';
                    $email = strtolower($get('mail'));
                    if ($email === '' || $get('names') === '') {
                        continue;
                    }
                    $people[$email] = [
                        'name' => Str::title(strtolower(preg_replace('/\s+/', ' ', $get('names')))),
                        'username' => strtolower($get('username')) ?: Str::before($email, '@'),
                        'role' => TechnicianRole::fromList($get('role')),
                        'company' => TechnicianCompany::fromList($get('team')),
                        'engagement' => EngagementType::fromList($get('type')),
                        'phone' => preg_replace('/\D/', '', $get('contact')) ?: null,
                    ];
                }
            }
            $reader->close();
        }

        $department = Department::query()->firstOrCreate(['code' => 'SD'], ['name' => 'Service Delivery', 'is_active' => true]);
        $teams = [];
        foreach (TechnicianCompany::cases() as $company) {
            $teams[$company->value] = Team::query()->firstOrCreate(['name' => $company->getLabel()], [
                'department_id' => $department->id,
                'team_type' => $company === TechnicianCompany::Inhouse ? TeamType::InHouse : TeamType::Outsource,
                'description' => 'Technician team from the technician list ('.$company->value.').',
                'is_active' => true,
            ])->id;
        }

        $counts = ['people' => count($people), 'created' => 0, 'updated' => 0, 'unknown_role' => 0, 'unknown_team' => 0];
        foreach ($people as $email => $p) {
            $user = User::query()->where('email', $email)->first();
            $username = $p['username'];
            if (User::query()->where('username', $username)->where('email', '!=', $email)->exists()) {
                $username .= '-'.Str::lower(Str::random(4));
            }

            $fields = ['name' => $p['name'], 'username' => $username, 'phone' => $p['phone'], 'department_id' => $department->id, 'is_active' => true];
            if ($user) {
                $user->fill($fields)->save();
                $counts['updated']++;
            } else {
                $user = User::query()->create($fields + ['email' => $email, 'password' => Str::password(32)]);
                $counts['created']++;
            }

            $engagement = $p['engagement'] ?? $p['company']?->engagement();
            $user->forceFill(['engagement_type' => $engagement?->value])->save();

            $p['role'] ? $user->syncRoles([$p['role']->value]) : $counts['unknown_role']++;
            $p['company'] ? $user->teams()->syncWithoutDetaching([$teams[$p['company']->value]]) : $counts['unknown_team']++;
        }

        return $counts;
    }

    // ------------------------------------------------------------------ helpers

    /** A text cell that the spreadsheet may have turned into a date ("7/1-4" ports …). */
    private function text(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('j/n') : (string) $value;
    }

    private function incidentType(string $text): string
    {
        $t = strtolower($text);

        return match (true) {
            str_contains($t, 'planned') => 'planned',
            (bool) preg_match('/upstream|seacom|wiocc|airtel|core network/', $t) => 'core',
            (bool) preg_match('/nms|raduis|radius|database|savbill|grafana|smart olt|no ips/', $t) => 'system',
            default => 'regular',
        };
    }

    private function clients(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return preg_match('/(\d+)\s*(clients?|customers?)|affected:\s*(\d+)/i', (string) $value, $m) ? (int) ($m[1] ?: $m[3]) : 0;
    }

    /** @param array<string, true> $taken */
    private function uniqueNumber(string $number, array &$taken): string
    {
        $base = Str::limit(preg_replace('/\s+/', '', $number), 26, '');
        $candidate = $base;
        for ($n = 2; isset($taken[strtolower($candidate)]); $n++) {
            $candidate = "{$base}-{$n}";
        }
        $taken[strtolower($candidate)] = true;

        return $candidate;
    }

    /** A date cell of the incident log (a real date, or text such as "26-04-2026"), read as local time. */
    private function sheetDate(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), config('app.timezone'));
        }

        $value = trim((string) $value);

        return preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $value) ? Carbon::createFromFormat('d-m-Y', $value, config('app.timezone'))->startOfDay() : null;
    }

    /** An Excel serial number (days since 1899-12-30), read as local time. */
    private function serialDate(mixed $value): ?Carbon
    {
        if (! is_numeric($value) || (float) $value < 30000 || (float) $value > 80000) {
            return null;
        }

        return Carbon::create(1899, 12, 30, 0, 0, 0, config('app.timezone'))->addSeconds((int) round((float) $value * 86400));
    }

    private ?array $lookups = null;

    /** @return array<string, mixed> */
    private function lookups(): array
    {
        return $this->lookups ??= [
            'visit_type' => OutageType::query()->where('code', OutageType::CODE_SITE_VISIT)->value('id'),
            'customer' => MajorOutageType::query()->where('code', MajorOutageType::CUSTOMER)->value('id'),
            'ftth' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
            'closed' => OutageStatus::forCode(StatusCode::VisitClosed)->id,
            'open' => OutageStatus::forCode(StatusCode::VisitOpen)->id,
            'visit_types' => VisitType::query()->pluck('id', 'code_prefix')->all(),
            'subcategories' => TicketSubcategory::query()->get()->keyBy(fn ($s) => strtolower($s->name))->all(),
            'olts' => Olt::query()->get()->flatMap(fn (Olt $olt) => array_fill_keys($olt->matchKeys(), $olt->id))->all(),
        ];
    }

    private function olt(string $name): ?int
    {
        return trim($name) === '' ? null : ($this->lookups()['olts'][Olt::matchKey($name)] ?? null);
    }

    /** @var array<string, int> */
    private array $crews = [];

    /** The field crew of a ticket ("Team Gerald", "TEAM IVAN" …) as a team under "Field crews". */
    private function crew(string $name): ?int
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return null;
        }

        $label = Str::title(strtolower($name));
        $label = str_starts_with($label, 'Team') ? $label : "Team {$label}";

        if (! isset($this->crews[$label])) {
            $parent = Team::query()->firstOrCreate(['name' => 'Field crews'], [
                'department_id' => Department::query()->where('code', 'DISPATCH')->value('id'),
                'team_type' => TeamType::InHouse,
                'description' => 'Site visit crews named in the ticket tracker.',
                'is_active' => true,
            ]);
            $this->crews[$label] = Team::query()->firstOrCreate(['name' => $label], ['parent_id' => $parent->id, 'is_active' => true])->id;
        }

        return $this->crews[$label];
    }

    /** @return list<string> */
    private function sharedStrings(string $file): array
    {
        $shared = [];
        $x = new XMLReader;
        $x->open("zip://{$file}#xl/sharedStrings.xml");
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->name === 'si') {
                $node = new SimpleXMLElement($x->readOuterXml());
                $node->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $shared[] = implode('', array_map('strval', $node->xpath('.//m:t')));
            }
        }
        $x->close();

        return $shared;
    }

    /** The zip path of a tab, found by name through the workbook and its relationships. */
    private function sheetPath(string $file, string $name): string
    {
        $workbook = new SimpleXMLElement((string) file_get_contents("zip://{$file}#xl/workbook.xml"));
        $rels = new SimpleXMLElement((string) file_get_contents("zip://{$file}#xl/_rels/workbook.xml.rels"));
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        foreach ($workbook->xpath('//m:sheet') as $sheet) {
            if ((string) $sheet['name'] === $name) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $id) {
                        return 'xl/'.ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
                    }
                }
            }
        }

        throw new RuntimeException("The workbook has no “{$name}” tab.");
    }
}
