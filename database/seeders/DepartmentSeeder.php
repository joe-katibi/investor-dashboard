<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/** Departments (spec §4.1). NOC and INFRA codes are used by the default FIFO queues. */
class DepartmentSeeder extends Seeder
{
    public const DEPARTMENTS = [
        ['NOC', 'NOC', 'Network Operations Centre'],
        ['CX', 'Customer Experience', 'Customer Experience / contact centre'],
        ['DISPATCH', 'Dispatch', 'Field dispatch and installation'],
        ['INFRA', 'Infrastructure', 'Infrastructure / outage maintenance'],
        ['IT', 'IT', 'Information Technology — first line for system outages'],
        ['MOBILITY', '4G NOC', '4G network operations: 4G NOC, engineers and technicians'],
        ['MGMT', 'Management', 'Executive and management'],
        // Customer ticket desks (membership of a desk is by permission; departments just organise people).
        ['BILLING', 'Billing', 'Billing agents and the Billing Manager'],
        ['COMMERCIAL', 'Commercial / Sales Admin', 'Refund assessment and sales administration'],
        ['DESIGN', 'Design', 'Network design and surveys'],
        ['ACCESS', 'Access Team', 'Property access for construction and installation'],
    ];

    public const TICKETING = ['BILLING', 'COMMERCIAL', 'DESIGN', 'ACCESS'];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as [$code, $name, $description]) {
            Department::query()->updateOrCreate(['code' => $code], ['name' => $name, 'description' => $description, 'is_active' => true]);
        }
    }
}
