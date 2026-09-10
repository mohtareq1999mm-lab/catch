<?php

declare(strict_types=1);

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CmsPageRequest;
use Marvel\Http\Resources\CmsPageResource;
use Marvel\Services\CmsPageService;
use Marvel\Traits\ApiResponse;
use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="ChawkBazar API",
 *     description="ChawkBazar E-commerce Platform REST API Documentation",
 *     @OA\Contact(
 *         email="support@chawkbazar.com",
 *         name="API Support"
 *     ),
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 *
 * @OA\Server(url=L5_SWAGGER_CONST_HOST, description="API Server")
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="sanctum",
 *     description="Enter your Bearer token obtained from /token endpoint"
 * )
 *
 * @OA\Tag(name="Authentication", description="User authentication and registration endpoints [ALL ROLES]")
 * @OA\Tag(name="Password Management", description="Password reset and recovery endpoints [ALL ROLES]")
 * @OA\Tag(name="User Management", description="User administration - list, ban, activate users [SUPER_ADMIN]")
 * @OA\Tag(name="Staff Management", description="Shop staff management - add/remove staff members [STORE_OWNER]")
 * @OA\Tag(name="Shop Administration", description="Shop approval and management operations [SUPER_ADMIN]")
 * @OA\Tag(name="Withdrawal Management", description="Vendor payout requests and approval [SUPER_ADMIN, STORE_OWNER]")
 * @OA\Tag(name="Platform Configuration", description="Settings, taxes, and shipping configuration [SUPER_ADMIN]")
 * @OA\Tag(name="Content Moderation", description="Abuse reports and content approval management [SUPER_ADMIN]")
 * @OA\Tag(name="Puck Pages", description="Page builder endpoints for Puck integration [EDITOR, SUPER_ADMIN]")
 * @OA\Tag(name="CMS Pages", description="Content management system pages [EDITOR, SUPER_ADMIN]")
 *
 * @OA\Schema(
 *     schema="AuthResponse",
 *     type="object",
 *     @OA\Property(property="token", type="string", example="1|abc123xyz...", description="Bearer token for API authentication"),
 *     @OA\Property(property="permissions", type="array", @OA\Items(type="string"), example={"customer"}),
 *     @OA\Property(property="role", type="string", example="customer")
 * )
 *
 * @OA\Schema(
 *     schema="LoginResponse",
 *     type="object",
 *     @OA\Property(property="token", type="string", nullable=true, example="1|abc123xyz...", description="Bearer token or null if login failed"),
 *     @OA\Property(property="permissions", type="array", @OA\Items(type="string"), example={"customer"}),
 *     @OA\Property(property="email_verified", type="boolean", example=true),
 *     @OA\Property(property="role", type="string", example="customer")
 * )
 *
 * @OA\Schema(
 *     schema="MessageResponse",
 *     type="object",
 *     @OA\Property(property="message", type="string", example="Operation completed successfully"),
 *     @OA\Property(property="success", type="boolean", example=true)
 * )
 *
 * @OA\Schema(
 *     schema="ValidationError",
 *     type="object",
 *     @OA\Property(
 *         property="errors",
 *         type="object",
 *         example={"email": {"The email field is required."}, "password": {"The password field is required."}}
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="CmsPage",
 *     type="object",
 *     description="CMS Page details",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="title", type="string", example="Privacy Policy"),
 *     @OA\Property(property="slug", type="string", example="privacy-policy"),
 *     @OA\Property(property="content", type="array", @OA\Items(type="object"), description="List of content blocks"),
 *     @OA\Property(property="meta", type="object", nullable=true, description="SEO metadata"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedCmsPages",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CmsPage")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=10),
 *     @OA\Property(property="total", type="integer", example=5),
 *     @OA\Property(property="last_page", type="integer", example=1)
 * )
 */
class CmsPageController extends CoreController
{
    use ApiResponse;
    public function __construct(
        private readonly CmsPageService $service
    ) {
    }

    /**
     * @OA\Get(
     *     path="/cms-pages",
     *     operationId="listCmsPages",
     *     tags={"CMS Pages"},
     *     summary="List all CMS pages",
     *     description="Retrieve a paginated list of CMS pages like Privacy Policy, Terms, etc.",
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=10)),
     *     @OA\Response(response=200, description="CMS pages retrieved successfully", @OA\JsonContent(ref="#/components/schemas/PaginatedCmsPages"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) ($request->get('limit') ?? 10);

        $pages = $this->service->paginate([], $limit);
        $pageData = CmsPageResource::collection($pages)->response()->getData(true);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $pageData['data'] ?? [],
            "page" => $pageData['meta']['current_page'] ?? 0,
            "current_page" => $pageData['meta']['current_page'] ?? 0,
            "from" => $pageData['meta']['from'] ?? 0,
            "to" => $pageData['meta']['to'] ?? 0,
            "last_page" => $pageData['meta']['last_page'] ?? 0,
            "path" => $pageData['meta']['path'] ?? "",
            "per_page" => $pageData['meta']['per_page'] ?? 0,
            "total" => $pageData['meta']['total'] ?? 0,
            "next_page_url" => $pageData['links']['next'] ?? "",
            "prev_page_url" => $pageData['links']['prev'] ?? "",
            "last_page_url" => $pageData['links']['last'] ?? "",
            "first_page_url" => $pageData['links']['first'] ?? "",
        ]);
    }

    /**
     * @OA\Get(
     *     path="/cms-pages/{slug}",
     *     operationId="getCmsPageBySlug",
     *     tags={"CMS Pages"},
     *     summary="Get a CMS page by slug",
     *     description="Retrieve detailed CMS page information by slug.",
     *     @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string", example="privacy-policy")),
     *     @OA\Response(response=200, description="CMS page retrieved successfully", @OA\JsonContent(ref="#/components/schemas/CmsPage")),
     *     @OA\Response(response=404, description="Page not found")
     * )
     */
    public function show(string $slug): CmsPageResource
    {
        $page = $this->service->getBySlug($slug);
        return new CmsPageResource($page);
    }

    /**
     * @OA\Get(
     *     path="/api/puck/page",
     *     operationId="getPageByPath",
     *     tags={"Puck Pages"},
     *     summary="Get page by path",
     *     description="Fetches a page by its URL path for Puck frontend rendering",
     *     @OA\Parameter(
     *         name="path",
     *         in="query",
     *         required=false,
     *         description="URL path of the page (e.g., '/', '/about')",
     *         @OA\Schema(type="string", default="/", example="/")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Page found successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="path", type="string", example="/"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="root", type="object"),
     *                 @OA\Property(property="content", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="zones", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Page not found",
     *         @OA\JsonContent(@OA\Property(property="data", type="null"))
     *     )
     * )
     */
    public function showByPath(Request $request): JsonResponse
    {
        $path = $request->query('path', '/');

        try {
            $page = $this->service->getByPath($path);

            return response()->json([
                'path' => $page->path,
                'data' => $page->puck_data,
            ]);
        } catch (MarvelException $e) {
            return response()->json(['data' => null], 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/cms-pages",
     *     operationId="createCmsPage",
     *     tags={"CMS Pages"},
     *     summary="Create a new CMS page",
     *     description="Create a new CMS page. Requires EDITOR or SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "slug"},
     *             @OA\Property(property="title", type="string", example="Privacy Policy"),
     *             @OA\Property(property="slug", type="string", example="privacy-policy"),
     *             @OA\Property(property="content", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=201, description="CMS page created successfully", @OA\JsonContent(ref="#/components/schemas/CmsPage")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(CmsPageRequest $request): JsonResponse
    {
        $this->assertEditor($request);
        $page = $this->service->create($request->validated());
        return (new CmsPageResource($page))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @OA\Post(
     *     path="/api/puck/page",
     *     operationId="savePuckPage",
     *     tags={"Puck Pages"},
     *     summary="Create or update page (upsert)",
     *     description="Creates a new page or updates existing one by path. Requires EDITOR or SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"path", "title"},
     *             @OA\Property(property="path", type="string", example="/", description="URL path for the page"),
     *             @OA\Property(property="title", type="string", example="Homepage", description="Page title"),
     *             @OA\Property(property="slug", type="string", nullable=true, description="URL slug (auto-generated if not provided)"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 description="Puck editor data structure",
     *                 @OA\Property(property="root", type="object", @OA\Property(property="props", type="object")),
     *                 @OA\Property(
     *                     property="content",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="type", type="string", example="HeroSlider"),
     *                         @OA\Property(property="props", type="object")
     *                     )
     *                 ),
     *                 @OA\Property(property="zones", type="object")
     *             ),
     *             @OA\Property(property="meta", type="object", nullable=true, description="SEO and other metadata")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Page saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="path", type="string", example="/"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - insufficient permissions"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function storePuckPage(CmsPageRequest $request): JsonResponse
    {
        $this->assertEditor($request);

        $validated = $request->validated();

        // Check if page exists for upsert
        try {
            $existingPage = $this->service->getByPath($validated['path']);
            $page = $this->service->update($existingPage->id, $validated);
        } catch (MarvelException $e) {
            // Page not found, create new
            $page = $this->service->create($validated);
        }

        return response()->json([
            'success' => true,
            'path' => $page->path,
            'data' => $page->puck_data,
        ]);
    }

    /**
     * @OA\Put(
     *     path="/cms-pages/{id}",
     *     operationId="updateCmsPage",
     *     tags={"CMS Pages"},
     *     summary="Update a CMS page",
     *     description="Update CMS page details. Requires EDITOR or SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/CmsPage")),
     *     @OA\Response(response=200, description="CMS page updated successfully", @OA\JsonContent(ref="#/components/schemas/CmsPage")),
     *     @OA\Response(response=404, description="Page not found")
     * )
     */
    public function update(CmsPageRequest $request, int $id): CmsPageResource
    {
        $this->assertEditor($request);
        $page = $this->service->update($id, $request->validated());
        return new CmsPageResource($page);
    }

    /**
     * @OA\Delete(
     *     path="/cms-pages/{id}",
     *     operationId="deleteCmsPage",
     *     tags={"CMS Pages"},
     *     summary="Delete a CMS page",
     *     description="Delete a CMS page. Requires EDITOR or SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="CMS page deleted successfully", @OA\JsonContent(ref="#/components/schemas/MessageResponse")),
     *     @OA\Response(response=404, description="Page not found")
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertEditor($request);
        $this->service->delete($id);
        return response()->json(['success' => true]);
    }

    private function assertEditor(Request $request): void
    {
        $user = $request->user();

        if (!$user || (!$user->hasRole(Role::SUPER_ADMIN) && !$user->hasPermissionTo(Permission::EDITOR))) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }
}



