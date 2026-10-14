<?php

namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\AuthorRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AuthorRequest;
use Marvel\Http\Resources\AuthorResource;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Authors", description="Author management - browse and manage book authors")
 *
 * @OA\Schema(
 *     schema="Author",
 *     type="object",
 *     description="Author details",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Leo Tolstoy"),
 *     @OA\Property(property="slug", type="string", example="leo-tolstoy"),
 *     @OA\Property(property="bio", type="string", example="Count Lev Nikolayevich Tolstoy, usually referred to in English as Leo Tolstoy, was a Russian writer."),
 *     @OA\Property(property="quote", type="string", example="All, everything that I understand, I understand only because I love."),
 *     @OA\Property(property="born", type="string", example="1828-09-09"),
 *     @OA\Property(property="death", type="string", example="1910-11-20"),
 *     @OA\Property(property="languages", type="string", example="Russian, French"),
 *     @OA\Property(property="is_approved", type="boolean", example=true),
 *     @OA\Property(property="products_count", type="integer", example=15),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="translated_languages", type="array", @OA\Items(type="string"), example={"en"}),
 *     @OA\Property(property="socials", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="image", type="object", nullable=true),
 *     @OA\Property(property="cover_image", type="object", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedAuthors",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Author")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=100),
 *     @OA\Property(property="last_page", type="integer", example=7)
 * )
 */
class AuthorController extends CoreController
{
    use ApiResponse;
    public $repository;

    public function __construct(AuthorRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/authors",
     *     operationId="listAuthors",
     *     tags={"Authors"},
     *     summary="List all authors",
     *     description="Retrieve a paginated list of authors with product counts.",
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en", example="en")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", required=false, @OA\Schema(type="integer", default=15, example=15)),
     *     @OA\Response(response=200, description="Authors retrieved successfully", @OA\JsonContent(ref="#/components/schemas/PaginatedAuthors"))
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $authors = $this->fetchAuthors($request)->paginate($limit);
        $authorData = AuthorResource::collection($authors)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $authorData['data'] ?? [],
            "page" => $authorData['meta']['current_page'] ?? 0,
            "current_page" => $authorData['meta']['current_page'] ?? 0,
            "from" => $authorData['meta']['from'] ?? 0,
            "to" => $authorData['meta']['to'] ?? 0,
            "last_page" => $authorData['meta']['last_page'] ?? 0,
            "path" => $authorData['meta']['path'] ?? "",
            "per_page" => $authorData['meta']['per_page'] ?? 0,
            "total" => $authorData['meta']['total'] ?? 0,
            "next_page_url" => $authorData['links']['next'] ?? "",
            "prev_page_url" => $authorData['links']['prev'] ?? "",
            "last_page_url" => $authorData['links']['last'] ?? "",
            "first_page_url" => $authorData['links']['first'] ?? "",
        ]);
    }

    public function fetchAuthors(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        return $this->repository->where('language', $language);
    }

    /**
     * @OA\Post(
     *     path="/authors",
     *     operationId="createAuthor",
     *     tags={"Authors"},
     *     summary="Create a new author",
     *     description="Create a new author. Requires active permissions for the associated shop.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "language"},
     *             @OA\Property(property="name", type="string", example="George RR Martin"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="bio", type="string"),
     *             @OA\Property(property="quote", type="string"),
     *             @OA\Property(property="born", type="string", format="date"),
     *             @OA\Property(property="death", type="string", format="date"),
     *             @OA\Property(property="languages", type="string"),
     *             @OA\Property(property="socials", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="image", type="object"),
     *             @OA\Property(property="cover_image", type="object")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Author created successfully", @OA\JsonContent(ref="#/components/schemas/Author")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(AuthorRequest $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->storeAuthor($request);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/authors/{slug}",
     *     operationId="getAuthor",
     *     tags={"Authors"},
     *     summary="Get a single author",
     *     description="Retrieve detailed author information by slug or ID.",
     *     @OA\Parameter(name="slug", in="path", description="Author slug or ID", required=true, @OA\Schema(type="string", example="leo-tolstoy")),
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(response=200, description="Author retrieved successfully", @OA\JsonContent(ref="#/components/schemas/Author")),
     *     @OA\Response(response=404, description="Author not found")
     * )
     */
    public function show(Request $request, $slug)
    {
        try {
            $request->slug = $slug;
            $author = $this->fetchAuthor($request);
            return new AuthorResource($author);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function fetchAuthor(Request $request)
    {
        $slug = $request->slug;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        try {
            $author = $this->repository->where('slug', $slug)->where('language', $language)->firstOrFail();
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        return $author;
    }

    /**
     * @OA\Put(
     *     path="/authors/{id}",
     *     operationId="updateAuthor",
     *     tags={"Authors"},
     *     summary="Update an author",
     *     description="Update author details. Requires permissions for the associated shop.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Author ID", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/Author")),
     *     @OA\Response(response=200, description="Author updated successfully", @OA\JsonContent(ref="#/components/schemas/Author")),
     *     @OA\Response(response=404, description="Author not found")
     * )
     */
    public function update(AuthorRequest $request, $id)
    {
        try {
            $request->id = $id;
            return $this->updateAuthor($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateAuthor(Request $request)
    {
        if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
            try {
                $author = $this->repository->findOrFail($request->id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
            return $this->repository->updateAuthor($request, $author);
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    /**
     * @OA\Delete(
     *     path="/authors/{id}",
     *     operationId="deleteAuthor",
     *     tags={"Authors"},
     *     summary="Delete an author",
     *     description="Delete an author. Requires SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Author ID to delete", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="Author deleted successfully", @OA\JsonContent(ref="#/components/schemas/Author")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Author not found")
     * )
     */
    public function destroy($id, Request $request)
    {
        try {
            $request['id'] = $id;
            return $this->deleteAuthor($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }
    public function deleteAuthor(Request $request)
    {
        if ($request->user()->hasRole(Role::SUPER_ADMIN)) {
            $author = $this->repository->findOrFail($request->id);
            $author->delete();
            return $author;
        }
        throw new MarvelException(NOT_AUTHORIZED);
    }

    /**
     * @OA\Get(
     *     path="/top-authors",
     *     operationId="getTopAuthors",
     *     tags={"Authors"},
     *     summary="Get top authors",
     *     description="Retrieve list of authors with the most products.",
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en", example="en")),
     *     @OA\Parameter(name="limit", in="query", description="Number of results", required=false, @OA\Schema(type="integer", default=10, example=10)),
     *     @OA\Response(response=200, description="Top authors retrieved successfully", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Author")))
     * )
     */
    public function topAuthor(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $limit = $request->limit ? $request->limit : 10;
        return $this->repository->where('language', $language)->withCount('products')->orderBy('products_count', 'desc')->take($limit)->get();
    }
}
