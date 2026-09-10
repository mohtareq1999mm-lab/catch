<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Exception;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Marvel\Database\Models\Type;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Wishlist;
use Marvel\Database\Models\Variation;
use Marvel\Exceptions\MarvelException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Author;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Manufacturer;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Database\Repositories\SettingsRepository;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Tag;
use Marvel\Exceptions\MarvelNotFoundException;
use \OpenAI;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Http\Requests\BulkDeleteProductsRequest;
use Marvel\Http\Resources\product\ProductCollection;
use Marvel\Http\Resources\ProductResource;

use const Dom\NOT_FOUND_ERR;

/**
 * @OA\Tag(name="Products", description="Product catalog endpoints - browse, search, and manage products")
 *
 * @OA\Schema(
 *     schema="Product",
 *     type="object",
 *     description="Full product details for single product view",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Hoppister Tops"),
 *     @OA\Property(property="slug", type="string", example="hoppister-tops"),
 *     @OA\Property(property="description", type="string", example="Fendi began life in 1925 as a fur and leather speciality store in Rome."),
 *     @OA\Property(property="type_id", type="integer", example=13),
 *     @OA\Property(property="price", type="number", format="float", nullable=true, example=350.00),
 *     @OA\Property(property="sale_price", type="number", format="float", nullable=true, example=300.00),
 *     @OA\Property(property="min_price", type="number", format="float", example=20.00),
 *     @OA\Property(property="max_price", type="number", format="float", example=25.00),
 *     @OA\Property(property="quantity", type="integer", example=1000),
 *     @OA\Property(property="in_stock", type="boolean", example=true),
 *     @OA\Property(property="is_taxable", type="boolean", example=false),
 *     @OA\Property(property="status", type="string", enum={"draft", "publish", "approved", "rejected", "under_review"}, example="publish"),
 *     @OA\Property(property="product_type", type="string", enum={"simple", "variable"}, example="variable"),
 *     @OA\Property(property="unit", type="string", example="1 pc"),
 *     @OA\Property(property="sku", type="string", nullable=true, example="SKU-12345"),
 *     @OA\Property(property="shop_id", type="integer", example=2),
 *     @OA\Property(property="height", type="string", nullable=true),
 *     @OA\Property(property="width", type="string", nullable=true),
 *     @OA\Property(property="length", type="string", nullable=true),
 *     @OA\Property(property="is_external", type="boolean", example=false),
 *     @OA\Property(property="is_rental", type="boolean", example=false),
 *     @OA\Property(property="ratings", type="number", format="float", example=4.5),
 *     @OA\Property(property="total_reviews", type="integer", example=25),
 *     @OA\Property(property="in_wishlist", type="boolean", example=false),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="image", type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="original", type="string"), @OA\Property(property="thumbnail", type="string")),
 *     @OA\Property(property="gallery", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="categories", type="array", @OA\Items(type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"))),
 *     @OA\Property(property="tags", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="shop", type="object", @OA\Property(property="id", type="integer", example=2), @OA\Property(property="name", type="string", example="Urban Threads Emporium"), @OA\Property(property="slug", type="string")),
 *     @OA\Property(property="type", type="object", @OA\Property(property="id", type="integer", example=13), @OA\Property(property="name", type="string", example="Clothing"), @OA\Property(property="slug", type="string")),
 *     @OA\Property(property="related_products", type="array", @OA\Items(ref="#/components/schemas/ProductSummary"))
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedProducts",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ProductSummary")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=50),
 *     @OA\Property(property="last_page", type="integer", example=5)
 * )
 */
class ProductController extends CoreController
{
    use ApiResponse, HasCache;

    public $repository;

    public $settings;

    public function __construct(ProductRepository $repository, SettingsRepository $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->middleware("permission:" . Permission::VIEW_PRODUCTS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_PRODUCT, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_PRODUCT, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_PRODUCT, ["only" => ["destroy", 'destroyAll', 'destroyBulk']]);
    }


    /**
     * Display a paginated listing of products.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $term = trim((string)$request->get('search', ''));
        $sort = trim((string)$request->get('sort', ''));
        $orderBy = trim((string)$request->get('orderBy', 'created_at'));
        $orderDir = trim((string)$request->get('orderDir', 'desc'));

        $products = $this->fetchProducts($request)->with(['variations', 'categories', 'flash_sales']);

        if ($term !== '') {
            $this->applyProductSearch($products, $term, app()->getLocale());
        }

        $sortable = ['created_at', 'updated_at', 'name', 'price', 'sold_quantity', 'sku', 'id'];
        if ($sort !== '') {
            $dir = strtolower($sort) === 'asc' ? 'asc' : 'desc';
            $products = $products->orderBy('created_at', $dir);
        } elseif (in_array($orderBy, $sortable)) {
            $dir = strtolower($orderDir) === 'asc' ? 'asc' : 'desc';
            if ($orderBy === 'name') {
                $products = $products->orderBy('name->' . app()->getLocale(), $dir);
            } else {
                $products = $products->orderBy($orderBy, $dir);
            }
        } else {
            $products = $products->orderBy('created_at', 'desc');
        }

        $products = $products->paginate($limit)->withQueryString();
        $productsCache = $this->remember(FrontendResource::PRODUCTS->value, md5($request->fullUrl()), $products);
        $data = new ProductCollection($productsCache);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data);
    }

    private function applyProductSearch($query, string $term, string $locale): void
    {
        $query->where(function (Builder $builder) use ($term, $locale) {
            $this->applyTranslatableLike($builder, 'name', $term, $locale);

            $builder->orWhere(function (Builder $sub) use ($term, $locale) {
                $this->applyTranslatableLike($sub, 'description', $term, $locale);
            });

            $builder->orWhere('sku', 'like', "%{$term}%");

            $builder->orWhereHas('variations', function (Builder $variantQuery) use ($term) {
                $variantQuery->where('sku', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Apply a LIKE search on a translatable JSON field for the given locale.
     */
    private function applyTranslatableLike(Builder $query, string $field, string $term, string $locale): void
    {
        $query->where(function ($q) use ($field, $term, $locale) {
            $q->where($field . '->' . $locale, 'like', "%$term%")
                ->orWhere($field, 'like', "%$term%");
        });
    }


    /**
     * fetchProducts
     *
     * @param mixed $request
     * @return object
     */
    private function fetchProducts(Request $request)
    {
        $products_query = $this->repository;

        if ($request->has('status') && $request->status !== null) {
            $products_query = $products_query->where('status', '=', $request->status);
        }

        if ($request->filled('item_type') && in_array($request->item_type, \Marvel\Enums\ItemType::getValues(), true)) {
            $products_query = $products_query->where('item_type', '=', $request->item_type);
        }

        if ($request->has('category')) {
            $categorySlug = trim((string)$request->category);
            $products_query->whereHas('categories', function (Builder $q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        if ($request->has('banner')) {
            $bannerSlug = trim((string)$request->banner);
            $products_query->whereHas('banners', function (Builder $q) use ($bannerSlug) {
                $q->where('slug', $bannerSlug);
            });
        }

        if ($request->has('flash_sale')) {
            $flashSaleSlug = trim((string)$request->flash_sale);
            $products_query->whereHas('flash_sales', function (Builder $q) use ($flashSaleSlug) {
                $q->where('slug', $flashSaleSlug);
            });
        }

        if ($request->has('slider')) {
            $sliderSlug = trim((string)$request->slider);
            $products_query->whereHas('sliders', function (Builder $q) use ($sliderSlug) {
                $q->where('slug', $sliderSlug);
            });
        }

        return $products_query;
    }



    public function store(ProductCreateRequest $request)
    {
        $product = $this->ProductStore($request);
        $this->flushTag(FrontendResource::PRODUCTS->value);
        return $this->apiResponse(CREATE_PRODUCT_SUCCESSFULLY, 201, true, ProductResource::make($product));
    }


    /**
     * Store a newly created resource in storage by GQL.
     *
     * @param Request $request
     * @return mixed
     */
    private function ProductStore(ProductCreateRequest $request)
    {
        try {
            return $this->repository->storeProduct($request);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }


    /**
     * Display the specified product.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, $id)
    {
        try {
            $product = $this->fetchSingleProduct($request, $id);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ProductResource::make($product));
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    private function fetchSingleProduct(Request $request, $id)
    {
        try {
            $limit = $request->limit ?? 10;
            $product = $this->repository->where('id', $id)->firstOrFail();
            $related_products = $this->repository->fetchRelated($id, $limit);
            $product->setRelation('related_products', $related_products);

            return $product->load('variations', 'categories', 'flash_sales', 'banners', 'sliders', 'brands', 'reviews', 'tags');
        } catch (Exception $e) {
            throw new MarvelNotFoundException(NOT_FOUND);
        }
    }

    /**
     * Update the specified product via REST API.
     *
     * @param ProductUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(ProductUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $product = $this->updateProduct($request);
            $this->flushTag(FrontendResource::PRODUCTS->value);
            return $this->apiResponse(UPDATE_PRODUCT_SUCCESSFULLY, 200, true, ProductResource::make($product));
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }


    /**
     * updateProduct
     *
     * @param Request $request
     * @return array
     */
    private function updateProduct(ProductUpdateRequest $request)
    {
        try {
            $id = $request->id;
            return $this->repository->updateProduct($request, $id);
        } catch (MarvelException $e) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }


    /**
     * Remove the specified product from storage via REST API.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        $request->merge(['id' => $id]);
        return $this->destroyProduct($request);
    }


    private function destroyProduct(Request $request)
    {
        try {
            $product = $this->repository->findOrFail($request->id);
            $product->delete();
            $this->flushTag(FrontendResource::PRODUCTS->value);
            return $this->apiResponse(DELETE_PRODUCT_SUCCESSFULLY, 200, true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (\Exception $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }


    public function destroyAll(Request $request)
    {
        try {
            $count = Product::count();
            Product::chunk(100, function ($products) {
                foreach ($products as $product) {
                    $this->deleteProduct($product);
                }
            });
            return $this->apiResponse(PRODUCTS_DELETED_SUCCESSFULLY, 200, true, [
                'deleted_count' => $count,
            ]);
        } catch (\Exception $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }


    /**
     * destroyBulk
     *
     * Soft delete specific products by IDs.
     *
     * @param BulkDeleteProductsRequest $request
     * @return JsonResponse
     */
    public function destroyBulk(BulkDeleteProductsRequest $request): JsonResponse
    {
        try {
            $ids = $request->input('ids');

            Product::whereIn('id', $ids)->chunk(100, function ($products) {
                foreach ($products as $product) {
                    $this->deleteProduct($product);
                }
            });

            $this->flushTag(FrontendResource::PRODUCTS->value);
            return $this->apiResponse(PRODUCTS_DELETED_SUCCESSFULLY, 200, true, [
                'deleted_ids' => $ids,
            ]);
        } catch (MarvelException $e) {
            throw new MarvelException($e->getMessage());
        }
    }


    private function deleteProduct(Product $product): void
    {
        $product->delete();
        $this->flushTag(FrontendResource::PRODUCTS->value);

    }

    public function relatedProducts(Request $request)
    {
        $limit = isset($request->limit) ? $request->limit : 10;
        $slug = $request->slug;
        return $this->repository->fetchRelated($slug, $limit);
    }

    public function exportProducts(Request $request, $shop_id)
    {
        $user = $request->user();

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        $filename = 'products-for-shop-id-' . $shop_id . '.csv';
        $headers = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires' => '0',
            'Pragma' => 'public'
        ];

        $list = $this->repository->with([
            'categories',
            'tags',
        ])->where('shop_id', $shop_id)->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
                //
            }, 200, $headers);
        }
        # add headers for each column in the CSV download
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'slug', 'deleted_at', 'created_at', 'updated_at', 'shipping_class_id', 'ratings', 'total_reviews', 'my_review', 'in_wishlist', 'rating_count', 'translated_languages', 'sold', 'blocked_dates'];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['deleted_at']);
                unset($row['shipping_class_id']);
                unset($row['updated_at']);
                unset($row['created_at']);
                unset($row['slug']);
                unset($row['ratings']);
                unset($row['total_reviews']);
                unset($row['my_review']);
                unset($row['in_wishlist']);
                unset($row['rating_count']);
                unset($row['translated_languages']);
                unset($row['sold']);
                unset($row['blocked_dates']);

                if (isset($row['image'])) {
                    $row['image'] = json_encode($row['image']);
                }
                if (isset($row['gallery'])) {
                    $row['gallery'] = json_encode($row['gallery']);
                }
                if (isset($row['blocked_dates'])) {
                    $row['blocked_dates'] = json_encode($row['blocked_dates']);
                }
                if (isset($row['video'])) {
                    $row['video'] = json_encode($row['video']);
                }
                if (isset($row['categories'])) {
                    $categories = collect($row['categories'])->pluck('id')->toArray();
                    $row['categories'] = json_encode($categories);
                }
                if (isset($row['tags'])) {
                    $tagIds = collect($row['tags'])->pluck('pivot.tag_id')->toArray();
                    $row['tags'] = json_encode($tagIds);
                }
                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }


    /**
     * exportVariableOptions
     *
     * @param Request $request
     * @param mixed $shop_id
     * @return void
     */
    public function exportVariableOptions(Request $request, $shop_id)
    {
        $user = $request->user();

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        $filename = 'variable-options-' . Str::random(5) . '.csv';
        $headers = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires' => '0',
            'Pragma' => 'public'
        ];

        $products = $this->repository->where('shop_id', $shop_id)->get();

        $list = Variation::WhereIn('product_id', $products->pluck('id'))->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
                //
            }, 200, $headers);
        }
        # add headers for each column in the CSV download
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'created_at', 'updated_at', 'translated_languages'];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['updated_at']);
                unset($row['created_at']);
                unset($row['translated_languages']);
                if (isset($row['options'])) {
                    $row['options'] = json_encode($row['options']);
                }
                if (isset($row['blocked_dates'])) {
                    $row['blocked_dates'] = json_encode($row['blocked_dates']);
                }
                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }


    /**
     * importProducts
     *
     * @param Request $request
     * @return bool
     */
    public function importProducts(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (count($requestFile)) {
            if (isset($requestFile['csv'])) {
                $uploadedCsv = $requestFile['csv'];
            } else {
                $uploadedCsv = current($requestFile);
            }
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        if (isset($shop_id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'products-' . $shop_id . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $products = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($products as $key => $product) {
                if (!isset($product['type_id'])) {
                    throw new MarvelException('MESSAGE.WRONG_CSV');
                }
                unset($product['id']);
                $product['shop_id'] = $shop_id;
                $product['image'] = json_decode($product['image'], true);
                $product['gallery'] = json_decode($product['gallery'], true);
                $product['video'] = json_decode($product['video'], true);
                $categoriesId = json_decode($product['categories'], true);
                $tagsId = json_decode($product['tags'], true);
                try {
                    $type = Type::findOrFail($product['type_id']);
                    $authorCacheKey = $product['author_id'] . '_author_id';
                    $manufacturerCacheKey = $product['manufacturer_id'] . '_manufacturer_id';
                    $product['author_id'] = Cache::remember($authorCacheKey, 30, fn() => Author::find($product['author_id'])?->id);
                    $product['manufacturer_id'] = Cache::remember($manufacturerCacheKey, 30, fn() => Manufacturer::find($product['manufacturer_id'])?->id);
                    $dataArray = $this->repository->getProductDataArray();
                    $productArray = array_intersect_key($product, array_flip($dataArray));
                    if (isset($type->id)) {
                        $newProduct = Product::FirstOrCreate($productArray);
                        $categoryCacheKey = $product['categories'] . '_categories';
                        $tagCacheKey = $product['tags'] . '_tags';
                        $categories = Cache::remember($categoryCacheKey, 30, fn() => Category::whereIn('id', $categoriesId)->get());
                        $tags = Cache::remember($tagCacheKey, 30, fn() => Tag::whereIn('id', $tagsId)->get());
                        if (!empty($categories)) {
                            $newProduct->categories()->sync($categories);
                        }
                        if (!empty($tags)) {
                            $newProduct->tags()->sync($tags);
                        }
                    }
                } catch (Exception $e) {
                    //
                }
            }
            return true;
        }
    }


    /**
     * importVariationOptions
     *
     * @param Request $request
     * @return bool
     */
    public function importVariationOptions(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (count($requestFile)) {
            if (isset($requestFile['csv'])) {
                $uploadedCsv = $requestFile['csv'];
            } else {
                $uploadedCsv = current($requestFile);
            }
        } else {
            throw new MarvelException(CSV_NOT_FOUND);
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        if (isset($user->id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'variation-options-' . Str::random(5) . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $attributes = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($attributes as $key => $attribute) {
                if (!isset($attribute['title']) || !isset($attribute['price'])) {
                    throw new MarvelException('MESSAGE.WRONG_CSV');
                }
                unset($attribute['id']);
                $attribute['options'] = json_decode($attribute['options'], true);
                try {
                    $product = Type::findOrFail($attribute['product_id']);
                    if (isset($product->id)) {
                        Variation::firstOrCreate($attribute);
                    }
                } catch (Exception $e) {
                    //
                }
            }
            return true;
        }
    }


    /**
     * @OA\Get(
     *     path="/best-selling-products",
     *     operationId="fetchBestSellingProducts",
     *     tags={"Products"},
     *     summary="Get best selling products",
     *     description="Retrieve products sorted by total sold quantity. Useful for showcasing top performers.",
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Maximum number of products to return",
     *         required=false,
     *         @OA\Schema(type="integer", default=10, minimum=1, maximum=100, example=10)
     *     ),
     *     @OA\Parameter(
     *         name="language",
     *         in="query",
     *         description="Language code for product translations",
     *         required=false,
     *         @OA\Schema(type="string", default="en", example="en")
     *     ),
     *     @OA\Parameter(
     *         name="type_slug",
     *         in="query",
     *         description="Filter by type/collection slug",
     *         required=false,
     *         @OA\Schema(type="string", example="clothing")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Best selling products retrieved successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/ProductSummary")
     *         )
     *     )
     * )
     */
    public function bestSellingProducts(Request $request)
    {
        return $this->repository->getBestSellingProducts($request);
    }


    /**
     * @OA\Get(
     *     path="/popular-products",
     *     operationId="fetchPopularProducts",
     *     tags={"Products"},
     *     summary="Get popular products",
     *     description="Retrieve products sorted by order count (popularity). Supports filtering by shop, type, and date range.",
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Maximum number of products to return",
     *         required=false,
     *         @OA\Schema(type="integer", default=10, minimum=1, maximum=100, example=10)
     *     ),
     *     @OA\Parameter(
     *         name="language",
     *         in="query",
     *         description="Language code for product translations",
     *         required=false,
     *         @OA\Schema(type="string", default="en", example="en")
     *     ),
     *     @OA\Parameter(
     *         name="shop_id",
     *         in="query",
     *         description="Filter by shop ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Parameter(
     *         name="type_id",
     *         in="query",
     *         description="Filter by type/collection ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=13)
     *     ),
     *     @OA\Parameter(
     *         name="type_slug",
     *         in="query",
     *         description="Filter by type/collection slug (alternative to type_id)",
     *         required=false,
     *         @OA\Schema(type="string", example="clothing")
     *     ),
     *     @OA\Parameter(
     *         name="range",
     *         in="query",
     *         description="Number of days to look back for popularity calculation",
     *         required=false,
     *         @OA\Schema(type="integer", example=30)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Popular products retrieved successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/ProductSummary")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Type not found (if type_slug is provided but not found)"
     *     )
     * )
     */
    public function popularProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $range = !empty($request->range) && $request->range !== 'undefined' ? $request->range : '';
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (MarvelException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }
        $products_query = $this->repository->withCount(['orders' => function ($q) {
            $q->where('order_status', 'order-completed');
        }])->with(['type', 'shop'])->orderBy('orders_count', 'desc')->where('language', $language);
        if (isset($request->shop_id)) {
            $products_query = $products_query->where('shop_id', "=", $request->shop_id);
        }
        if ($range) {
            $products_query = $products_query->whereDate('created_at', '>', Carbon::now()->subDays($range));
        }
        if ($type_id) {
            $products_query = $products_query->where('type_id', '=', $type_id);
        }
        return $products_query->take($limit)->get();
    }


    /**
     * @OA\Get(
     *     path="/products/calculate-rental-price",
     *     operationId="calculateRentalPrice",
     *     tags={"Products"},
     *     summary="Calculate rental price for a product",
     *     description="Calculates the total rental price based on duration, quantity, and selected features. Also checks availability for the given date range.",
     *     @OA\Parameter(name="product_id", in="query", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="variation_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="from", in="query", required=true, @OA\Schema(type="string", format="date", example="2023-12-01")),
     *     @OA\Parameter(name="to", in="query", required=true, @OA\Schema(type="string", format="date", example="2023-12-05")),
     *     @OA\Parameter(name="quantity", in="query", required=false, @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="persons", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="dropoff_location_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="pickup_location_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Calculated rental price",
     *         @OA\JsonContent(
     *             @OA\Property(property="price", type="number", format="float", example=150.00),
     *             @OA\Property(property="total", type="number", format="float", example=150.00)
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid product or date range"),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function calculateRentalPrice(Request $request)
    {
        $isAvailable = true;
        $product_id = $request->product_id;
        try {
            $product = Product::findOrFail($product_id);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
        if (!$product->is_rental) {
            throw new MarvelException(NOT_A_RENTAL_PRODUCT);
        }
        $variation_id = $request->variation_id;
        $quantity = $request->quantity;
        $persons = $request->persons;
        $dropoff_location_id = $request->dropoff_location_id;
        $pickup_location_id = $request->pickup_location_id;
        $deposits = $request->deposits;
        $features = $request->features;
        $from = $request->from;
        $to = $request->to;
        if ($variation_id) {
            $blockedDates = $this->repository->fetchBlockedDatesForAVariationInRange($from, $to, $variation_id);
            $isAvailable = $this->repository->isVariationAvailableAt($from, $to, $variation_id, $blockedDates, $quantity);
            if (!$isAvailable) {
                throw new marvelException(NOT_AVAILABLE_FOR_BOOKING);
            }
        } else {
            $blockedDates = $this->repository->fetchBlockedDatesForAProductInRange($from, $to, $product_id);
            $isAvailable = $this->repository->isProductAvailableAt($from, $to, $product_id, $blockedDates, $quantity);
            if (!$isAvailable) {
                throw new marvelException(NOT_AVAILABLE_FOR_BOOKING);
            }
        }

        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        $bookedDay = $from->diffInDays($to);

        return $this->repository->calculatePrice($bookedDay, $product_id, $variation_id, $quantity, $persons, $dropoff_location_id, $pickup_location_id, $deposits, $features);
    }


    /**
     * myWishlists
     *
     * @param Request $request
     * @return void
     */
    public function myWishlists(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        return ProductResource::collection($this->fetchWishlists($request)->paginate($limit));
    }


    /**
     * fetchWishlists
     *
     * @param Request $request
     * @return object
     */
    private function fetchWishlists(Request $request)
    {
        $user = $request->user();
        $wishlist = Wishlist::where('user_id', $user->id)->pluck('product_id');
        return $this->repository->whereIn('id', $wishlist);
    }


    /**
     * @OA\Get(
     *     path="/draft-products",
     *     operationId="getDraftedProducts",
     *     tags={"Products"},
     *     summary="List products in draft status",
     *     description="Returns a paginated list of drafted products belonging to the user's shop(s).",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="shop_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of drafted products",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Product")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function draftedProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->fetchDraftedProducts($request)->paginate($limit);
    }

    /**
     * fetchDraftedProducts
     *
     * @param Request $request
     * @return mixed
     */
    private function fetchDraftedProducts(Request $request)
    {
        $user = $request->user() ?? null;;
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

        $products_query = $this->repository->with(['type', 'shop'])->where('language', $language);

        switch ($user) {
            case $user->hasRole(Role::SUPER_ADMIN):
                return $products_query->whereIn('shop_id', $user->shops->pluck('id'));
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query->whereIn('shop_id', $user->shops->pluck('id'));
                }
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query->where('shop_id', $user->managed_shop->id);
                }
                break;
        }

        return $products_query;
    }

    /**
     * @OA\Get(
     *     path="/product-stock",
     *     operationId="getProductStock",
     *     tags={"Products"},
     *     summary="List products with low stock",
     *     description="Returns a paginated list of products with inventory less than 10 belonging to the user's shop(s).",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="shop_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of products with low stock",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Product")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function productStock(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->fetchProductStock($request)->paginate($limit);
    }

    /**
     * productStock
     *
     * @param Request $request
     * @return mixed
     */
    private function fetchProductStock(Request $request)
    {
        $user = $request->user();
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

        $products_query = $this->repository->with(['type', 'shop'])->where('language', $language)->where('stock_quantity', '<', 10);

        switch ($user) {
            case $user->hasRole(Role::SUPER_ADMIN):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query;
                }
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                if (isset($request->shop_id)) {
                    // shop specific
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    // overall shops
                    return $products_query->whereIn('shop_id', $user->shops->pluck('id'));
                }
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query->where('shop_id', '=', null);
                }
                break;

            default:
                return $products_query->where('shop_id', '=', null);

                break;
        }

        return $products_query;
    }

    /**
     * @OA\Put(
     *     path="/api/products/{id}/fast-shipping",
     *     tags={"Products"},
     *     summary="Toggle fast shipping availability for a product",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="is_fast_shipping_available", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Fast shipping status updated"),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function toggleFastShipping(Request $request, $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            $validated = $request->validate([
                'is_fast_shipping_available' => ['required', 'boolean'],
            ]);
            $product->update($validated);
            return $this->apiResponse(UPDATE_PRODUCT_SUCCESSFULLY, 200, true, ProductResource::make($product->load('variations', 'categories', 'flash_sales')));
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
