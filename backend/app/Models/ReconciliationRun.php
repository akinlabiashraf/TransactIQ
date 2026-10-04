<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationRun extends Model
{
    use HasUuids;

    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'run_reference',
        'provider',
        'source_file',
        'reconciliation_date',
        'total_internal_records',
        'total_provider_records',
        'matched_records',
        'mismatched_records',
        'matched_volume_minor',
        'mismatched_volume_minor',
        'status',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'reconciliation_date' => 'date',
            'total_internal_records' => 'integer',
            'total_provider_records' => 'integer',
            'matched_records' => 'integer',
            'mismatched_records' => 'integer',
            'matched_volume_minor' => 'integer',
            'mismatched_volume_minor' => 'integer',
            'summary' => 'array',
        ];
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ReconciliationException::class);
    }
}
