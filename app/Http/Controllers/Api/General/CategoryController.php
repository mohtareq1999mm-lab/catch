<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Category\CategoryHomeResource;
use App\Http\Resources\Category\CategoryWithChildResource;
use App\Services\General\CategoryService;
use App\Traits\HasCache;
use Marvel\Traits\ApiResponse;
use Illuminate\Http\Request;

use const Dom\NO_DATA_ALLOWED_ERR;

class CategoryController extends Controller
{
    use ApiResponse , HasCache;
    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function index(Request $request)
    {
        if ($slug = $request->query('slug')) {
            return $this->getCategoryBySlug($slug);
        }
        $categoriesCache = $this->remember(
            FrontendResource::CATEGORIES->value,
            md5($request->fullUrl()),
            fn() => $this->categoryService->paginate($request)
        );
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CategoryHomeResource::collection($categoriesCache));
    }

    public function getCategoryBySlug($slug)
    {
        $category = $this->categoryService->getBySlug($slug);
        if (!$category) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true,  CategoryWithChildResource::make($category));
    }

}