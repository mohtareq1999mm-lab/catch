<?php

namespace Marvel\Http\Resources\Order;

use Illuminate\Http\Request;
use Marvel\Http\Resources\Resource;

class OrderTransactionResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'invoice_id' => $this->invoice_id,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'amount' => $this->amount,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
