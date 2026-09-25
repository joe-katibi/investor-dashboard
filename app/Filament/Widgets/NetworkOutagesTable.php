<?php

namespace App\Filament\Widgets;

use App\Models\MajorOutageType;
use Illuminate\Database\Eloquent\Builder;

class NetworkOutagesTable extends LiveOutagesTable
{
    protected static ?int $sort = 10;

    protected static ?string $heading = 'Live network outages (FTTH / 4G)';

    protected function scope(Builder $query): void
    {
        $query->ofMajorType(MajorOutageType::NETWORK);
    }
}
