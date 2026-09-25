<?php

namespace App\Filament\Widgets;

use App\Models\MajorOutageType;
use Illuminate\Database\Eloquent\Builder;

class SystemOutagesTable extends LiveOutagesTable
{
    protected static ?int $sort = 12;

    protected static ?string $heading = 'System outages (CRM / Call Center / Dialer)';

    protected function scope(Builder $query): void
    {
        $query->ofMajorType(MajorOutageType::SYSTEM);
    }
}
