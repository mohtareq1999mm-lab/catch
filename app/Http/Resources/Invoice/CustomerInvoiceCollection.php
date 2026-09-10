<?php

namespace App\Http\Resources\Invoice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CustomerInvoiceCollection extends ResourceCollection
{
    public $collects = CustomerInvoiceListResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'links' => [
                'current_page' => $this->currentPage(),
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
                'last_page' => $this->lastPage(),
                'path' => $request->url(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
                'next_page_url' => $this->nextPageUrl(),
                'prev_page_url' => $this->previousPageUrl(),
                'last_page_url' => $this->url($this->lastPage()),
                'first_page_url' => $this->url(1),
            ],
        ];
    }
}
