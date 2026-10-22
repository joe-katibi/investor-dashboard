<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A value an ONU export sync changed on a customer (OLT, slot, port, ONU type, physical address…), old and new. */
#[Fillable(['onu_sync_run_id', 'customer_id', 'account_number', 'field', 'old_value', 'new_value', 'created_at'])]
class CustomerOnuChange extends Model
{
    public const UPDATED_AT = null;

    /** field => label */
    public const FIELDS = [
        'created' => 'Added from the ONU export',
        'olt' => 'OLT',
        'fdt' => 'FDT',
        'slot' => 'Slot',
        'port' => 'Port',
        'onu_type' => 'ONU type',
        'onu_physical_address' => 'ONU physical address',
        'onu_created_at' => 'ONU creation time',
        'name' => 'Customer name',
        'region' => 'Region',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(OnuSyncRun::class, 'onu_sync_run_id');
    }
}
