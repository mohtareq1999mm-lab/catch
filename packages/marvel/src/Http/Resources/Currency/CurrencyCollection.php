<?php

namespace Marvel\Http\Resources\Currency;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CurrencyCollection extends ResourceCollection
{
    public $collects = CurrencyResource::class;

    public function toArray($request)
    {
        return [
            'data' => CurrencyResource::collection($this->collection),
        ];
    }

    public function with($request)
    {
        return [
            'success' => true,
            'message' => __('message.MESSAGE.FETCH_DATA_SUCCESSFULLY'),
            'meta' => [
                'current_page' => $this->currentPage(),
                'first_item' => $this->firstItem(),
                'last_item' => $this->lastItem(),
                'last_page' => $this->lastPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
                'next_page_url' => $this->nextPageUrl(),
                'previous_page_url' => $this->previousPageUrl(),
            ],
        ];
    }
}
