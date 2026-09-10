<?php


namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Services\General\CategoryHierarchyService;
use App\Traits\HasCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Repositories\CategoryRepository;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\BulkDeleteCategoriesRequest;
use Marvel\Http\Requests\CategoryCreateRequest;
use Marvel\Http\Requests\CategoryFeatureToggleRequest;
use Marvel\Http\Requests\CategoryUpdateRequest;
use Marvel\Http\Resources\CategoryResource;
use Marvel\Jobs\BulkDeleteCategoriesJob;
use Marvel\Traits\ApiResponse;



class CategoryController extends CoreController
{
    use ApiResponse, HasCache;
    public $repository;

    public function __construct(CategoryRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_CATEGORIES, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_CATEGORY, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_CATEGORY, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_CATEGORY, ["only" => ["destroy"]]);
        $this->middleware("permission:" . Permission::DELETE_CATEGORY, ["only" => ["bulkDelete", "bulkDeleteStatus", "cancelBulkDelete"]]);
        $this->middleware("permission:" . Permission::UPDATE_CATEGORY, ["only" => ["addOrRemoveCategoryFromFeature"]]);
    }


    public function index(Request $request)
    {
        $parent = $request->parent ?? null;
        $selfId = $request->exceptSelf ?? null;
        $limit = $request->per_page ?? $request->limit ?? 15;
        $active = $request->active ?? null;
        $Inactive = $request->inactive ?? null;
        $search = $request->search ?? null;
        $featureCategory = $request->input('feature-category');
        $order = $request->order;
        $sortedBy = $request->sortedBy ?? 'asc';
        $categoriesQuery = $this->repository
            ->withCount(['products']);

        if ($featureCategory) {
            $categoriesQuery = $categoriesQuery->where('is_featured', true);
        }
        if ($order && in_array($order, ['id', 'name', 'slug', 'products_count', 'created_at', 'updated_at', 'level'])) {
            $categoriesQuery = $categoriesQuery->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        }
        if ($parent) {
            $categoriesQuery = $categoriesQuery->whereNull('parent_id');
        }
        if ($selfId) {
            $categoriesQuery = $categoriesQuery->where('id', '!=', $selfId);
        }
        if ($active) {
            $categoriesQuery = $categoriesQuery->active();
        }
        if ($Inactive) {
            $categoriesQuery = $categoriesQuery->inactive();
        }
        if ($search) {
            $categoriesQuery = $categoriesQuery->search('name', $search, app()->getLocale());
        }

        $categories = $categoriesQuery->paginate($limit);
        $data = CategoryResource::collection($categories)->response()->getData(true);
        $dataCache = $this->remember(FrontendResource::CATEGORIES->value, md5($request->fullUrl()), $data);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $dataCache['data'] ?? [],
            "page" => $dataCache['meta']['current_page'] ?? 0,
            "current_page" => $dataCache['meta']['current_page'] ?? 0,
            "from" => $dataCache['meta']['from'] ?? 0,
            "to" => $dataCache['meta']['to'] ?? 0,
            "last_page" => $dataCache['meta']['last_page'] ?? 0,
            "path" => $dataCache['meta']['path'] ?? "",
            "per_page" => $dataCache['meta']['per_page'] ?? 0,
            "total" => $dataCache['meta']['total'] ?? 0,
            "next_page_url" => $dataCache['links']['next'] ?? "",
            "prev_page_url" => $dataCache['links']['prev'] ?? "",
            "last_page_url" => $dataCache['links']['last'] ?? "",
            "first_page_url" => $dataCache['links']['first'] ?? "",
        ]);
    }


    public function store(CategoryCreateRequest $request)
    {
        try {
            $category = $this->repository->saveCategory($request);
            $category->load('products');
            $this->flushTag(frontendResource::CATEGORIES->value);
            return $this->apiResponse(CATEGORY_CREATED_SUCCESSFULLY, 200, true, CategoryResource::make($category));
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }


    public function show(Request $request, $id)
    {
        try {
            $category = $this->repository->with(['parent', 'products'])
                ->withCount('products')
                ->where('id', $id)->firstOrFail();
            app(CategoryHierarchyService::class)->loadDirectChildren($category, true);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CategoryResource::make($category));
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function update(CategoryUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $category = $this->categoryUpdate($request);
            $this->flushTag(frontendResource::CATEGORIES->value);
            return $this->apiResponse(CATEGORY_UPDATED_SUCCESSFULLY, 200, true, CategoryResource::make($category));
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    private function categoryUpdate(CategoryUpdateRequest $request): Category
    {
        $category = $this->repository->findOrFail($request->id);
        $category = $this->repository->updateCategory($request, $category);
        $category->load('products');
        return $category;
    }


    public function destroy($id)
    {
        try {
            $this->repository->findOrFail($id)->delete();
            return $this->apiResponse(CATEGORY_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new MarvelException(CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES);
        }
    }


    public function fetchFeaturedCategories(Request $request)
    {
        $limit = isset($request->limit) ? $request->limit : 3;
        $categories = $this->repository->with(['products'])
            ->withCount('products')
            ->orderByDesc('products_count')
            ->limit($limit)
            ->get();

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CategoryResource::collection($categories));
    }

    public function addOrRemoveCategoryFromFeature(CategoryFeatureToggleRequest $request)
    {
        $category = Category::find($request->id);
        $category->is_featured = !$category->is_featured;
        $category->save();
        $this->flushTag(frontendResource::CATEGORIES->value);
        return $this->apiResponse(CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY, 200, true);
    }

    public function bulkDelete(BulkDeleteCategoriesRequest $request)
    {
        $ids = array_values(array_unique($request->input('ids')));

        $import = Import::create([
            'type' => FileOperationType::CATEGORY_BULK_DELETE,
            'file_path' => '',
            'file_name' => '',
            'status' => 'pending',
            'total_rows' => count($ids),
            'created_by' => $request->user()->id,
        ]);

        $this->writeBulkDeleteIdsSignal($import->id, $ids);

        BulkDeleteCategoriesJob::dispatch($import->id);

        return $this->apiResponse(CATEGORY_BULK_DELETE_STARTED, 202, true, [
            'bulk_delete_id' => $import->id,
            'status' => $import->status,
        ]);
    }

    public function bulkDeleteStatus(int $id)
    {
        $import = Import::findOrFail($id);

        $progress = $this->readBulkDeleteSignal($import->id, 'progress');
        $cancelPending = $this->bulkDeleteSignalExists($import->id, 'cancel');

        $effectiveStatus = $cancelPending ? 'cancelling' : $import->status;

        $isTerminal = in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);

        return response()->json([
            'status' => 200,
            'message' => CATEGORY_BULK_DELETE_STATUS_FETCHED,
            'success' => true,
            'data' => [
                'id' => $import->id,
                'status' => $effectiveStatus,
                'total_rows' => $import->total_rows,
                'processed_rows' => $progress['processed_rows'] ?? $import->processed_rows,
                'successful_rows' => $progress['success_rows'] ?? $import->success_rows,
                'failed_rows' => $progress['failed_rows'] ?? $import->failed_rows,
                'errors' => $import->errors,
                'error_count' => is_array($import->errors) ? count($import->errors) : 0,
                'created_at' => optional($import->created_at)->toIso8601String(),
                'completed_at' => $isTerminal ? optional($import->updated_at)->toIso8601String() : null,
            ],
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function cancelBulkDelete(int $id)
    {
        $import = Import::findOrFail($id);

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return $this->apiResponse(__('message.MESSAGE.IMPORT_CANNOT_CANCEL'), 409, false);
        }

        $this->writeBulkDeleteIdsSignal($import->id, null, 'cancel');

        return $this->apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, [
            'bulk_delete_id' => $import->id,
            'status' => 'cancelling',
        ]);
    }

    protected function bulkDeleteSignalPath(int $importId, string $type): string
    {
        return storage_path("app/imports/{$type}_{$importId}.json");
    }

    protected function bulkDeleteSignalExists(int $importId, string $type): bool
    {
        $path = $this->bulkDeleteSignalPath($importId, $type);
        clearstatcache(true, $path);

        return file_exists($path);
    }

    protected function readBulkDeleteSignal(int $importId, string $type): ?array
    {
        $path = $this->bulkDeleteSignalPath($importId, $type);
        clearstatcache(true, $path);

        if (!file_exists($path)) {
            return null;
        }

        try {
            return json_decode((string) file_get_contents($path), true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function writeBulkDeleteIdsSignal(int $importId, ?array $ids, string $type = 'ids'): void
    {
        $dir = storage_path('app/imports');

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $data = $type === 'cancel' ? ['cancelled_at' => now()->toIso8601String()] : ['ids' => $ids];

        try {
            file_put_contents($this->bulkDeleteSignalPath($importId, $type), json_encode($data), LOCK_EX);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
