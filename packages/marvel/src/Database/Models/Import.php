<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportStatus;

class Import extends Model
{
    protected $table = 'imports';

    protected $fillable = [
        'type',
        'operation_type',
        'file_path',
        'file_name',
        'images_source',
        'zip_file_path',
        'status',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'errors',
        'created_by',
    ];

    protected $casts = [
        'errors' => 'array',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'failed_rows' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $hasOperationType = self::hasOperationTypeColumn();

            // Ensure operation_type is populated for new records if column exists
            if ($hasOperationType) {
                if (empty($model->attributes['operation_type']) && ! empty($model->attributes['type'])) {
                    $model->attributes['operation_type'] = FileOperationType::normalize($model->attributes['type']) ?? $model->attributes['type'];
                } elseif (! empty($model->attributes['operation_type']) && empty($model->attributes['type'])) {
                    $model->attributes['type'] = FileOperationType::normalize($model->attributes['operation_type']) ?? $model->attributes['operation_type'];
                } elseif (! empty($model->attributes['type']) && ! empty($model->attributes['operation_type'])) {
                    $normalized = FileOperationType::normalize($model->attributes['operation_type']) ?? $model->attributes['operation_type'];
                    $model->attributes['type'] = $normalized;
                    $model->attributes['operation_type'] = $normalized;
                }
            } else {
                // No operation_type column — ensure we don't try to write it
                unset($model->attributes['operation_type']);
                if (! empty($model->attributes['type'])) {
                    $model->attributes['type'] = FileOperationType::normalize($model->attributes['type']) ?? $model->attributes['type'];
                }
            }
        });

        static::updating(function (self $model) {
            $hasOperationType = self::hasOperationTypeColumn();

            if ($hasOperationType) {
                if ($model->isDirty('type') && ! $model->isDirty('operation_type')) {
                    $model->attributes['operation_type'] = FileOperationType::normalize($model->attributes['type']) ?? $model->attributes['type'];
                } elseif ($model->isDirty('operation_type') && ! $model->isDirty('type')) {
                    $model->attributes['type'] = FileOperationType::normalize($model->attributes['operation_type']) ?? $model->attributes['operation_type'];
                }
            } else {
                unset($model->attributes['operation_type']);
            }
        });

        // Clean up operation_type attribute if column doesn't exist before saving
        static::saving(function (self $model) {
            if (! self::hasOperationTypeColumn() && array_key_exists('operation_type', $model->attributes)) {
                unset($model->attributes['operation_type']);
            }
        });
    }

    protected static function hasOperationTypeColumn(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('imports', 'operation_type');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getOperationTypeAttribute(): ?string
    {
        // Prefer physical column if available
        $raw = $this->attributes['operation_type'] ?? $this->attributes['type'] ?? null;

        return FileOperationType::normalize($raw);
    }

    public function setOperationTypeAttribute(?string $value): void
    {
        $normalized = FileOperationType::normalize($value) ?? $value;
        $this->attributes['type'] = $normalized;

        if (self::hasOperationTypeColumn()) {
            $this->attributes['operation_type'] = $normalized;
        } else {
            unset($this->attributes['operation_type']);
        }
    }

    public function scopeOfOperationType($query, string $operationType)
    {
        $normalized = FileOperationType::normalize($operationType) ?? $operationType;

        return $query->where(function ($q) use ($normalized) {
            $q->where('type', $normalized);

            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('imports', 'operation_type')) {
                    $q->orWhere('operation_type', $normalized);
                }
            } catch (\Throwable $e) {
            }
        });
    }

    /**
     * Scope that handles legacy short values and both type/operation_type columns.
     */
    public function scopeWhereOperationType($query, string $operationType)
    {
        $normalized = FileOperationType::normalize($operationType) ?? $operationType;
        $legacy = array_search($normalized, [
            'product' => FileOperationType::PRODUCT_IMPORT,
            'category' => FileOperationType::CATEGORY_IMPORT,
            'brand' => FileOperationType::BRAND_IMPORT,
        ], true);

        $values = $legacy !== false ? [$normalized, $legacy] : [$normalized];

        // Support both physical columns for transition period
        return $query->where(function ($q) use ($values) {
            $q->whereIn('type', $values);

            // If operation_type column exists, also check it (covers migrated rows)
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('imports', 'operation_type')) {
                    $q->orWhereIn('operation_type', $values);
                }
            } catch (\Throwable $e) {
                // Schema check may fail in testing with in-memory DB
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', ImportStatus::PENDING);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            ImportStatus::COMPLETED,
            ImportStatus::COMPLETED_WITH_ERRORS,
            ImportStatus::FAILED,
            ImportStatus::CANCELLED,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            ImportStatus::COMPLETED,
            ImportStatus::COMPLETED_WITH_ERRORS,
            ImportStatus::FAILED,
            ImportStatus::CANCELLED,
        ]);
    }
}
