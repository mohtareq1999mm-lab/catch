<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Services\General\SearchService;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class SearchController extends Controller
{
    use ApiResponse;
    private SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function index(Request $request)
    {
        $data = $this->searchService->search($request);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data);
    }
}
