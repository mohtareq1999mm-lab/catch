<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class GetSingleRefundResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {

        return [
            'id'                  => $this->id,
            'title'               => $this->title,
            'refund_reason'       => $this->refund_reason,
            'description'         => $this->description,
            'amount'              => $this->roundMoney($this->amount),
            'status'              => $this->status,
            'images'              => $this->images,
            'customer'            => [
                'email'            => $this->customer->email
            ],
            'order'               => $this->getOrderData($this->order),
            'created_at'          => $this->created_at,
        ];
    }

    private function roundMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }

    // TODO When order resource done then use OrderResource Instead of these function

    private function getOrderData($data)
    {
        return [
            'id' => $data->id,
            'tracking_number' => $data->tracking_number,
            'shipping_address' => $data->shipping_address,
            'billing_address' => $data->billing_address,
            'customer_contact' => $data->customer_contact,
            'customer_name' => $data->customer_name,
            'amount' => $this->roundMoney($data->amount),
            'sales_tax' => $this->roundMoney($data->sales_tax),
            'discount' => $this->roundMoney($data->discount),
            'delivery_fee' => $this->roundMoney($data->delivery_fee),
            'order_status' => $data->order_status,
            'products' => $this->getProductData($data->products),
            'paid_total' => $this->roundMoney($data->paid_total),
            'created_at'       => $this->created_at,
        ];
    }
    private function getProductData($products)
    {
        $item = [];
        foreach ($products as $product) {
            $item[] = [
                'id'           => $product['id'],
                'name'         => $product['name'],
                'image'         => $product['image'],
                'pivot'         => $this->getProductPivot($product['pivot']),
            ];
        }
        return $item;
    }
    private function getProductPivot($pivot)
    {
        return [
            'order_quantity' => $pivot->order_quantity,
            'subtotal' => $this->roundMoney($pivot->subtotal),
        ];
    }
}
