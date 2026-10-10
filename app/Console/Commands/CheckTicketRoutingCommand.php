<?php

namespace App\Console\Commands;

use App\Enums\QueueType;
use App\Models\DeskRoute;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Services\Fifo\EligibilityEvaluator;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use App\Support\AuditContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * "Do customer tickets reach the right desk and the right people — here, on this server?"
 *
 * Raises a probe ticket for each ticket category through the real workflow, follows it to the desk
 * that approves and the desk that carries out the request, and says who receives it at each one —
 * or the exact error. Everything happens inside a database transaction that is rolled back, with
 * the queue, e-mail, SMS and live updates switched off for this run: no ticket is kept, nobody is
 * notified, no number is used up.
 */
#[Signature('oms:check-ticket-routing
    {--as= : E-mail address of the person raising the probe tickets (default: the first active CX Agent / CX Frontline)}
    {--all : Check every subcategory, not only the first of each category}')]
#[Description('Raise a probe ticket per category, follow it through its desks and report who receives it — nothing is kept or sent')]
class CheckTicketRoutingCommand extends Command
{
    private int $problems = 0;

    /** Tickets that reached their desk and were given to nobody, and the rules that kept people from them. */
    private int $unassigned = 0;

    /** @var array<string, int> */
    private array $blocked = [];

    public function handle(OutageWorkflow $workflow): int
    {
        // Nothing leaves this run: jobs (notifications, live updates), e-mail and the SMS gateway are stand-ins.
        Queue::fake();
        Mail::fake();
        Http::fake();
        config(['broadcasting.default' => 'null']);

        $raiser = $this->raiser();

        if (! $raiser) {
            $this->error('Nobody to raise the probe tickets: no active person who may raise customer tickets (or --as names no active user).');

            return self::FAILURE;
        }

        $this->line("Database: ".DB::connection()->getDriverName()." · raised by {$raiser->name} (".$raiser->getRoleNames()->implode(', ').')');

        $categories = TicketCategory::query()->active()->orderBy('sort_order')
            ->with(['subcategories' => fn ($query) => $query->active()->orderBy('sort_order')])->get();

        if ($categories->isEmpty()) {
            $this->error('There are no active ticket categories. Run: php artisan db:seed --class=TicketingSeeder --force');

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            foreach ($categories as $category) {
                $this->newLine();
                $this->line("<options=bold>{$category->name}</> ({$category->code})");
                $subcategories = $this->option('all') ? $category->subcategories : $category->subcategories->take(1);

                if ($subcategories->isEmpty()) {
                    $this->problem('no active subcategory: tickets cannot be raised in this category');
                }

                foreach ($subcategories as $subcategory) {
                    // A step that fails must not spoil the next one (PostgreSQL aborts the whole transaction otherwise).
                    DB::beginTransaction();

                    try {
                        AuditContext::as('routing-check', fn () => $this->follow($workflow, $subcategory, $raiser));
                    } catch (Throwable $e) {
                        $this->problem('ERROR '.class_basename($e).': '.str($e->getMessage())->squish()->limit(600));
                        $this->line('         at '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()).':'.$e->getLine());
                    } finally {
                        DB::rollBack();
                    }
                }
            }
        } finally {
            DB::rollBack();
            Auth::guard()->forgetUser();
        }

        if ($this->blocked !== []) {
            $this->advice();
        }

        $this->newLine();

        if ($this->problems > 0 || $this->blocked !== []) {
            $this->error(($this->problems + count($this->blocked)).' problem(s) found — see the lines marked ✗ and the advice above. Nothing was kept.');

            return self::FAILURE;
        }

        $this->info('Every category reaches its desks, and somebody receives it at each one. Nothing was kept.');

        return self::SUCCESS;
    }

    /** One ticket from raising to the desk that carries the request out. */
    private function follow(OutageWorkflow $workflow, TicketSubcategory $subcategory, User $raiser): void
    {
        Auth::setUser($raiser);

        $outage = $workflow->createTicket([
            'subcategory_id' => $subcategory->id,
            'account_number' => 'ROUTING-CHECK',
            'customer_name' => 'Routing check',
            'calling_number' => '0700000000',
            'primary_number' => '0700000000',
            'address' => 'Routing check',
            'description' => 'Routing check — not a real ticket.',
            'amount' => 1000,
        ], $raiser);

        $this->arrived("  {$subcategory->name}: raised", $outage, $subcategory->first_desk);

        $approval = $subcategory->approval_desk;

        if ($approval) {
            if ($this->deskOf($outage) !== $approval) {
                $workflow->routeTicket($outage, $this->holder($outage), $approval, 'Routing check: for approval');
                $this->arrived('    sent for approval', $outage, $approval);
            }

            $workflow->approveTicket($outage, $this->holder($outage), 'Routing check: approved');
            $this->arrived('    approved', $outage, $subcategory->fulfilment_desk ?? QueueType::Billing);

            return;
        }

        // No approval in this category: where its first desk may escalate it, and whether anyone is there.
        $targets = DeskRoute::targets($subcategory->first_desk);

        if ($targets === []) {
            $this->line('    this desk has no escalation routes (Customer Care → Desk Routes)');

            return;
        }

        $this->line('    can be escalated to: '.collect(array_keys($targets))->map(function (string $value): string {
            $desk = QueueType::from($value);
            $people = $this->people($desk);

            if ($people === 0) {
                $this->problems++;
            }

            return $desk->getLabel().' ('.($people === 0 ? '<fg=red>✗ nobody holds this desk</>' : "{$people} people").')';
        })->implode(', '));
    }

    /** Report where the ticket is now, against where it should be. */
    private function arrived(string $step, Outage $outage, QueueType $expected): void
    {
        // FIFO normally hands tickets out once the request is saved; here nothing is ever saved, so it is asked to now.
        app(FifoAssignmentEngine::class)->process($expected);

        $outage = Outage::query()->with(['status', 'currentAssignee'])->findOrFail($outage->id);
        $desk = $this->deskOf($outage);
        $people = $this->people($expected);
        $who = $outage->currentAssignee
            ? "given to {$outage->currentAssignee->name}"
            : ($people > 0 ? "waiting in the queue — none of the {$people} people of this desk can be given it right now:" : 'waiting in the queue');

        if ($desk !== $expected || $outage->current_queue !== $expected) {
            $this->problem("{$step} → at ".($desk?->getLabel() ?? 'no desk').", expected {$expected->getLabel()}");

            return;
        }

        if ($people === 0) {
            $this->problem("{$step} → {$expected->getLabel()}, but nobody holds this desk (permission {$expected->defaultPermission()}): give a role with it to the people of that team");

            return;
        }

        $this->line("  <fg=green>✓</> {$step} → {$expected->getLabel()} · {$outage->status?->name} · {$who}");

        if (! $outage->currentAssignee) {
            $this->whyNobody($outage);
        }
    }

    /** The ticket reached its desk but FIFO gave it to nobody: the rule each person of the desk fails. */
    private function whyNobody(Outage $outage): void
    {
        $entry = $outage->openQueueEntries()->with('outage')->first();

        if (! $entry) {
            return;
        }

        foreach (app(EligibilityEvaluator::class)->evaluateAll($entry)->take(4) as $row) {
            $failed = collect($row['checks'])->reject(fn (array $check) => $check['passed'])
                ->map(fn (array $check) => $check['label'].($check['detail'] !== '' ? " ({$check['detail']})" : ''))->implode('; ');

            $this->line("         {$row['user']->name}: ".($failed !== '' ? $failed : 'eligible, but tickets that were already waiting come first'));

            foreach (collect($row['checks'])->reject(fn (array $check) => $check['passed'])->keys() as $rule) {
                $this->blocked[$rule] = ($this->blocked[$rule] ?? 0) + 1;
            }
        }

        $this->unassigned++;
    }

    /** What to do about the rules that kept tickets from being given to anyone. */
    private function advice(): void
    {
        $advice = [
            'shift' => '“Respect shifts” is on and these people have no shift covering now: give them shifts (People → Users → a person → Shifts), or switch “Respect shifts” off under Configuration → FIFO & SLA Settings.',
            'availability' => 'These people are not set to Available: FIFO only gives tickets to people who are (their availability switch, top right), unless “Require availability” is switched off under FIFO & SLA Settings.',
            'leave' => 'These people are on leave today.',
            'workload' => 'These people already hold as many tickets as one person may (FIFO & SLA Settings → Max active per user).',
            'team' => 'The queue is limited to a team these people are not in (Configuration → FIFO Queues, or Workflow Configuration → the step).',
            'department' => 'The queue is limited to a department these people are not in (Configuration → FIFO Queues).',
        ];

        $this->newLine();
        $this->warn("{$this->unassigned} ticket(s) reached the right desk but were given to nobody. A ticket nobody holds can only be moved on by someone who may override FIFO — for everyone else the flow stops at that desk.");

        foreach ($this->blocked as $rule => $times) {
            $this->line('  • '.($advice[$rule] ?? "Rule “{$rule}” kept people from receiving tickets."));
        }
    }

    private function deskOf(Outage $outage): ?QueueType
    {
        return Ticket::query()->where('outage_id', $outage->id)->first()?->desk;
    }

    /** Whoever holds the ticket at its desk; when nobody was available, an administrator steps in for the check. */
    private function holder(Outage $outage): User
    {
        $outage = Outage::query()->findOrFail($outage->id);
        $user = $outage->current_assignee_id ? User::query()->find($outage->current_assignee_id) : null;
        $user ??= User::role('Super Admin')->active()->first()
            ?? throw new \RuntimeException('Nobody holds the ticket and there is no active Super Admin to continue the check with.');

        Auth::setUser($user);

        return $user;
    }

    /** Active people who may work this desk. */
    private function people(QueueType $desk): int
    {
        return User::permission($desk->defaultPermission())->active()->count();
    }

    private function raiser(): ?User
    {
        if (filled($this->option('as'))) {
            return User::query()->active()->where('email', $this->option('as'))->first();
        }

        // Whoever raises tickets day to day; an administrator when none of them is set up yet.
        foreach (['CX Agent', 'CX Frontline', 'CX Tier II', 'Super Admin'] as $role) {
            if (! Role::query()->where('name', $role)->exists()) {
                continue;
            }

            $user = User::role($role)->active()->orderBy('id')->get()->first(fn (User $user) => $user->can('createTicket', Outage::class));

            if ($user) {
                return $user;
            }
        }

        return null;
    }

    private function problem(string $text): void
    {
        $this->problems++;
        $this->line("  <fg=red>✗</> {$text}");
    }
}
