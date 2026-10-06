<?php

namespace App\Services\Topology;

use App\Models\AuditLog;
use App\Models\Enodeb;
use App\Models\TransmissionNode;
use App\Models\Vendor;
use App\Support\AuditContext;
use App\Support\InMemorySharedStrings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;

/**
 * Imports the 4G topology from the NOC workbook (XLSX with both sheets, or one CSV per sheet):
 *
 *  - eNodeB sites:        SAVANNA ID | SAVANNA SITE NAME | Tower CO ID | Latitude | Longitude | Owner | heights…
 *  - transmission nodes:  Type | Scripts | Gateway | CSG NAME | Hostname | Management IP | SITE NAME |
 *                         SAV GO SITE ID | TOWER CO ID | TOWER CO | LABEL STATUS
 *
 * A sheet is recognised by its header row, so sheet names and column order do not matter.
 *
 * The transmission hierarchy comes from the hostname numbering: ssg-1234-… is SSG 1234 on CSG 123,
 * which hangs off ASG 12 under RSG 1. The CSG name and gateway are read from the first row of each ring.
 * SSG rows without a site (not yet deployed) are imported inactive, so they are hidden from
 * selection lists until a site is put on them.
 *
 * Idempotent: sites are matched on their ID and nodes on their code; existing rows are updated,
 * never duplicated or removed, and their active flag is left as the administrator set it.
 */
class MobilityTopologyImporter
{
    private const LEVELS = [TransmissionNode::RSG, TransmissionNode::ASG, TransmissionNode::CSG, TransmissionNode::SSG];

    /**
     * @return array{sites: array{rows: int, created: int, updated: int}, nodes: array{rows: int, created: int, updated: int, linked: int, inactive: int}, errors: list<string>, dry_run: bool}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $sheets = $this->read($path);

        if ($sheets['sites'] === [] && $sheets['nodes'] === [] && $sheets['errors'] === []) {
            throw new InvalidArgumentException('No eNodeB or transmission sheet found. The header row must contain "SAVANNA ID" (sites) or "Hostname" (transmission nodes).');
        }

        $report = [
            'sites' => ['rows' => count($sheets['sites']), 'created' => 0, 'updated' => 0],
            'nodes' => ['rows' => count($sheets['nodes']), 'created' => 0, 'updated' => 0, 'linked' => 0, 'inactive' => 0],
            'errors' => $sheets['errors'],
            'dry_run' => $dryRun,
        ];

        if ($sheets['errors'] !== []) {
            return $report;
        }

        try {
            DB::transaction(function () use ($sheets, $dryRun, &$report): void {
                AuditContext::as('4g-topology-import', function () use ($sheets, &$report): void {
                    // Sites first: SSG rows link to them.
                    $report['sites'] = $this->applySites($sheets['sites']) + $report['sites'];
                    $report['nodes'] = $this->applyNodes($sheets['nodes']) + $report['nodes'];
                });

                if ($dryRun) {
                    throw new DryRunRollback;
                }

                AuditLog::query()->create([
                    'auditable_type' => (new Enodeb)->getMorphClass(),
                    'auditable_id' => 0,
                    'event' => '4g_topology_imported',
                    'new_values' => ['sites' => $report['sites'], 'nodes' => $report['nodes']],
                    'user_id' => Auth::id(),
                    'trigger' => AuditContext::trigger(),
                    'created_at' => now(),
                ]);
            });
        } catch (DryRunRollback) {
            // Preview only: everything above was rolled back.
        }

        return $report;
    }

    /**
     * @return array{sites: list<array<string, mixed>>, nodes: list<array<string, mixed>>, errors: list<string>}
     */
    public function read(string $path): array
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
        $result = ['sites' => [], 'nodes' => [], 'errors' => []];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $kind = null;
                $columns = [];

                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($cell) => $cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : $this->clean((string) $cell), $row->toArray());

                    if ($kind === null) {
                        [$kind, $columns] = $this->header($cells);

                        if ($kind === null) {
                            break; // not one of ours: skip the sheet
                        }

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $record = ['line' => $line, 'sheet' => $sheet->getName()];
                    foreach ($columns as $key => $index) {
                        $record[$key] = $cells[$index] ?? '';
                    }

                    if ($problem = $kind === 'sites' ? $this->siteProblem($record) : $this->nodeProblem($record)) {
                        $result['errors'][] = trim($sheet->getName().' row '.$line).': '.$problem;

                        continue;
                    }

                    $result[$kind][] = $record;
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    // ------------------------------------------------------------------ eNodeB sites

    /** @return array{created: int, updated: int} */
    private function applySites(array $rows): array
    {
        $existing = Enodeb::query()->get()->keyBy('site_id');
        $vendors = Vendor::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [Str::upper($code) => $id]);
        $created = $updated = 0;

        foreach ($rows as $row) {
            $owner = Str::upper($row['tower_company']);
            $attributes = [
                'name' => $row['name'],
                'tower_company' => $owner ?: null,
                'tower_co_site_id' => $this->blankIfZero($row['tower_co_site_id']),
                'vendor_id' => $vendors[$owner] ?? null,
                'latitude' => is_numeric($row['latitude']) ? round((float) $row['latitude'], 6) : null,
                'longitude' => is_numeric($row['longitude']) ? round((float) $row['longitude'], 6) : null,
                'bracket_height' => $row['bracket_height'] ?: null,
                'tower_height' => $row['tower_height'] ?: null,
            ];

            if ($site = $existing->get($row['site_id'])) {
                $site->fill($attributes);

                if ($site->isDirty()) {
                    $site->save();
                    $updated++;
                }
            } else {
                $existing->put($row['site_id'], Enodeb::query()->create(['site_id' => $row['site_id'], 'is_active' => true] + $attributes));
                $created++;
            }
        }

        return compact('created', 'updated');
    }

    private function siteProblem(array &$row): ?string
    {
        if ($row['site_id'] === '') {
            return 'SAVANNA ID is empty';
        }

        if (mb_strlen($row['site_id']) > 30) {
            return "SAVANNA ID '{$row['site_id']}' is too long";
        }

        // A site without a name in the workbook is still a site: it is listed by its ID until named.
        if ($row['name'] === '') {
            $row['name'] = 'Site '.$row['site_id'];
        }

        return null;
    }

    // ------------------------------------------------------------------ transmission nodes

    /** @return array{created: int, updated: int, linked: int, inactive: int} */
    private function applyNodes(array $rows): array
    {
        $nodes = TransmissionNode::query()->get()->keyBy('code');
        $sites = Enodeb::query()->pluck('id', 'site_id');
        $createdCodes = [];
        $stats = ['created' => 0, 'updated' => 0, 'linked' => 0, 'inactive' => 0];

        $upsert = function (string $type, string $digits, array $attributes, ?bool $active = null) use (&$nodes, &$createdCodes, &$stats, &$upsert): TransmissionNode {
            $code = $type.'-'.$digits;
            $attributes = array_filter($attributes, fn ($value) => $value !== null);

            // Parents are implied by the numbering: SSG 1234 → CSG 123 → ASG 12 → RSG 1.
            $level = array_search($type, self::LEVELS, true);
            $parent = $level > 0 && strlen($digits) > 1
                ? $upsert(self::LEVELS[$level - 1], substr($digits, 0, -1), [])
                : null;

            if ($node = $nodes->get($code)) {
                $node->fill($attributes + ['parent_id' => $parent?->id ?? $node->parent_id]);

                if ($node->isDirty()) {
                    $node->save();
                    $stats['updated']++;
                }

                return $node;
            }

            $node = TransmissionNode::query()->create(['type' => $type, 'code' => $code, 'parent_id' => $parent?->id, 'is_active' => $active ?? true]
                + $attributes + ['name' => $code]);
            $nodes->put($code, $node);
            $createdCodes[$code] = true;
            $stats['created']++;

            return $node;
        };

        foreach ($rows as $row) {
            $siteId = $sites[$row['site_ref']] ?? null;
            $note = $this->safeNote($row['status_note']);
            $inService = $row['site_name'] !== '' && ! Str::startsWith(Str::lower($note ?? ''), 'unavailable');

            $node = $upsert($row['type'], $row['digits'], [
                'name' => $row['site_name'] !== '' ? $row['site_name'] : null,
                'hostname' => $row['hostname'] ?: null,
                'management_ip' => $row['management_ip'] ?: null,
                'equipment' => $row['equipment'] ?: null,
                'site_name' => $row['site_name'] ?: null,
                'enodeb_id' => $siteId,
                'tower_company' => $row['tower_company'] ? Str::upper($row['tower_company']) : null,
                'tower_co_site_id' => $this->blankIfZero($row['tower_co_site_id']),
                'status_note' => $note,
            ], $inService);

            $stats['linked'] += $siteId ? 1 : 0;

            // The ring's name and gateway sit on its first SSG row.
            if ($row['type'] === TransmissionNode::SSG && strlen($row['digits']) > 1 && ($row['ring_name'] !== '' || $row['gateway_ip'] !== '')) {
                $named = $row['ring_name'] !== '' && Str::lower($row['ring_name']) !== 'unavailable';
                $ring = $nodes->get(TransmissionNode::CSG.'-'.substr($row['digits'], 0, -1));
                $ring?->fill(array_filter(['name' => $named ? $row['ring_name'] : null, 'gateway_ip' => $row['gateway_ip'] ?: null]));

                if ($ring?->isDirty()) {
                    $ring->save();
                }
            }
        }

        // A ring / aggregation node created by this import is in service only if something below it is.
        foreach ([TransmissionNode::CSG, TransmissionNode::ASG, TransmissionNode::RSG] as $type) {
            foreach ($nodes->where('type', $type) as $node) {
                if (isset($createdCodes[$node->code]) && ! $nodes->contains(fn (TransmissionNode $child) => $child->parent_id === $node->id && $child->is_active)) {
                    $node->forceFill(['is_active' => false])->save();
                }
            }
        }

        $stats['inactive'] = $nodes->filter(fn (TransmissionNode $node) => isset($createdCodes[$node->code]) && ! $node->is_active)->count();

        return $stats;
    }

    private function nodeProblem(array &$row): ?string
    {
        if (! preg_match('/^(rsg|asg|csg|ssg)[-_ ]?(\d{1,6})\b/i', $row['hostname'], $match)) {
            return "Hostname '{$row['hostname']}' is not like ssg-1234-…";
        }

        $type = Str::upper($row['type'] !== '' ? $row['type'] : $match[1]);

        if (! in_array($type, self::LEVELS, true)) {
            return "Type '{$row['type']}' must be RSG, ASG, CSG or SSG";
        }

        $row['type'] = $type;
        $row['digits'] = $match[2];
        $row['site_ref'] = ctype_digit($row['site_ref']) ? $row['site_ref'] : '';

        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{0: ?string, 1: array<string, int>} sheet kind (sites | nodes) and column key => index
     */
    private function header(array $cells): array
    {
        $normalised = array_map(fn (string $cell) => Str::upper(preg_replace('/[^a-z0-9]/i', '', $cell)), $cells);
        $find = function (string ...$needles) use ($normalised): ?int {
            foreach ($needles as $needle) {
                foreach ($normalised as $index => $cell) {
                    if ($cell !== '' && str_contains($cell, $needle)) {
                        return $index;
                    }
                }
            }

            return null;
        };

        if (($hostname = $find('HOSTNAME')) !== null) {
            return ['nodes', array_filter([
                'type' => array_search('TYPE', $normalised, true) === false ? null : array_search('TYPE', $normalised, true),
                'equipment' => $find('SCRIPT'),
                'gateway_ip' => $find('GATEWAY'),
                'ring_name' => $find('CSGNAME'),
                'hostname' => $hostname,
                'management_ip' => $find('MANAGEMENTIP'),
                'site_name' => array_search('SITENAME', $normalised, true) === false ? null : array_search('SITENAME', $normalised, true),
                'site_ref' => $find('SITEID'),
                'tower_co_site_id' => $find('TOWERCOID'),
                'tower_company' => array_search('TOWERCO', $normalised, true) === false ? null : array_search('TOWERCO', $normalised, true),
                'status_note' => $find('LABELSTATUS', 'STATUS'),
            ], fn ($index) => $index !== null) + array_fill_keys([
                'type', 'equipment', 'gateway_ip', 'ring_name', 'management_ip', 'site_name', 'site_ref', 'tower_co_site_id', 'tower_company', 'status_note',
            ], -1)];
        }

        if (($siteId = $find('SAVANNAID')) !== null) {
            return ['sites', array_filter([
                'site_id' => $siteId,
                'name' => $find('SITENAME'),
                'tower_co_site_id' => $find('TOWERCOID'),
                'latitude' => $find('LATITUDE'),
                'longitude' => $find('LONGITUDE'),
                'tower_company' => $find('OWNER', 'TOWERCO'),
                'bracket_height' => $find('PROPOSEDHEIGHT', 'BRACKET'),
                'tower_height' => $find('TOWERHEIGHT'),
            ], fn ($index) => $index !== null) + array_fill_keys([
                'name', 'tower_co_site_id', 'latitude', 'longitude', 'tower_company', 'bracket_height', 'tower_height',
            ], -1)];
        }

        return [null, []];
    }

    /** Notes are shown on screen: anything that looks like a device login is dropped. */
    private function safeNote(string $note): ?string
    {
        if ($note === '' || preg_match('/\b(psw|pwd|pass(word)?|login)\b/i', $note)) {
            return null;
        }

        return Str::limit($note, 250, '');
    }

    private function blankIfZero(string $value): ?string
    {
        return in_array($value, ['', '0', 'N/A', 'n/a'], true) ? null : $value;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
