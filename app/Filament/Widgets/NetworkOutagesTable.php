<?php

namespace App\Filament\Widgets;

use App\Models\MajorOutageType;
use Illuminate\Database\Eloquent\Builder;

class NetworkOutagesTable extends LiveOutagesTable
{
    protected static ?int $sort = 10;

    // 4G outages have their own dashboard (4G NOC → Dashboard).
    protected static ?string $heading = 'Live network outages (FTTH)';

    protected function scope(Builder $query): void
    {
        $query->ofMajorType(MajorOutageType::NETWORK);
    }
}
