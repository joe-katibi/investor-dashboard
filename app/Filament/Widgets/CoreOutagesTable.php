<?php

namespace App\Filament\Widgets;

use App\Models\MajorOutageType;
use Illuminate\Database\Eloquent\Builder;

class CoreOutagesTable extends LiveOutagesTable
{
    protected static ?int $sort = 11;

    protected static ?string $heading = 'Core outages (SEACOM / core services)';

    protected function scope(Builder $query): void
    {
        $query->ofMajorType(MajorOutageType::CORE);
    }
}
