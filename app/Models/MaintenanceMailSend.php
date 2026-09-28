<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Timed maintenance notification already handled (sent or skipped) for a window. */
#[Fillable(['change_request_id', 'slot', 'window_start', 'status'])]
class MaintenanceMailSend extends Model
{
    protected function casts(): array
    {
        return ['window_start' => 'datetime'];
    }
}
