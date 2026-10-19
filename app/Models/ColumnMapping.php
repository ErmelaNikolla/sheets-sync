<?php

namespace App\Models;

use App\Enums\DataType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColumnMapping extends Model
{
    protected $fillable = ['table_mapping_id', 'source_column', 'target_column', 'data_type', 'is_unique_key', 'date_format'];

    protected function casts(): array
    {
        return [
            'data_type' => DataType::class,
            'is_unique_key' => 'boolean',
        ];
    }

    public function tableMapping(): BelongsTo
    {
        return $this->belongsTo(TableMapping::class);
    }
}
