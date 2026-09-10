<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\CategoryShop;
use Marvel\Database\Models\CouponShop;
use Marvel\Database\Models\ProductShop;
use Marvel\Database\Models\FlashSaleShop;
use Illuminate\Support\Facades\Hash;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ShopCreateRequest;
use Marvel\Http\Requests\ShopUpdateRequest;
use Marvel\Http\Requests\TransferShopOwnerShipRequest;
use Marvel\Http\Requests\UserCreateRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Settings;
use Marvel\Database\Repositories\ShopRepository;
use Marvel\Enums\Role;
use Marvel\Http\Resources\ShopCollection;
use Marvel\Http\Resources\ShopResource;
use Marvel\Traits\ApiResponse;
use Marvel\Traits\OrderStatusManagerWithPaymentTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Shops", description="Shop/store management - browse shops, follow shops, manage your own shop")
 *
 * @OA\Schema(
 *     schema="ShopSummary",
 *     type="object",
 *     description="Shop summary for listings",
 *     @OA\Property(property="id", type="integer", example=2),
 *     @OA\Property(property="name", type="string", example="Urban Threads Emporium"),
 *     @OA\Property(property="slug", type="string", example="urban-threads-emporium"),
 *     @OA\Property(property="description", type="string", example="Premium fashion and accessories store"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="orders_count", type="integer", example=150),
 *     @OA\Property(property="products_count", type="integer", example=45),
 *     @OA\Property(property="logo", type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="original", type="string"), @OA\Property(property="thumbnail", type="string")),
 *     @OA\Property(property="cover_image", type="object", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="Shop",
 *     type="object",
 *     description="Full shop details",
 *     @OA\Property(property="id", type="integer", example=2),
 *     @OA\Property(property="name", type="string", example="Urban Threads Emporium"),
 *     @OA\Property(property="slug", type="string", example="urban-threads-emporium"),
 *     @OA\Property(property="description", type="string", example="Premium fashion and accessories store"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="orders_count", type="integer", example=150),
 *     @OA\Property(property="products_count", type="integer", example=45),
 *     @OA\Property(property="owner_id", type="integer", example=1),
 *     @OA\Property(property="logo", type="object", nullable=true),
 *     @OA\Property(property="cover_image", type="object", nullable=true),
 *     @OA\Property(property="address", type="object", @OA\Property(property="street_address", type="string"), @OA\Property(property="city", type="string"), @OA\Property(property="state", type="string"), @OA\Property(property="zip", type="string"), @OA\Property(property="country", type="string")),
 *     @OA\Property(property="settings", type="object", @OA\Property(property="contact", type="string"), @OA\Property(property="website", type="string"), @OA\Property(property="location", type="object", @OA\Property(property="lat", type="number"), @OA\Property(property="lng", type="number"))),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="owner", type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="name", type="string"), @OA\Property(property="email", type="string")),
 *     @OA\Property(property="balance", type="object", nullable=true, @OA\Property(property="current_balance", type="number"), @OA\Property(property="total_earnings", type="number"), @OA\Property(property="withdrawn_amount", type="number")),
 *     @OA\Property(property="categories", type="array", @OA\Items(ref="#/components/schemas/CategorySummary"))
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedShops",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ShopSummary")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=11)
 * )
 */
class ShopController extends CoreController
{
    use OrderStatusManagerWithPaymentTrait, ApiResponse;

    public $repository;

    public function __construct(ShopRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_SHOPS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_SHOP, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_SHOP, ["only" => [
            "update",
            "syncShopRelation",
            "attachShopRelation",
            "detachShopRelation",
        ]]);
        $this->middleware("permission:" . Permission::DELETE_SHOP, ["only" => ["destroy"]]);
    }

    /**
     * @OA\Get(
     *     path="/shops",
     *     operationId="listShops",
     *     tags={"Shops"},
     *     summary="List all shops",
     *     description="Retrieve a paginated list of active shops with order and product counts.",
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer", default=1)),
     *     @OA\Response(response=200, description="Shops retrieved successfully", @OA\JsonContent(ref="#/components/schemas/PaginatedShops"))
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        $shops = $this->fetchShops($request)->paginate($limit)->withQueryString();
        $data =   new ShopCollection($shops);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data);
    }

    public function fetchShops(Request $request)
    {
        $active = $request->active ?? null;
        $Inactive = $request->inactive ?? null;
        $search = $request->search ?? null;
        $query = $this->repository->withCount(['categories', 'products']);
        if ($active) {
            $query->active();
        }
        if ($Inactive) {
            $query->inactive();
        }
        if ($search) {
            $query->search('name', $search, app()->getLocale());
        }
        return $query;
    }

    /**
     * @OA\Post(
     *     path="/shops",
     *     operationId="createShop",
     *     tags={"Shops"},
     *     summary="Create a new shop",
     *     description="Create a new shop. Requires STORE_OWNER permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="My New Shop"),
     *             @OA\Property(property="description", type="string", example="A great shop for fashion lovers"),
     *             @OA\Property(property="logo", type="object"),
     *             @OA\Property(property="cover_image", type="object"),
     *             @OA\Property(property="address", type="object"),
     *             @OA\Property(property="settings", type="object")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Shop created successfully", @OA\JsonContent(ref="#/components/schemas/Shop")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - needs STORE_OWNER permission"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(ShopCreateRequest $request)
    {
        try {
            $shop = $this->repository->storeShop($request);
            return $this->apiResponse(SHOP_CREATE_SUCCESSFULLY, 201, true, ShopResource::make($shop));
        } catch (MarvelException $th) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    /**
     * @OA\Get(
     *     path="/shops/{slug}",
     *     operationId="getShop",
     *     tags={"Shops"},
     *     summary="Get a single shop",
     *     description="Retrieve detailed shop information by slug or ID. Includes categories, owner, and balance (for shop owner/admin only).",
     *     @OA\Parameter(name="slug", in="path", description="Shop slug or ID", required=true, @OA\Schema(type="string", example="urban-threads-emporium")),
     *     @OA\Response(response=200, description="Shop retrieved successfully", @OA\JsonContent(ref="#/components/schemas/Shop")),
     *     @OA\Response(response=404, description="Shop not found")
     * )
     */
    public function show($slug, Request $request)
    {
        $query = $this->repository
            ->with(['categories'])
            ->withCount('products');

        $user = $request->user();

        // if ($user && (
        //         $user->hasRole(Role::SUPER_ADMIN) ||
        //         $user->shops()->where('slug', $slug)->exists()
        //     )) {
        //     $query->with('balance');
        // }

        $shop = is_numeric($slug)
            ? $query->where('id', $slug)->first()
            : $query->where('slug', $slug)->first();

        if (!$shop) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(SHOP_RETRIEVED_SUCCESSFULLY, 200, true, ShopResource::make($shop));

        //        $shop = $this->repository
        //            ->with(['categories', 'owner', 'ownership_history'])
        //            ->withCount(['orders', 'products']);
        //        if ($request->user() && ($request->user()->hasRole(Role::SUPER_ADMIN) || $request->user()->shops->contains('slug', $slug))) {
        //            $shop = $shop->with('balance');
        //        }
        //        if (!$shop) {
        //            return response()->json([
        //                'message' => NOT_FOUND,
        //                'status' => false
        //            ]);
        //        }
        //
        //        return match (true) {
        //            is_numeric($slug) => $shop->where('id', $slug)->firstOrFail(),
        //            is_string($slug) => $shop->where('slug', $slug)->firstOrFail(),
        //        };

    }

    /**
     * @OA\Put(
     *     path="/shops/{id}",
     *     operationId="updateShop",
     *     tags={"Shops"},
     *     summary="Update a shop",
     *     description="Update shop details. Requires ownership or SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Shop ID", required=true, @OA\Schema(type="integer", example=2)),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="logo", type="object"),
     *         @OA\Property(property="cover_image", type="object"),
     *         @OA\Property(property="address", type="object"),
     *         @OA\Property(property="settings", type="object")
     *     )),
     *     @OA\Response(response=200, description="Shop updated successfully", @OA\JsonContent(ref="#/components/schemas/Shop")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Shop not found")
     * )
     */
    public function update(ShopUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $shop = $this->updateShop($request);
            return $this->apiResponse(SHOP_UPDATED_SUCCESSFULLY, 200, true, ShopResource::make($shop));
        } catch (MarvelException $th) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function updateShop(Request $request)
    {
        $id = $request->id;
        if ($request->user()->hasPermissionTo(Permission::UPDATE_SHOP)) {
            return $this->repository->updateShop($request, $id);
        } else {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }

    public function syncShopRelation(Request $request, $id, $relation)
    {
        return $this->applyRelationAction($request, $id, $relation);
    }


    private function applyRelationAction(Request $request, $id, $relation)
    {
        $relationMap = [
            'categories' => 'categories',
            'products' => 'products',
            'coupons' => 'coupons',
            'promotions' => 'promotions',
            'flashSales' => 'flashSales',
        ];
        $relationTableMap = [
            'categories' => 'categories',
            'products' => 'products',
            'coupons' => 'coupons',
            'promotions' => 'promotions',
            'flashSales' => 'flash_sales',
        ];

        if (!array_key_exists($relation, $relationMap)) {
            return $this->apiResponse(ACTION_NOT_VALID, 400, false);
        }

        $table = $relationTableMap[$relation];

        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => "integer|exists:${table},id",
        ]);


        $shop = $this->repository->find($id);
        if (!$shop) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $relationName = $relationMap[$relation];
        $ids = $validated['ids'];

        $shop->{$relationName}()->sync($ids);


        $currentIds = $shop->{$relationName}()->pluck($table . '.id');

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'shop_id' => $shop->id,
            'relation' => $relation,
            'ids' => $currentIds,
        ]);
    }

    public function shopMaintenanceEvent(Request $request)
    {
        try {
            $id = $request->shop_id;
            return $this->repository->maintenanceShopEvent($request, $id);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Delete(
     *     path="/shops/{id}",
     *     operationId="deleteShop",
     *     tags={"Shops"},
     *     summary="Delete a shop",
     *     description="Delete a shop. Requires ownership or SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Shop ID to delete", required=true, @OA\Schema(type="integer", example=2)),
     *     @OA\Response(response=200, description="Shop deleted successfully", @OA\JsonContent(ref="#/components/schemas/Shop")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Shop not found")
     * )
     */
    public function destroy(Request $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $request->validate([
                'id' => 'required|exists:shops,id'
            ]);
            return $this->deleteShop($request);
        } catch (MarvelException $th) {
            return $this->apiResponse(COULD_NOT_DELETE_THE_RESOURCE, 500, false);
        }
    }

    public function deleteShop(Request $request)
    {
        $id = $request->id;

        try {
            $shop = $this->repository->findOrFail($id);

            // Soft-delete only the pivot rows belonging to this shop,
            // NOT the related Category / Product / Coupon / FlashSale models.
            CategoryShop::where('shop_id', $id)->delete();
            CouponShop::where('shop_id', $id)->delete();
            ProductShop::where('shop_id', $id)->delete();
            FlashSaleShop::where('shop_id', $id)->delete();

            $shop->delete();
            return $this->apiResponse(SHOP_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
    }

    /**
     * @OA\Post(
     *     path="/approve-shop",
     *     operationId="approveShop",
     *     tags={"Shop Administration"},
     *     summary="Approve Shop",
     *     description="Approve a pending shop and set commission rate. Activates shop and publishes all products. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=5, description="Shop ID to approve"),
     *             @OA\Property(property="admin_commission_rate", type="number", example=10.5, description="Custom commission rate (optional)"),
     *             @OA\Property(property="isCustomCommission", type="boolean", example=false, description="Use custom commission rate")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Shop approved successfully", @OA\JsonContent(ref="#/components/schemas/Shop")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Shop not found")
     * )
     */
    public function approveShop(Request $request)
    {

        try {
            if (!$request->user()->hasRole(Role::SUPER_ADMIN)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
            $id = $request->id;
            $admin_commission_rate = $request->admin_commission_rate;
            try {
                $shop = $this->repository->findOrFail($id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
            $shop->is_active = true;
            $shop->save();

            if (Product::count() > 0) {
                Product::where('shop_id', '=', $id)->update(['status' => 'publish']);
            }

            $balance = Balance::firstOrNew(['shop_id' => $id]);

            if (!$request->isCustomCommission) {
                $adminCommissionDefaultRate = $this->getCommissionRate($balance->total_earnings);
                $balance->admin_commission_rate = $adminCommissionDefaultRate;
            } else {
                $balance->admin_commission_rate = $admin_commission_rate;
            }
            $balance->is_custom_commission = $request->isCustomCommission;
            $balance->save();
            return $shop;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Post(
     *     path="/disapprove-shop",
     *     operationId="disapproveShop",
     *     tags={"Shop Administration"},
     *     summary="Disapprove/Disable Shop",
     *     description="Disable a shop and set all its products to draft. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=5, description="Shop ID to disapprove")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Shop disapproved successfully", @OA\JsonContent(ref="#/components/schemas/Shop")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Shop not found")
     * )
     */
    public function disApproveShop(Request $request)
    {
        try {
            if (!$request->user()->hasRole(Role::SUPER_ADMIN)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
            $id = $request->id;
            try {
                $shop = $this->repository->findOrFail($id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }

            $shop->is_active = false;
            $shop->save();

            Product::where('shop_id', '=', $id)->update(['status' => 'draft']);

            return $shop;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Get(
     *     path="/staffs",
     *     operationId="getStaffs",
     *     tags={"Staff Management"},
     *     summary="List Shop Staff",
     *     description="Get list of staff members for a shop. Requires STORE_OWNER permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="shop_id", in="query", required=true, description="Shop ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(
     *         response=200,
     *         description="Staff list retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function staffs(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $shop_id = $request->shop_id;
        try {
            if ($this->repository->hasPermission($request->user(), $shop_id)) {
                return User::permission(Permission::STAFF)->where('shop_id', $shop_id)->paginate($limit);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Post(
     *     path="/staffs",
     *     operationId="addStaff",
     *     tags={"Staff Management"},
     *     summary="Add Staff to Shop",
     *     description="Create a new staff member for a shop. Only the shop owner can add staff.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "password", "shop_id"},
     *             @OA\Property(property="name", type="string", example="John Staff"),
     *             @OA\Property(property="email", type="string", format="email", example="staff@myshop.com"),
     *             @OA\Property(property="password", type="string", format="password", example="staffPassword123"),
     *             @OA\Property(property="shop_id", type="integer", example=5, description="ID of the shop to assign staff to")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Staff created successfully", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true))),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - not shop owner")
     * )
     */
    public function addStaff(UserCreateRequest $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                $permissions = [Permission::CUSTOMER, Permission::STAFF];
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'shop_id' => $request->shop_id,
                    'password' => Hash::make($request->password),
                ]);

                $user->givePermissionTo($permissions);
                $user->assignRole(Role::STAFF);

                return true;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Delete(
     *     path="/staffs/{id}",
     *     operationId="deleteStaff",
     *     tags={"Staff Management"},
     *     summary="Delete Staff Member",
     *     description="Remove a staff member from the shop. Only the shop owner can delete staff.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Staff user ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Staff deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - not shop owner"),
     *     @OA\Response(response=404, description="Staff not found")
     * )
     */
    public function deleteStaff(Request $request, $id)
    {
        try {
            $request->id = $id;
            return $this->removeStaff($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function removeStaff(Request $request)
    {
        $id = $request->id;
        try {
            $staff = User::findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        if ($request->user()->hasPermissionTo(Permission::STORE_OWNER) || ($request->user()->hasPermissionTo(Permission::STORE_OWNER) && ($request->user()->shops->contains('id', $staff->shop_id)))) {
            $staff->delete();
            return $staff;
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    /**
     * @OA\Get(
     *     path="/my-shops",
     *     operationId="getMyShops",
     *     tags={"Shops"},
     *     summary="Get current user's shops",
     *     description="Retrieve all shops owned by the authenticated user.",
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="User's shops retrieved successfully",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ShopSummary"))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function myShops(Request $request)
    {
        $user = $request->user();
        return $this->repository->where('owner_id', '=', $user->id)->get();
    }


    /**
     * @OA\Get(
     *     path="/followed-shops-popular-products",
     *     operationId="getFollowedShopsPopularProducts",
     *     tags={"Shops"},
     *     summary="Get popular products from followed shops",
     *     description="Retrieve popular products from shops the user follows, sorted by order count.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=10)),
     *     @OA\Response(
     *         response=200,
     *         description="Popular products retrieved successfully",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ProductSummary"))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function followedShopsPopularProducts(Request $request)
    {
        $request->validate([
            'limit' => 'numeric',
        ]);

        try {
            $user = $request->user();
            $userShops = User::where('id', $user->id)->with('follow_shops')->get();
            $followedShopIds = $userShops->first()->follow_shops->pluck('id')->all();
            $limit = $request->limit ? $request->limit : 10;

            $products_query = Product::withCount('orders')->with(['shop'])->whereIn('shop_id', $followedShopIds)->orderBy('orders_count', 'desc');

            return $products_query->take($limit)->get();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Get(
     *     path="/followed-shops",
     *     operationId="getFollowedShops",
     *     tags={"Shops"},
     *     summary="Get shops followed by current user",
     *     description="Retrieve paginated list of shops that the authenticated user is following.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(
     *         response=200,
     *         description="Followed shops retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/PaginatedShops")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function userFollowedShops(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $user = $request->user();
        $currentUser = User::where('id', $user->id)->first();

        return $currentUser->follow_shops()->paginate($limit);
    }

    /**
     * @OA\Get(
     *     path="/follow-shop",
     *     operationId="checkFollowShop",
     *     tags={"Shops"},
     *     summary="Check if user follows a shop",
     *     description="Returns boolean indicating if authenticated user follows the specified shop.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="shop_id", in="query", required=true, @OA\Schema(type="integer", example=2)),
     *     @OA\Response(
     *         response=200,
     *         description="Follow status retrieved",
     *         @OA\JsonContent(type="boolean", example=true)
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function userFollowedShop(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|numeric',
        ]);

        try {
            $user = $request->user();
            $userShops = User::where('id', $user->id)->with('follow_shops')->get();
            $followedShopIds = $userShops->first()->follow_shops->pluck('id')->all();

            $shop_id = (int) $request->input('shop_id');

            return in_array($shop_id, $followedShopIds);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Post(
     *     path="/follow-shop",
     *     operationId="toggleFollowShop",
     *     tags={"Shops"},
     *     summary="Follow or unfollow a shop",
     *     description="Toggle follow status for a shop. Returns true if now following, false if unfollowed.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"shop_id"},
     *             @OA\Property(property="shop_id", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow status toggled",
     *         @OA\JsonContent(type="boolean", example=true)
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function handleFollowShop(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|numeric',
        ]);

        try {
            $user = $request->user();
            $userShops = User::where('id', $user->id)->with('follow_shops')->get();
            $followedShopIds = $userShops->first()->follow_shops->pluck('id')->all();

            $shop_id = (int) $request->input('shop_id');

            if (in_array($shop_id, $followedShopIds)) {
                $followedShopIds = array_diff($followedShopIds, [$shop_id]);
            } else {
                $followedShopIds[] = $shop_id;
            }

            $response = $user->follow_shops()->sync($followedShopIds);

            if (count($response['attached'])) {
                return true;
            }

            if (count($response['detached'])) {
                return false;
            }
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Get(
     *     path="/near-by-shop/{lat}/{lng}",
     *     operationId="getNearByShops",
     *     tags={"Shops"},
     *     summary="Find shops near a location",
     *     description="Returns shops within the configured maximum distance from the given coordinates, sorted by distance.",
     *     @OA\Parameter(name="lat", in="path", required=true, description="Latitude", @OA\Schema(type="number", format="float", example=40.7128)),
     *     @OA\Parameter(name="lng", in="path", required=true, description="Longitude", @OA\Schema(type="number", format="float", example=-74.0060)),
     *     @OA\Response(
     *         response=200,
     *         description="Nearby shops retrieved successfully",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             allOf={@OA\Schema(ref="#/components/schemas/ShopSummary")},
     *             @OA\Property(property="distance", type="number", format="float", description="Distance in km")
     *         ))
     *     ),
     *     @OA\Response(response=400, description="Invalid coordinates")
     * )
     */
    public function nearByShop($lat, $lng, Request $request)
    {
        $request['lat'] = $lat;
        $request['lng'] = $lng;

        return $this->findShopDistance($request);
    }

    public function findShopDistance(Request $request)
    {
        try {
            $settings = Settings::getData();
            $maxShopDistance = isset($settings['options']['maxShopDistance']) ? $settings['options']['maxShopDistance'] : 1000;
            $lat = $request->lat;
            $lng = $request->lng;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                throw new HttpException(400, 'invalid argument');
            }

            $near_shop = Shop::where('settings->location->lat', '!=', null)
                ->where('settings->location->lng', '!=', null)
                ->select(
                    "shops.*",
                    DB::raw("6371 * acos(cos(radians(" . $lat . "))
        * cos(radians(json_unquote(json_extract(`shops`.`settings`, '$.\"location\".\"lat\"'))))
        * cos(radians(json_unquote(json_extract(`shops`.`settings`, '$.\"location\".\"lng\"'))) - radians(" . $lng . "))
        + sin(radians(" . $lat . "))
        * sin(radians(json_unquote(json_extract(`shops`.`settings`, '$.\"location\".\"lat\"'))))) AS distance")
                )
                ->orderBy('distance', 'ASC')
                ->where('is_active', 1)
                ->get()
                ->where('distance', '<', $maxShopDistance);

            return $near_shop;
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Get(
     *     path="/new-shops",
     *     operationId="getNewOrInactiveShops",
     *     tags={"Shop Administration"},
     *     summary="List Pending/Inactive Shops",
     *     description="Get paginated list of shops filtered by active status. Use is_active=false for pending shops. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="is_active", in="query", required=true, description="Filter by active status (false=pending)", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Shops retrieved successfully", @OA\JsonContent(ref="#/components/schemas/PaginatedShops")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN")
     * )
     */
    public function newOrInActiveShops(Request $request)
    {
        try {
            $limit = $request->limit ? $request->limit : 15;
            return $this->repository->withCount(['orders', 'products'])->with(['owner.profile'])->where('is_active', '=', $request->is_active)->paginate($limit)->withQueryString();
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/transfer-shop-ownership",
     *     operationId="transferShopOwnership",
     *     tags={"Shops"},
     *     summary="Transfer Shop Ownership",
     *     description="Transfer ownership of a shop to another user. Requires STORE_OWNER permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"shop_id", "admin_id"},
     *             @OA\Property(property="shop_id", type="integer", example=5, description="Shop ID"),
     *             @OA\Property(property="admin_id", type="integer", example=10, description="User ID to transfer ownership to")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Ownership transferred successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Shop or User not found")
     * )
     */
    public function transferShopOwnership(TransferShopOwnerShipRequest $request)
    {
        try {
            return DB::transaction(fn() => $this->repository->transferShopOwnership($request));
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}