<?php

declare(strict_types=1);

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Services\ComponentDataService;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Component Data",
 *     description="Data endpoints for Puck page builder components (optimized for SSR)"
 * )
 */
class ComponentDataController extends CoreController
{
    public function __construct(
        private readonly ComponentDataService $service
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/component-data/flash-sale-products",
     *     operationId="getFlashSaleProducts",
     *     tags={"Component Data"},
     *     summary="Get flash sale products",
     *     description="Returns currently active flash sale with its products. Used by ProductFlashSaleBlock component.",
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Maximum number of products to return",
     *         @OA\Schema(type="integer", default=10, minimum=1, maximum=100)
     *     ),
     *     @OA\Parameter(
     *         name="language",
     *         in="query",
     *         required=false,
     *         description="Language code for filtering",
     *         @OA\Schema(type="string", example="en")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Flash sale data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="flash_sale",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="title", type="string"),
     *                     @OA\Property(property="slug", type="string"),
     *                     @OA\Property(property="start_date", type="string", format="date-time"),
     *                     @OA\Property(property="end_date", type="string", format="date-time"),
     *                     @OA\Property(property="rate", type="number")
     *                 ),
     *                 @OA\Property(property="products", type="array", @OA\Items(ref="#/components/schemas/ProductSummary"))
     *             )
     *         )
     *     )
     * )
     */
    public function flashSaleProducts(Request $request): JsonResponse
    {
        $limit = (int) ($request->get('limit') ?? 10);
        $language = $request->get('language');

        $data = $this->service->getFlashSaleProducts($limit, $language);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/component-data/categories",
     *     operationId="getCategories",
     *     tags={"Component Data"},
     *     summary="Get categories",
     *     description="Returns categories for CategoryBlock component display.",
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Parameter(
     *         name="language",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="top_level",
     *         in="query",
     *         required=false,
     *         description="Only return top-level categories (no parent)",
     *         @OA\Schema(type="boolean", default=true)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="slug", type="string"),
     *                     @OA\Property(property="icon", type="string", nullable=true),
     *                     @OA\Property(property="image", type="object", nullable=true)
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function categories(Request $request): JsonResponse
    {
        $limit = (int) ($request->get('limit') ?? 10);
        $language = $request->get('language');
        $topLevelOnly = filter_var($request->get('top_level', true), FILTER_VALIDATE_BOOLEAN);

        $data = $this->service->getCategories($limit, $language, $topLevelOnly);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/component-data/collections",
     *     operationId="getCollections",
     *     tags={"Component Data"},
     *     summary="Get collections (types)",
     *     description="Returns collections/types for CollectionBlock component.",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=10)),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Collections retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function collections(Request $request): JsonResponse
    {
        $limit = (int) ($request->get('limit') ?? 10);
        $language = $request->get('language');

        $data = $this->service->getCollections($limit, $language);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/component-data/popular-products",
     *     operationId="getPopularProducts",
     *     tags={"Component Data"},
     *     summary="Get popular products",
     *     description="Returns products sorted by order count (popularity).",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=10)),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Popular products retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ProductSummary"))
     *         )
     *     )
     * )
     */
    public function popularProducts(Request $request): JsonResponse
    {
        $limit = (int) ($request->get('limit') ?? 10);
        $language = $request->get('language');

        $data = $this->service->getPopularProducts($limit, $language);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/component-data/best-selling-products",
     *     operationId="getBestSellingProducts",
     *     tags={"Component Data"},
     *     summary="Get best selling products",
     *     description="Returns products sorted by sold quantity.",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=10)),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Best selling products retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ProductSummary"))
     *         )
     *     )
     * )
     * 
     * @OA\Schema(
     *     schema="ProductSummary",
     *     type="object",
     *     @OA\Property(property="id", type="integer"),
     *     @OA\Property(property="name", type="string"),
     *     @OA\Property(property="slug", type="string"),
     *     @OA\Property(property="price", type="number"),
     *     @OA\Property(property="sale_price", type="number", nullable=true),
     *     @OA\Property(property="min_price", type="number", nullable=true),
     *     @OA\Property(property="max_price", type="number", nullable=true),
     *     @OA\Property(property="product_type", type="string"),
     *     @OA\Property(property="quantity", type="integer"),
     *     @OA\Property(property="image", type="object", nullable=true),
     *     @OA\Property(
     *         property="shop",
     *         type="object",
     *         nullable=true,
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string")
     *     ),
     *     @OA\Property(
     *         property="type",
     *         type="object",
     *         nullable=true,
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="slug", type="string")
     *     )
     * )
     */
    public function bestSellingProducts(Request $request): JsonResponse
    {
        $limit = (int) ($request->get('limit') ?? 10);
        $language = $request->get('language');

        $data = $this->service->getBestSellingProducts($limit, $language);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}

