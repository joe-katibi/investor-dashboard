<?php

namespace Database\Seeders;

use App\Enums\AvailabilityStatus;
use App\Enums\TeamType;
use App\Models\Department;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\Port;
use App\Models\Service;
use App\Models\ShiftAssignment;
use App\Models\Slot;
use App\Models\Team;
use App\Models\User;
use App\Models\UserSkill;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/**
 * Local / staging demo data: teams, users for every role, shifts and skills. No outage tickets
 * and no topology — tickets start empty and the real OLT → FDT → Slot → Port mapping is loaded
 * with `php artisan oms:import-topology` (or TopologySeeder).
 */
class DemoDataSeeder extends Seeder
{
    /** How many days ahead the demo staff are on shift. */
    public const SHIFT_DAYS = 90;

    public const PASSWORD ='Password123!';

    public function run(): void
    {
        $dept = Department::query()->pluck('id', 'code');

        // ---------------------------------------------------------------- teams
        $noc = $this->team('NOC Operations', $dept['NOC'], TeamType::InHouse);
        $nocShiftA = $this->team('NOC Shift A', $dept['NOC'], TeamType::InHouse, $noc);
        $cx = $this->team('Customer Experience', $dept['CX'], TeamType::InHouse);
        $cxTier2 = $this->team('CX Tier II', $dept['CX'], TeamType::InHouse, $cx);
        $dispatch = $this->team('Field Dispatch', $dept['DISPATCH'], TeamType::InHouse);
        $support = $this->team('Support Technicians', $dept['DISPATCH'], TeamType::InHouse, $dispatch);
        $it = $this->team('IT Service Desk', $dept['IT'], TeamType::InHouse);
        $infra = $this->team('Infrastructure Field Ops', $dept['INFRA'], TeamType::InHouse);
        $north = $this->team('North Crew', $dept['INFRA'], TeamType::InHouse, $infra);
        $south = $this->team('South Crew', $dept['INFRA'], TeamType::InHouse, $infra);
        $vendor = $this->team('FibreTech Contractors', $dept['INFRA'], TeamType::Outsource);
        $vendorCrew = $this->team('FibreTech Crew 1', $dept['INFRA'], TeamType::Outsource, $vendor);

        // ---------------------------------------------------------------- users
        $this->user('System Administrator', 'admin', 'MGMT', 'Super Admin', []);
        // $this->user('Executive Viewer', 'exec', 'MGMT', 'Executive', []);

        // $nocManager = $this->user('Nora NOC Manager', 'noc.manager', 'NOC', 'NOC Manager', [$noc]);
        // $nocLead = $this->user('Liam NOC Lead', 'noc.lead', 'NOC', 'NOC Team Leader', [$noc, $nocShiftA]);
        // $this->user('Tariq NOC Tier I', 'noc.tier1', 'NOC', 'NOC Tier I', [$nocShiftA]);
        // $this->user('Grace NOC Tier II', 'noc.tier2', 'NOC', 'NOC Tier II', [$nocShiftA]);

        // $cxAgent = $this->user('Chris CX Agent', 'cx.agent', 'CX', 'CX Agent', [$cx]);
        // $cxLead = $this->user('Clara CX Lead', 'cx.lead', 'CX', 'CX Team Leader', [$cx]);
        // $this->user('Tess CX Tier II', 'cx.tier2', 'CX', 'CX Tier II', [$cxTier2]);
        // $this->user('Tony CX Tier II', 'cx.tier2b', 'CX', 'CX Tier II', [$cxTier2]);
        // $this->user('Dan Dispatcher', 'dispatch', 'DISPATCH', 'Dispatcher', [$dispatch]);
        // $dispatchLead = $this->user('Dora Dispatch Lead', 'dispatch.lead', 'DISPATCH', 'Dispatch Team Leader', [$dispatch]);
        // $this->user('Steve Support Tech', 'support1', 'DISPATCH', 'Support/Installation Technician', [$support]);
        // $this->user('Susan Support Tech', 'support2', 'DISPATCH', 'Support/Installation Technician', [$support]);

        // $itManager = $this->user('Isaac IT Manager', 'it.manager', 'IT', 'IT Manager', [$it]);
        // $itLead = $this->user('Irene IT Lead', 'it.lead', 'IT', 'IT Team Leader', [$it]);
        // $this->user('Evan IT Engineer', 'it.eng1', 'IT', 'IT Engineer', [$it]);
        // $this->user('Esther IT Engineer', 'it.eng2', 'IT', 'IT Engineer', [$it]);

        // $infraManager = $this->user('Ian Infra Manager', 'infra.manager', 'INFRA', 'Infrastructure Manager', [$infra]);
        // $infraLead = $this->user('Ivy Infra Lead', 'infra.lead', 'INFRA', 'Infrastructure Team Leader', [$infra]);
        // $this->user('Owen Infra Dispatcher', 'infra.dispatch1', 'INFRA', 'Infrastructure Dispatcher', [$infra]);
        // $this->user('Olivia Infra Dispatcher', 'infra.dispatch2', 'INFRA', 'Infrastructure Dispatcher', [$infra]);

        // $techs = [
        //     $this->user('Tom Technician', 'tech.north1', 'INFRA', 'Outage Technician', [$north]),
        //     $this->user('Tina Technician', 'tech.north2', 'INFRA', 'Outage Technician', [$north]),
        //     $this->user('Sam Technician', 'tech.south1', 'INFRA', 'Outage Technician', [$south]),
        //     $this->user('Sara Technician', 'tech.south2', 'INFRA', 'Outage Technician', [$south]),
        //     $this->user('Victor Vendor Tech', 'vendor.tech1', 'INFRA', 'Outage Technician', [$vendorCrew]),
        // ];

        // $noc->forceFill(['leader_id' => $nocLead->id, 'manager_id' => $nocManager->id])->save();
        // $cx->forceFill(['leader_id' => $cxLead->id])->save();
        // $cxTier2->forceFill(['leader_id' => $cxLead->id])->save();
        // $dispatch->forceFill(['leader_id' => $dispatchLead->id])->save();
        // $support->forceFill(['leader_id' => $dispatchLead->id])->save();
        // $it->forceFill(['leader_id' => $itLead->id, 'manager_id' => $itManager->id])->save();
        // $infra->forceFill(['leader_id' => $infraLead->id, 'manager_id' => $infraManager->id])->save();
        // $north->forceFill(['leader_id' => $infraLead->id])->save();
        // $south->forceFill(['leader_id' => $infraLead->id])->save();

        // Topology is not demo data: load the real OLT → FDT → Slot → Port mapping with
        // `php artisan oms:import-topology` or TopologySeeder.
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');

        // Skills (used when skill-based FIFO is enabled)
        $internet = Service::query()->where('name', 'Internet')->value('id');
        foreach ($techs ?? [] as $tech) {
            UserSkill::query()->firstOrCreate(['user_id' => $tech->id, 'vertical_id' => $ftth, 'service_id' => null], ['skill' => 'FTTH splicing', 'is_active' => true]);
            UserSkill::query()->firstOrCreate(['user_id' => $tech->id, 'service_id' => $internet], ['skill' => 'Internet service restoration', 'is_active' => true]);
        }

        // Shifts: everyone on shift for the next 90 days so FIFO assigns immediately in the demo.
        // (One week was too short: when the shifts ran out, tickets were given to nobody and every flow
        // stopped at its first desk without an error. Re-run this seeder to extend them.)
        foreach (User::query()->where('username', '!=', 'admin')->get() as $user) {
            foreach (range(0, self::SHIFT_DAYS) as $day) {
                $date = now()->startOfDay()->addDays($day);
                ShiftAssignment::query()->firstOrCreate(
                    ['user_id' => $user->id, 'work_date' => $date->toDateString()],
                    ['shift_start' => $date, 'shift_end' => $date->copy()->endOfDay(), 'status' => 'scheduled']
                );
            }
        }

    }

    private function team(string $name, int $departmentId, TeamType $type, ?Team $parent = null): Team
    {
        return Team::query()->firstOrCreate(
            ['name' => $name, 'department_id' => $departmentId, 'parent_id' => $parent?->id],
            ['team_type' => $type, 'is_active' => true],
        );
    }

    private function user(string $name, string $username, string $departmentCode, string $role, array $teams): User
    {
        $user = User::query()->firstOrCreate(['username' => $username], [
            'name' => $name,
            'email' => "{$username}@oms.test",
            'password' => self::PASSWORD,
            'department_id' => Department::query()->where('code', $departmentCode)->value('id'),
            'is_active' => true,
            'availability_status' => AvailabilityStatus::Available,
            'availability_changed_at' => now(),
            'email_verified_at' => now(),
        ]);

        $user->syncRoles([$role]);
        $user->teams()->syncWithoutDetaching(collect($teams)->pluck('id')->all());

        return $user;
    }
}
