<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentPod extends Model
{
    protected $table = 'shipment_pods';

    protected $fillable = [
        'shipment_id',
        'shipment_assignment_id',
        'shipment_delivery_attempt_id',
        'status',
        'recipient_name',
        'captured_at',
        'file_reference',
        'signature_reference',
        'otp_reference',
        'uploaded_by',
        'metadata',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShipmentAssignment::class, 'shipment_assignment_id');
    }

    public function deliveryAttempt(): BelongsTo
    {
        return $this->belongsTo(ShipmentDeliveryAttempt::class, 'shipment_delivery_attempt_id');
    }
}
