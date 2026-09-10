<?php


namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Tag;
use Marvel\Database\Repositories\TagRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\TagCreateRequest;
use Marvel\Http\Requests\TagUpdateRequest;
use Marvel\Http\Resources\TagResource;
use Marvel\Traits\ApiResponse;
use Marvel\Traits\MediaManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Tags", description="Product tags management - organize products with tags")
 *
 * @OA\Schema(
 *     schema="Tag",
 *     type="object",
 *     description="Product tag details",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Organic"),
 *     @OA\Property(property="slug", type="string", example="organic"),
 *     @OA\Property(property="details", type="string", nullable=true, example="Fresh organic products"),
 *     @OA\Property(property="image", type="object", nullable=true),
 *     @OA\Property(property="icon", type="string", nullable=true),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="translated_languages", type="array", @OA\Items(type="string"), example={"en"}),
 *     @OA\Property(property="type", ref="#/components/schemas/Type")
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedTags",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Tag")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=50),
 *     @OA\Property(property="last_page", type="integer", example=4)
 * )
 */
class TagController extends CoreController
{
    use ApiResponse , HasCache;
    use MediaManager;

    public $repository;

    public function __construct(TagRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_TAGS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_TAGS, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_TAGS, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_TAGS, ["only" => ["destroy"]]);
    }

    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        $query = Tag::query();

        if ($request->filled('ids')) {
            $ids = is_array($request->ids) ? $request->ids : explode(',', $request->ids);
            $query->whereIn('id', $ids);
        }

        if ($request->filled('slugs')) {
            $slugs = is_array($request->slugs) ? $request->slugs : explode(',', $request->slugs);
            $query->whereIn('slug', $slugs);
        }

        $tags = $query->paginate($limit);
        $tagData = TagResource::collection($tags)->response()->getData(true);
        $tagDataCache = $this->remember(FrontendResource::TAGS->value, md5($request->fullUrl()), $tagData);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $tagDataCache['data'] ?? [],
            "page" => $tagDataCache['meta']['current_page'] ?? 0,
            "current_page" => $tagDataCache['meta']['current_page'] ?? 0,
            "from" => $tagDataCache['meta']['from'] ?? 0,
            "to" => $tagDataCache['meta']['to'] ?? 0,
            "last_page" => $tagDataCache['meta']['last_page'] ?? 0,
            "path" => $tagDataCache['meta']['path'] ?? "",
            "per_page" => $tagDataCache['meta']['per_page'] ?? 0,
            "total" => $tagDataCache['meta']['total'] ?? 0,
            "next_page_url" => $tagDataCache['links']['next'] ?? "",
            "prev_page_url" => $tagDataCache['links']['prev'] ?? "",
            "last_page_url" => $tagDataCache['links']['last'] ?? "",
            "first_page_url" => $tagDataCache['links']['first'] ?? "",
        ]);
    }


    public function store(TagCreateRequest $request)
    {
        try {
            $validatedData = $request->validated();
            $validatedData['slug'] = $this->repository->makeSlug($request);
            $tag = $this->repository->create([
                'slug' => $validatedData['slug'],
                'name' => $validatedData['name'],
            ]);
            if ($request->has('products')) {
                $products = array_filter(array_map('intval', (array) $request->products), fn ($id) => $id > 0);
                $tag->products()->sync($products);
            }
            if ($request->has('image')) {
                if (!$this->uploadSingleImage($request, 'image', $tag, 'tags', 'tags')) {
                    throw new HttpException(422, 'Image upload failed, please check the file format or size.');
                }
            }
            if ($request->has('icon')) {
                if (!$this->uploadSingleImage($request, 'icon', $tag, 'tags', 'tags')) {
                    throw new HttpException(422, 'Icon upload failed, please check the file format or size.');
                }
            }
            $tag->load('products');
            $this->flushTag(FrontendResource::TAGS->value);
            return $this->apiResponse(TAG_CREATED_SUCCESSFULLY, 201, true, new TagResource($tag));
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }


    public function show(Request $request, $params)
    {
        try {
            if (is_numeric($params)) {
                $params = (int) $params;
                $tag = $this->repository->with('products')->where('id', $params)->firstOrFail();
                return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new TagResource($tag));
            }
            $tag = $this->repository->with('products')->where('slug', $params)->firstOrFail();
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new TagResource($tag));
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function update(TagUpdateRequest $request, $id)
    {
        try {
            $request['id'] = $id;
            return $this->tagUpdate($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function tagUpdate(Request $request)
    {
        try {
            $tag = $this->repository->findOrFail($request->id);
            $updatedTag = $this->repository->updateTag($request, $tag);
            $updatedTag->load('products');
            $this->flushTag(FrontendResource::TAGS->value);
            return $this->apiResponse(TAG_UPDATED_SUCCESSFULLY, 200, true, new TagResource($updatedTag));
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }


    public function destroy($id)
    {
        try {
            $this->repository->findOrFail($id)->delete();
            $this->flushTag(FrontendResource::TAGS->value);
            return $this->apiResponse(TAG_DELETED_SUCCESSFULLY, 200, true, true);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }
}