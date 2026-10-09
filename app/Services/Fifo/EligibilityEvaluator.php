<?php

namespace App\Services\Fifo;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageQueueEntry;
use App\Models\OutageType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Evaluates every eligibility rule of spec §27.3 for a queue entry. The same code path is
 * used to pick the user and to explain the decision, so "why did X get this ticket" always
 * matches what the engine actually did.
 *
 * Within a processing run the evaluator caches candidate populations and tracks workload
 * and last-assigned times in memory, so a burst of tickets is distributed round-robin
 * without re-querying the database for each ticket.
 */
class EligibilityEvaluator
{
    /** @var array<string, Collection<int, User>> */
    private array $populations = [];

    /** @var array<int, int> */
    private array $workloads = [];

    /** @var array<int, CarbonImmutable> */
    private array $assignedAt = [];

    /** @var array<int, list<int>> team id => [team id, sub-team ids...] */
    private array $teamScopes = [];

    private ?CarbonImmutable $now = null;

    public function __construct(private readonly Settings $settings) {}

    public function beginRun(): void
    {
        $this->populations = [];
        $this->workloads = [];
        $this->assignedAt = [];
        $this->teamScopes = [];
        $this->now = CarbonImmutable::now();
    }

    public function recordAssignment(int $userId): void
    {
        $this->workloads[$userId] = ($this->workloads[$userId] ?? 0) + 1;
        $this->assignedAt[$userId] = $this->now();
    }

    /**
     * Pick the longest-waiting eligible user for the entry.
     *
     * @return array{user: ?User, meta: array<string, mixed>}
     */
    public function decide(OutageQueueEntry $entry): array
    {
        $evaluations = $this->evaluateAll($entry);
        $eligible = $evaluations->filter(fn (array $row) => $row['eligible'])->values();
        $winner = $eligible->first();

        return [
            'user' => $winner['user'] ?? null,
            'meta' => [
                'evaluated_at' => $this->now()->toIso8601String(),
                'queue_type' => $entry->queue_type->value,
                'queued_at' => $entry->queued_at?->format('Y-m-d H:i:s.u'),
                'priority_rank' => $entry->priority_rank,
                'rules' => $this->ruleConfiguration(),
                'candidates_evaluated' => $evaluations->count(),
                'eligible_count' => $eligible->count(),
                'selection_order' => 'Longest since last assignment first (never-assigned first), then earliest availability, then user id.',
                'eligible_ranking' => $eligible->take(10)->map(fn (array $row, int $i) => [
                    'rank' => $i + 1,
                    'user_id' => $row['user']->id,
                    'name' => $row['user']->name,
                    'workload' => $row['workload'],
                    'last_assigned_at' => $row['last_assigned_at']?->toIso8601String(),
                ])->all(),
                'excluded_summary' => $evaluations->reject(fn (array $row) => $row['eligible'])
                    ->flatMap(fn (array $row) => collect($row['checks'])->reject(fn ($c) => $c['passed'])->keys())
                    ->countBy()->all(),
            ],
        ];
    }

    /**
     * Full rule-by-rule evaluation for every candidate, eligible users first in FIFO order.
     *
     * @return Collection<int, array{user: User, eligible: bool, workload: int, last_assigned_at: ?CarbonImmutable, checks: array<string, array{label: string, passed: bool, detail: string}>}>
     */
    public function evaluateAll(OutageQueueEntry $entry, bool $includeInactive = false): Collection
    {
        $users = $this->population($entry, $includeInactive);
        $this->loadWorkloads($users);

        return $users
            ->map(function (User $user) use ($entry): array {
                $checks = $this->checks($entry, $user);

                return [
                    'user' => $user,
                    'eligible' => collect($checks)->every(fn (array $check) => $check['passed']),
                    'workload' => $this->workloads[$user->id] ?? 0,
                    'last_assigned_at' => $this->lastAssignedAt($user),
                    'checks' => $checks,
                ];
            })
            ->sort(function (array $a, array $b): int {
                // Eligible first, then FIFO over users: the one waiting longest wins.
                return [$b['eligible'], $a['last_assigned_at']?->getTimestamp() ?? 0, $a['user']->availability_changed_at?->getTimestamp() ?? 0, $a['user']->id]
                    <=> [$a['eligible'], $b['last_assigned_at']?->getTimestamp() ?? 0, $b['user']->availability_changed_at?->getTimestamp() ?? 0, $b['user']->id];
            })
            ->values();
    }

    /** @return array<string, array{label: string, passed: bool, detail: string}> */
    public function checks(OutageQueueEntry $entry, User $user): array
    {
        $outage = $entry->outage;
        $checks = [];

        $checks['active'] = $this->check('User status', $user->is_active, $user->is_active ? 'Active' : 'Inactive');

        if ($this->settings->bool('fifo.require_availability')) {
            $status = $user->availability_status ?? AvailabilityStatus::Offline;
            $checks['availability'] = $this->check('Availability', $status === AvailabilityStatus::Available, $status->getLabel());
        }

        if ($entry->department_id) {
            $checks['department'] = $this->check(
                'Department',
                (int) $user->department_id === (int) $entry->department_id,
                $user->department?->name ?? 'No department',
            );
        }

        // A step of a custom workflow is worked by the members of its team: no permission of its own.
        if (! $entry->queue_type->isCustom()) {
            $permission = $this->permissionFor($entry);
            $checks['permission'] = $this->check('Permission', $user->hasPermissionTo($permission), $permission);
        } elseif (! $entry->team_id) {
            $checks['team'] = $this->check('Team', false, 'The step has no team');
        }

        // 4G: transmission outages go to the engineers, eNodeB outages to the technicians.
        if ($outage && $entry->queue_type === QueueType::MobilityEngineer && ($handles = OutageType::handlerPermission($outage->fault_domain))) {
            $checks['fault_domain'] = $this->check(
                'Handles '.(OutageType::FAULT_DOMAINS[$outage->fault_domain] ?? $outage->fault_domain).' outages',
                $user->checkPermissionTo($handles),
                Permissions::label($handles),
            );
        }

        $userTeamIds = $user->teams->pluck('id')->all();

        $requiredTeamId = $entry->team_id ?? $entry->queueDefinition?->team_id;
        if ($requiredTeamId) {
            $scope = $this->teamScope((int) $requiredTeamId);
            $checks['team'] = $this->check('Team', array_intersect($scope, $userTeamIds) !== [], 'Requires team #'.$requiredTeamId);
        }

        if ($entry->sub_team_id) {
            $checks['sub_team'] = $this->check('Sub-team', in_array((int) $entry->sub_team_id, $userTeamIds, true), 'Requires sub-team #'.$entry->sub_team_id);
        }

        if ($this->settings->bool('fifo.respect_shift')) {
            $onShift = $user->shiftAssignments->isNotEmpty();
            $checks['shift'] = $this->check('Shift', $onShift, $onShift ? 'On shift until '.$user->shiftAssignments->first()->shift_end->format('H:i') : 'Not within an active shift');
        }

        if ($this->settings->bool('fifo.exclude_on_leave')) {
            $onLeave = $user->leaves->isNotEmpty();
            $checks['leave'] = $this->check('Leave', ! $onLeave, $onLeave ? 'On approved leave' : 'Not on leave');
        }

        if ($this->settings->bool('fifo.skill_based') && $outage) {
            $checks['skill'] = $this->skillCheck($user, $outage->services->pluck('id')->all(), $outage->vertical_id);
        }

        // Off by default: every technician can work every OLT. Switch on to restrict by team OLT coverage.
        if ($outage && $this->settings->bool('fifo.olt_coverage') && in_array($entry->queue_type, [QueueType::Infrastructure, QueueType::Technician, QueueType::SupportTechnician], true)) {
            $checks['coverage'] = $this->coverageCheck($user, $outage->olts->pluck('id')->all());
        }

        // Customer tickets / site visits: people locked to regions only get tickets of those regions.
        if ($outage && $entry->queue_type->isTicketing()) {
            $checks['region'] = $this->regionCheck($user, $outage);
        }

        $limit = $user->max_active_assignments ?? $this->settings->int('fifo.max_active_per_user');
        $workload = $this->workloads[$user->id] ?? 0;
        $bypass = $this->settings->bool('fifo.critical_bypass') && $outage?->priority?->isCritical();
        $checks['workload'] = $this->check(
            'Workload',
            $limit <= 0 || $workload < $limit || $bypass,
            "{$workload} of {$limit} active".($bypass && $workload >= $limit ? ' (critical bypass)' : ''),
        );

        $rejected = $entry->hasRejected($user->id);
        $checks['not_rejected'] = $this->check('Not previously rejected/timed out', ! $rejected, $rejected ? 'Already rejected or timed out on this ticket' : 'OK');

        return $checks;
    }

    public function permissionFor(OutageQueueEntry $entry): string
    {
        return $entry->queueDefinition?->permission() ?? $entry->queue_type->defaultPermission();
    }

    public function ruleConfiguration(): array
    {
        return collect(Settings::DEFAULTS)
            ->keys()
            ->filter(fn (string $key) => str_starts_with($key, 'fifo.'))
            ->mapWithKeys(fn (string $key) => [$key => $this->settings->get($key)])
            ->all();
    }

    /** @return Collection<int, User> */
    private function population(OutageQueueEntry $entry, bool $includeInactive): Collection
    {
        $custom = $entry->queue_type->isCustom();
        $permission = $custom ? 'team:'.(int) $entry->team_id : $this->permissionFor($entry);
        $departmentId = $entry->department_id;
        $key = implode('|', [$permission, $departmentId, (int) $includeInactive]);

        if (isset($this->populations[$key])) {
            return $this->populations[$key];
        }

        $now = $this->now();

        try {
            // Custom workflow step: the members of the step's team (and of its sub-teams).
            $query = $custom
                ? User::query()->whereHas('teams', fn ($team) => $team->whereIn('teams.id', $entry->team_id ? $this->teamScope((int) $entry->team_id) : [0]))
                : User::permission($permission);
        } catch (PermissionDoesNotExist) {
            return $this->populations[$key] = collect();
        }

        $query->with([
            'department:id,name',
            'teams:id,parent_id,department_id,is_active',
            'teams.olts:id',
            'shiftAssignments' => fn ($q) => $q->activeAt($now),
            'leaves' => fn ($q) => $q->approvedAt($now),
            'skills' => fn ($q) => $q->where('is_active', true),
            'regions',
            'roles.permissions:id,name',
            'permissions:id,name',
        ]);

        if (! $includeInactive) {
            $query->where('is_active', true);

            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }
        }

        return $this->populations[$key] = $query->get();
    }

    /** @param Collection<int, User> $users */
    private function loadWorkloads(Collection $users): void
    {
        $missing = $users->pluck('id')->reject(fn (int $id) => array_key_exists($id, $this->workloads))->values()->all();

        if ($missing === []) {
            return;
        }

        $counts = OutageAssignment::query()
            ->selectRaw('user_id, count(*) as aggregate')
            ->whereIn('user_id', $missing)
            ->whereIn('status', AssignmentStatus::activeValues())
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        foreach ($missing as $id) {
            $this->workloads[$id] = (int) ($counts[$id] ?? 0);
        }
    }

    private function lastAssignedAt(User $user): ?CarbonImmutable
    {
        if (isset($this->assignedAt[$user->id])) {
            return $this->assignedAt[$user->id];
        }

        return $user->last_assigned_at ? CarbonImmutable::instance($user->last_assigned_at) : null;
    }

    private function teamScope(int $teamId): array
    {
        return $this->teamScopes[$teamId] ??= array_merge([$teamId], Team::query()->where('parent_id', $teamId)->pluck('id')->all());
    }

    private function skillCheck(User $user, array $serviceIds, ?int $verticalId): array
    {
        if ($serviceIds === [] && ! $verticalId) {
            return $this->check('Skill', true, 'No skill requirement');
        }

        $matched = $user->skills->first(fn ($skill) => ($skill->service_id && in_array($skill->service_id, $serviceIds, true))
            || (! $skill->service_id && $skill->vertical_id && (int) $skill->vertical_id === (int) $verticalId));

        return $this->check('Skill', (bool) $matched, $matched ? 'Qualified: '.($matched->skill ?? 'service/vertical skill') : 'No matching service or vertical skill');
    }

    private function regionCheck(User $user, Outage $outage): array
    {
        $regionId = $outage->relationLoaded('ticket') ? $outage->ticket?->region_id : Ticket::query()->where('outage_id', $outage->id)->value('region_id');
        $locked = ($user->relationLoaded('regions') ? $user->regions : $user->regions()->get())->pluck('id')->all();

        if ($locked === [] || ! $regionId) {
            return $this->check('Region', true, $locked === [] ? 'All regions' : 'Ticket has no region');
        }

        $covers = in_array((int) $regionId, array_map('intval', $locked), true);

        return $this->check('Region', $covers, $covers ? 'Covers the ticket region' : 'Locked to other region(s)');
    }

    private function coverageCheck(User $user, array $outageOltIds): array
    {
        if ($outageOltIds === [] || $user->teams->isEmpty()) {
            return $this->check('OLT coverage', true, 'Not restricted');
        }

        $covers = $user->teams->contains(fn (Team $team) => $team->olts->isEmpty() || $team->olts->pluck('id')->intersect($outageOltIds)->isNotEmpty());

        return $this->check('OLT coverage', $covers, $covers ? 'Team covers the affected OLT' : 'User teams do not cover the affected OLT(s)');
    }

    private function check(string $label, bool $passed, string $detail): array
    {
        return ['label' => $label, 'passed' => $passed, 'detail' => $detail];
    }

    private function now(): CarbonImmutable
    {
        return $this->now ??= CarbonImmutable::now();
    }
}
