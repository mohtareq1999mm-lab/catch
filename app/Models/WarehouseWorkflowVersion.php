<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Warehouse Workflow version (Q-02 global, append-only, immutable).
 *
 * Each publish inserts a new row. Current = latest id. Fulfillments pin
 * warehouse_workflow_version_id at creation (Q-04) and never change it.
 * Published rows must never be updated — enforced here + in service.
 */
class WarehouseWorkflowVersion extends Model
{
    protected $fillable = [
        'picking_enabled',
        'packing_enabled',
        'shipping_enabled',
        'order_received_enabled',
        'created_by',
        'published_at',
        'notes',
    ];

    protected $casts = [
        'picking_enabled' => 'boolean',
        'packing_enabled' => 'boolean',
        'shipping_enabled' => 'boolean',
        'order_received_enabled' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Published versions are immutable (Q-04 history preservation).
        // App-layer enforcement: Eloquent updating/deleting throw. Mass ops
        // via query builder (query()->update()/delete()) or raw DB bypass
        // model events by design — no app path does this (no update/delete
        // routes or services); DB-operator deletes remain possible and are
        // an accepted residual risk (see runbook note in final report).
        static::updating(function (self $version) {
            throw new \RuntimeException(
                __('wms.workflow.version_immutable', ['id' => $version->id])
            );
        });

        static::deleting(function (self $version) {
            throw new \RuntimeException(
                __('wms.workflow.version_immutable', ['id' => $version->id])
            );
        });
    }

    public static function current(): ?self
    {
        return static::query()->orderByDesc('id')->first();
    }
}
