<?php

namespace Database\Seeders;

use App\Models\Vertical;
use Illuminate\Database\Seeder;

/** Verticals (spec §6): FTTH and 4G Mobility. */
class VerticalSeeder extends Seeder
{
    public function run(): void
    {
        Vertical::query()->updateOrCreate(['code' => Vertical::FTTH], ['name' => 'FTTH', 'description' => 'Fibre to the home', 'is_active' => true]);
        Vertical::query()->updateOrCreate(['code' => Vertical::MOBILITY_4G], ['name' => '4G Mobility', 'description' => '4G / LTE mobile network', 'is_active' => true]);
    }
}
