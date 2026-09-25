<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
