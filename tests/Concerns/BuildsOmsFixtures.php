<?php

namespace Tests\Concerns;

use App\Enums\AvailabilityStatus;
use App\Enums\TeamType;
use App\Models\Department;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;

trait BuildsOmsFixtures
{
    protected function seedReference(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, ReferenceDataSeeder::class]);
        app(Settings::class)->flush();
    }

    protected function makeUser(string $role, string $department, array $teams = [], array $attributes = [], bool $onShift = true): User
    {
        $user = User::factory()->create(array_merge([
            'department_id' => Department::query()->where('code', $department)->value('id'),
            'availability_status' => AvailabilityStatus::Available,
            'availability_changed_at' => now()->subHour(),
            'is_active' => true,
        ], $attributes));

        $user->assignRole($role);
        $user->teams()->sync(collect($teams)->pluck('id'));

        if ($onShift) {
            ShiftAssignment::query()->create([
                'user_id' => $user->id,
                'work_date' => today(),
                'shift_start' => now()->subHours(2),
                'shift_end' => now()->addHours(8),
                'status' => 'scheduled',
            ]);
        }

        return $user->refresh();
    }

    protected function makeTeam(string $name, string $department, ?Team $parent = null): Team
    {
        return Team::query()->create([
            'name' => $name,
            'department_id' => Department::query()->where('code', $department)->value('id'),
            'parent_id' => $parent?->id,
            'team_type' => TeamType::InHouse,
            'is_active' => true,
        ]);
    }

    protected function reportOutage(User $by, array $overrides = []): Outage
    {
        Auth::setUser($by);

        return app(OutageWorkflow::class)->create(array_merge([
            'title' => 'Test outage',
            'outage_type_id' => OutageType::query()->where('code', 'regular')->value('id'),
            'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
            'customers_affected' => 20,
            'start_time' => now()->subMinutes(5),
        ], $overrides), $by);
    }

    protected function makeOlt(string $name = 'OLT-A'): Olt
    {
        return Olt::query()->create(['name' => $name, 'is_active' => true]);
    }
}
