<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Facades\Socialite;
use Marvel\Console\MarvelVerification;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Wallet;
use Marvel\Database\Repositories\UserRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use App\Events\AdminLoggedIn;
use App\Events\UserRolesUpdated;
use Marvel\Exceptions\MarvelException;
use Marvel\Exceptions\MarvelNotFoundException;
use Marvel\Http\Requests\AdminCreateUserRequest;
use Marvel\Http\Requests\ChangePasswordRequest;
use Marvel\Http\Requests\LicenseRequest;
use Marvel\Http\Requests\UserAuthEmailAndPasswordRequest;
use Marvel\Http\Requests\UserCreateRequest;
use Marvel\Http\Requests\UserUpdateRequest;
use Marvel\Http\Resources\UserResource;
use Marvel\Mail\ContactAdmin;
use Marvel\Otp\Gateways\OtpGateway;
use Marvel\Otp\Gateways\LocalGateway;
use Marvel\Traits\ApiResponse;
use Marvel\Traits\UsersTrait;
use Marvel\Traits\WalletsTrait;
use App\Enums\UserType;
use App\Services\Currency\UserCurrencyPreferenceService;
use Spatie\Newsletter\Facades\Newsletter;

/**
 * @OA\Tag(name="User Management", description="User management endpoints [SUPER_ADMIN, STAFF, CUSTOMER]")
 *
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="John Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="john@example.com"),
 *     @OA\Property(property="email_verified_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="shop_id", type="integer", nullable=true),
 *     @OA\Property(property="profile", type="object", @OA\Property(property="avatar", type="object"), @OA\Property(property="bio", type="string"), @OA\Property(property="contact", type="string")),
 *     @OA\Property(property="address", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="permissions", type="array", @OA\Items(type="object", @OA\Property(property="name", type="string", example="super_admin")))
 * )
 */
class UserController extends CoreController
{
    use WalletsTrait, UsersTrait, ApiResponse;

    public $repository;
    private bool $applicationIsValid;
    private array $appData;

    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
        $this->applicationIsValid = $this->repository->checkIfApplicationIsValid();
        $this->middleware("permission:" . Permission::VIEW_USERS, ["only" => ["index", "show", "adminTrashedUsers"]]);
        $this->middleware("permission:" . Permission::CREATE_USER, ["only" => ["adminAddUsers"]]);
        $this->middleware("permission:" . Permission::DELETE_USER, ["only" => ["adminDeleteUsers", "adminDeleteUsersForever", "destroy"]]);
        $this->middleware("permission:" . Permission::EDIT_USER, ["only" => ["adminUpdateActivationUsers", "update"]]);
        $this->middleware("permission:" . Permission::RESTORE_USER, ["only" => ["adminRestoreUser"]]);
        $this->middleware("permission:" . Permission::ADD_POINTS, ["only" => ["addPoints"]]);
        $this->middleware("permission:" . Permission::BAN_USER, ["only" => ["banUser"]]);
        $this->middleware("permission:" . Permission::ACTIVATE_USER, ["only" => ["activeUser"]]);
    }

    /**
     * Validate user email from the link sent to the user.
     * @param  $id
     * @param  $hash
     * @return RedirectResponse
     */
    public function verifyEmail($id, $hash)
    {
        $user = User::findOrFail($id);
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        $user->markEmailAsVerified();
        // return Redirect::to(config('app.frontend_url') . '/email-verified');
    }

    /**
     * Send the email verification notification.
     *
     * @return JsonResponse
     */
    public function sendVerificationEmail(User $user): JsonResponse
    {
        $user->sendEmailVerificationNotification();
        return $this->apiResponse(EMAIL_VERIFICATION_LINK_SENT, 200, true);
    }






    public function index(Request $request)
    {
        try {
            $filterUsers = $request->boolean('users');
            $filterAdmins = $request->boolean('admins');
            $filterTrash = $request->boolean('trash');
            $active = $request->boolean('active');
            $inActive = $request->boolean('in_active');
            $limit = $request->limit ? $request->limit : 15;
            $query = $this->repository->with(['permissions']);

            if ($filterTrash) {
                $query = $query->onlyTrashed();
            }

            if ($filterUsers) {
                $query = $query->where('type', 'user');
            } elseif ($filterAdmins) {
                $query = $query->where('type', 'admin');
            }

            if ($active) {
                $query = $query->where('is_active', true);
            }

            if ($inActive) {
                $query = $query->where('is_active', false);
            }

            if ($request->has('type')) {
                $query = $query->where('type', $request->query('type'));
            }

            if ($search = $request->query('search')) {
                $query = $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                });
            }

            $orderBy = $request->query('order_by', 'created_at');
            $sort = $request->query('sort', 'desc');
            $query = $query->orderBy($orderBy, $sort);

            $users = $query->paginate($limit)->withQueryString();
            $paginated = UserResource::collection($users)->response()->getData(true);
            return $this->apiResponse(USERS_LISTED_SUCCESSFULLY, 200, true, [
                "data" => $paginated['data'] ?? [],
                "page" => $users->currentPage(),
                "current_page" => $users->currentPage(),
                "from" => $users->firstItem() ?? 0,
                "to" => $users->lastItem() ?? 0,
                "last_page" => $users->lastPage(),
                "path" => $users->path(),
                "per_page" => $users->perPage(),
                "total" => $users->total(),
                "next_page_url" => $users->nextPageUrl() ?? "",
                "prev_page_url" => $users->previousPageUrl() ?? "",
                "last_page_url" => $users->url($users->lastPage()),
                "first_page_url" => $users->url(1),
            ]);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Post(
     *     path="/users",
     *     operationId="createUser",
     *     tags={"User Management"},
     *     summary="Create User (Admin)",
     *     description="Create a new user with any role. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "password"},
     *             @OA\Property(property="name", type="string", example="New User"),
     *             @OA\Property(property="email", type="string", format="email", example="newuser@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123"),
     *             @OA\Property(property="permission", type="string", enum={"customer", "staff", "editor", "super_admin"}, example="customer")
     *         )
     *     ),
     *     @OA\Response(response=201, description="User created successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(UserCreateRequest $request)
    {
        try {
            $user = $this->repository->storeUser($request);
            return $this->apiResponse(USER_ADDED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Get(
     *     path="/users/{id}",
     *     operationId="getUser",
     *     tags={"User Management"},
     *     summary="Get User Details",
     *     description="Get a single user's full details. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="User ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="User retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function show($id)
    {
        try {

            $user = $this->repository->findOrFail($id);
            $user->load(['roles', 'permissions']);
            if ($user->type === 'user') {
                $user->load(['address']);
            }
            return $this->apiResponse(USER_FETCHED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/users/{id}",
     *     operationId="updateUser",
     *     tags={"User Management"},
     *     summary="Update User",
     *     description="Update user profile. SUPER_ADMIN can update any user; others can only update themselves.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="User ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Updated Name"),
     *             @OA\Property(property="email", type="string", format="email", example="updated@example.com"),
     *             @OA\Property(property="profile", type="object"),
     *             @OA\Property(property="address", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=200, description="User updated successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function update(UserUpdateRequest $request, $id)
    {
        try {
            if ($request->user()->hasRole(Role::SUPER_ADMIN)) {
                $user = $this->repository->findOrFail($id);
                $updatedUser = $this->repository->updateUser($request, $user);
                return $this->apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true, UserResource::make($updatedUser));
            } elseif ($request->user()->id == $id) {
                $user = $request->user();
                $updatedUser = $this->repository->updateUser($request, $user);
                return $this->apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true, UserResource::make($updatedUser));
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Delete(
     *     path="/users/{id}",
     *     operationId="deleteUser",
     *     tags={"User Management"},
     *     summary="Delete User",
     *     description="Permanently delete a user. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="User ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="User deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function destroy($id)
    {
        try {
            $user = $this->repository->findOrFail($id);
            if ($user->hasRole('super_admin') || $user->id === auth()->id()) {
                return $this->apiResponse(USER_ADMIN_CANNOT_BE_DELETED, 400, false);
            }
            $user->tokens()->delete();
            $user->delete();
            return $this->apiResponse(USER_DELETED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Get(
     *     path="/me",
     *     operationId="getCurrentUser",
     *     tags={"Authentication"},
     *     summary="Get Current User",
     *     description="Get the currently authenticated user's profile with wallet, addresses, and shop information",
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="User profile retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="role", type="string", example="customer"),
     *             @OA\Property(property="profile", type="object"),
     *             @OA\Property(property="wallet", type="object"),
     *             @OA\Property(property="address", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            if (isset($user)) {
                $user = $this->repository
                    ->find($user->id);
                $user->role = $user->roles->first()?->name;
                $user->unsetRelation('roles');
                return $this->apiResponse(USER_PROFILE_RETRIEVED_SUCCESSFULLY, 200, true, UserResource::make($user));
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }

    public function adminAddUsers(AdminCreateUserRequest $request)
    {
        try {
            $user = $this->repository->addUserWithRole($request);
            $user->load(['roles']);
            return $this->apiResponse(USER_ADDED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
    public function adminDeleteUsers($id)
    {
        try {
            $user = $this->repository->findOrFail($id);
            if ($user->hasRole('super_admin') || $user->id === auth()->id()) {
                return $this->apiResponse(USER_ADMIN_CANNOT_BE_DELETED, 400, false);
            }
            $user->tokens()->delete();
            $user->delete();
            return $this->apiResponse(USER_DELETED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
    public function adminDeleteUsersForever($id)
    {
        try {
            $user = User::withTrashed()->findOrFail($id);
            if ($user->hasRole('super_admin') || $user->id === auth()->id()) {
                return $this->apiResponse(USER_ADMIN_CANNOT_BE_DELETED, 400, false);
            }
            $user->forceDelete();
            return $this->apiResponse(USER_DELETED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
    public function adminRestoreUser($id)
    {
        try {
            $user = User::withTrashed()->findOrFail($id);
            if (!$user->trashed()) {
                return $this->apiResponse(USER_CANNOT_BE_RESTORED, 400, false);
            }
            $user->restore();
            return $this->apiResponse(USER_RESTORED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
    public function adminTrashedUsers(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $trashedUsers = User::onlyTrashed()
            ->with(['permissions'])
            ->paginate($limit);
        $data = UserResource::collection($trashedUsers)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $data['data'] ?? [],
            "page" => $data['meta']['current_page'] ?? 0,
            "current_page" => $data['meta']['current_page'] ?? 0,
            "from" => $data['meta']['from'] ?? 0,
            "to" => $data['meta']['to'] ?? 0,
            "last_page" => $data['meta']['last_page'] ?? 0,
            "path" => $data['meta']['path'] ?? "",
            "per_page" => $data['meta']['per_page'] ?? 0,
            "total" => $data['meta']['total'] ?? 0,
            "next_page_url" => $data['links']['next'] ?? "",
            "prev_page_url" => $data['links']['prev'] ?? "",
            "last_page_url" => $data['links']['last'] ?? "",
            "first_page_url" => $data['links']['first'] ?? "",
        ]);
    }
    public function adminUpdateActivationUsers(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);
        try {
            $user = $this->repository->findOrFail($request->user_id);
            if ($user->hasRole('super_admin')) {
                if ($user->id === auth()->id() || $user->is_active === false) {
                    return $this->apiResponse(USER_CANNOT_BE_UPDATED, 400, false);
                }
            }
            $user->is_active = !$user->is_active;
            $user->save();
            return $this->apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    /**
     * @OA\Post(
     *     path="/token",
     *     operationId="login",
     *     tags={"Authentication"},
     *     summary="User Login",
     *     description="Authenticate user and get access token",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful login",
     *         @OA\JsonContent(ref="#/components/schemas/LoginResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationError")
     *     )
     * )
     */
    public function token(UserAuthEmailAndPasswordRequest $request)
    {
        $request->validated();

        $user = User::where(function ($query) use ($request) {
            $query->where('email', $request->email)
                ->orWhere('phone_number', $request->phone_number);
        })->where('is_active', true)->first();

if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->apiResponse(INVALID_CREDENTIALS, 404, false);
        }

        app(UserCurrencyPreferenceService::class)->adoptGuestCurrencyOnLogin($user, $request);

        $email_verified = $user->hasVerifiedEmail();
        $data = [
            "token" => $user->createToken('auth_token',[],now()->addWeekdays(14))->plainTextToken,
            "email_verified" => $email_verified,
            "permissions" => $user->getAllPermissions()->pluck('name'),
            "role" => $user->roles->pluck('name'),
            'expires_at' => $user->tokens()->latest()->first()?->expires_at,
        ];

        return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data);
    }
    public function adminToken(UserAuthEmailAndPasswordRequest $request)
    {
        $request->validated();

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->apiResponse(INVALID_CREDENTIALS, 404, false);
        }
        if ($user->type !== 'admin') {
            return $this->apiResponse(USER_NOT_FOUND, 404, false);
        }
        $email_verified = $user->hasVerifiedEmail();
        if (!$email_verified) {
            return $this->apiResponse(USER_NOT_VERIFIED, 404, false);
        }
        $data = [
            "token" => $user->createToken('auth_token',[],now()->addWeek())->plainTextToken,
            "permissions" => $user->getAllPermissions()->pluck('name'),
            "email_verified" => $email_verified,
            "role" => $user->roles->pluck('name'),
            'expires_at' => $user->tokens()->latest()->first()?->expires_at,
        ];
        AdminLoggedIn::dispatch($user, request()->ip(), request()->userAgent());
        return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data);
    }
    public function loginWithOutEmailVerification(UserAuthEmailAndPasswordRequest $request)
    {
        abort(410, __('message.gone'));
    }
    public function sendUserOtp(Request $request)
    {
        $request->validate([
            'email' => 'required_without:phone_number|email',
            'phone_number' => 'required_without:email|string|max:15|min:11',
        ]);

        if ($request->email) {
            $user = User::where('email', $request->email)->where('is_active', true)->first();
        } else {
            $user = User::where('phone_number', $request->phone_number)->where('is_active', true)->first();
        }
        if (!$user) {
            return $this->apiResponse(USER_NOT_FOUND, 404, false);
        }
        $data = [];
        if ($request->email) {
            try {
                $oneTimePassword = $user->createOneTimePassword();
                $notificationClass = config('one-time-passwords.notification');
                $user->notify(new $notificationClass($oneTimePassword));
            } catch (\Exception $e) {
                $data = [
                    'otp_status' => false,
                    'requires_resend' => true,
                ];
                return $this->apiResponse(ACCOUNT_CREATED_BUT_OTP_FAILED, 201, true);
            }
        } else {
            $otpResponse = $this->sendOtpCode($request);
            if (is_array($otpResponse)) {
                $data['otp_id'] = $otpResponse['otp_id'] ?? null;
            }
        }
        return $this->apiResponse(OTP_SENT_SUCCESSFULLY, 200, true, $data);
    }
    public function verifyLoginOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|min:4|max:6',
        ]);
        $user = User::where('email', $request->email)->where('is_active', true)->first();

        if (!$user) {
            return $this->apiResponse(USER_NOT_FOUND, 404, false);
        }

if ($user->verifyOneTimePassword($request->code)) {
            $user->update(['email_verified_at' => now()]);

            app(UserCurrencyPreferenceService::class)->adoptGuestCurrencyOnLogin($user, $request);

            $data = [
                "token" => $user->createToken('auth_token',[],now()->addWeek())->plainTextToken,
            ];
            return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data);
        }

        return $this->apiResponse(INVALID_OTP, 400, false);
    }


    public function logout(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->apiResponse(USER_NOT_FOUND, 404, false);
        }

        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return $this->apiResponse(USER_LOGGED_OUT_SUCCESSFULLY, 200, true);
    }

    /**
     * @OA\Post(
     *     path="/register",
     *     operationId="register",
     *     tags={"Authentication"},
     *     summary="Register New User",
     *     description="Create a new user account and get access token",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "password"},
     *             @OA\Property(property="name", type="string", maxLength=255, example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="securePassword123"),
     *             @OA\Property(property="permission", type="string", enum={"customer", "user", "admin"}, example="customer", description="User permission level")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully registered",
     *         @OA\JsonContent(ref="#/components/schemas/AuthResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationError")
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized (attempted to register as super_admin, editor, or staff - these roles are admin-assigned only)"
     *     )
     * )
     */
    public function register(UserCreateRequest $request)
    {
        try {
            DB::beginTransaction();
            $request->validated();

            $user = $this->repository->create([
                'name' => $request->first_name . ' ' . $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone_number' => $request->phone_number,
                'type' => 'user',
                'is_active' => true,
            ]);

            if ($request->hasFile('avatar')) {
                $user->addMedia($request->file('avatar'))->toMediaCollection('avatar');
            }

            DB::commit();
            
            if ($user->email) {
                try {
                    $user->sendOneTimePassword();
                    $data = ['otp_status' => true];
                    return $this->apiResponse(USER_REGISTERED_SUCCESSFULLY, 200, true, $data);
                } catch (\Exception $mailException) {
                    $data = [
                        'requires_resend' => true,
                        'email' => $user->email,
                        'phone_number' => $user->phone_number,
                        'otp_status' => false
                    ];

                    return $this->apiResponse(ACCOUNT_CREATED_BUT_OTP_FAILED, 201, true, $data);
                }
            }
            
            // No email: registration succeeds with no email-side effect
            return $this->apiResponse(USER_REGISTERED_SUCCESSFULLY, 200, true, ['otp_status' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false, $e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/ban-user",
     *     operationId="banUser",
     *     tags={"User Management"},
     *     summary="Ban User",
     *     description="Deactivate a user account and all their shops. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=5, description="User ID to ban")
     *         )
     *     ),
     *     @OA\Response(response=200, description="User banned successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN")
     * )
     */
    public function banUser(Request $request)
    {
        try {
            $user = $request->user();
            if ($user && $user->hasPermissionTo(Permission::BAN_USER) && $user->id != $request->id) {
                $banUser = User::findOrFail($request->id);
                $banUser->tokens()->delete();
                $banUser->is_active = false;
                $banUser->save();
                return $this->apiResponse(USER_DEACTIVATED_SUCCESSFULLY, 200);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function activeUser(Request $request)
    {
        try {
            $user = $request->user();
            if ($user && $user->hasPermissionTo(Permission::ACTIVATE_USER) && $user->id != $request->id) {
                $activeUser = User::findOrFail($request->id);
                $activeUser->is_active = true;
                $activeUser->save();
                return $this->apiResponse(USER_ACTIVATED_SUCCESSFULLY, 200);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Post(
     *     path="/forget-password",
     *     operationId="forgetPassword",
     *     tags={"Password Management"},
     *     summary="Request Password Reset",
     *     description="Send password reset email to user",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password reset email sent",
     *         @OA\JsonContent(ref="#/components/schemas/MessageResponse")
     *     )
     * )
     */
    public function forgetPassword(Request $request)
    {
        $user = $this->repository->findByField('email', $request->email);
        if (count($user) < 1) {
            return $this->apiResponse(CHECK_INBOX_FOR_PASSWORD_RESET_EMAIL, 200);
        }

        $plainTextToken = random_int(100000, 999999);
        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($plainTextToken),
                'created_at' => Carbon::now(),
            ]
        );

        if ($this->repository->sendResetEmail($request->email, $plainTextToken)) {
            return $this->apiResponse(CHECK_INBOX_FOR_PASSWORD_RESET_EMAIL, 200);
        } else {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500);
        }
    }



    public function verifyForgetPasswordToken(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string',
        ]);

        if ($this->checkResetToken($request)) {
            return $this->apiResponse(TOKEN_IS_VALID, 200, true);
        }

        return $this->apiResponse(INVALID_TOKEN, 400, false);
    }

    private function checkResetToken(Request $request): bool
    {
        $tokenData = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$tokenData) {
            return false;
        }

        if (!Hash::check($request->otp, $tokenData->token)) {
            return false;
        }

        if (
            Carbon::parse($tokenData->created_at)
            ->addMinutes(config('auth.passwords.users.expire', 60))
            ->isPast()
        ) {
            return false;
        }

        return true;
    }


    public function resetPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string|min:8|max:50|confirmed',
            'password_confirmation' => ['required', 'string', 'min:8', 'max:50'],
            'email' => 'email|required',
            'otp' => 'required|string'
        ]);

        try {
            return DB::transaction(function () use ($request) {
                if (!$this->checkResetToken($request)) {
                    return $this->apiResponse(INVALID_TOKEN, 400, false);
                }

                $user = $this->repository->where('email', $request->email)->first();
                $user->password = Hash::make($request->password);
                $user->save();

                $user->tokens()->delete();

                DB::table('password_resets')->where('email', $user->email)->delete();

                return $this->apiResponse(PASSWORD_RESET_SUCCESSFUL, 200, true);
            });
        } catch (\Exception $th) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    /**
     * @OA\Post(
     *     path="/change-password",
     *     operationId="changePassword",
     *     tags={"Password Management"},
     *     summary="Change Password",
     *     description="Change the current user's password (requires old password)",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"oldPassword", "newPassword"},
     *             @OA\Property(property="oldPassword", type="string", format="password", example="currentPassword123"),
     *             @OA\Property(property="newPassword", type="string", format="password", example="newSecurePassword456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password changed successfully",
     *         @OA\JsonContent(ref="#/components/schemas/MessageResponse")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error or old password incorrect")
     * )
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $user = $request->user();
            if (Hash::check($request->oldPassword, $user->password)) {
                $user->password = Hash::make($request->newPassword);
                $user->save();
                $user->tokens()->delete();
                return $this->apiResponse(PASSWORD_RESET_SUCCESSFUL, 200, true);
            } else {
                return $this->apiResponse(OLD_PASSWORD_INCORRECT, 400, false);
            }
        } catch (\Exception $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function contactAdmin(Request $request)
    {
        try {
            $listedAdmin = [];
            $admins = $this->getAdminUsers();
            if (isset($admins)) {
                foreach ($admins as $key => $admin) {
                    array_push($listedAdmin, $admin->email);
                }
            }
            $details = $request->only('subject', 'name', 'email', 'description');
            $emailTo = isset($request['emailTo']) ? $request['emailTo'] : $listedAdmin;
            Mail::to($emailTo)->send(new ContactAdmin($details));
            return $this->apiResponse(EMAIL_SENT_SUCCESSFUL, 200, true);
        } catch (\Exception $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function fetchStaff(Request $request)
    {
        try {
            if (!isset($request->shop_id)) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->with(['profile'])->where('shop_id', '=', $request->shop_id);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function staffs(Request $request)
    {
        $query = $this->fetchStaff($request);
        $limit = $request->limit ?? 15;
        return $query->paginate($limit);
    }


    /**
     * @OA\Post(
     *     path="/social-login-token",
     *     operationId="socialLogin",
     *     tags={"Authentication"},
     *     summary="Social Login",
     *     description="Login or register using Facebook or Google OAuth token",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"provider", "access_token"},
     *             @OA\Property(property="provider", type="string", enum={"facebook", "google"}, example="google"),
     *             @OA\Property(property="access_token", type="string", example="ya29.a0AfH6SMC...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully authenticated",
     *         @OA\JsonContent(ref="#/components/schemas/AuthResponse")
     *     ),
     * @OA\Response(response=422, description="Invalid provider or token")
     * )
     */
    public function socialLogin(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|string',
            'access_token' => 'required|string',
        ]);

        $provider = $validated['provider'];
        $token = $validated['access_token'];
        $this->validateProvider($provider);

        try {
            $user = Socialite::driver($provider)->userFromToken($token);

            $userCreated = User::firstOrCreate(
                [
                    'email' => $user->getEmail(),
                ],
                [
                    'email_verified_at' => now(),
                    'name' => $user->getName(),
                    'password' => Hash::make(Str::random(16)),
                    'type' => 'user',
                ]
            );

$userCreated->providers()->updateOrCreate(
                [
                    'provider' => $provider,
                    'provider_user_id' => $user->getId(),
                ]
            );

            app(UserCurrencyPreferenceService::class)->adoptGuestCurrencyOnLogin($userCreated, $request);

            $data = [
                "token" => $userCreated->createToken('auth_token',[],now()->addWeek())->plainTextToken,
            ];
            return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data);
        } catch (\Exception $e) {
            throw new MarvelException(INVALID_CREDENTIALS);
        }
    }

    protected function validateProvider($provider)
    {
        if (!in_array($provider, ['facebook', 'google'])) {
            throw new MarvelException(PLEASE_LOGIN_USING_FACEBOOK_OR_GOOGLE);
        }
    }


    protected function getOtpGateway()
    {
        $gateway = config('auth.active_otp_gateway');
        $gateWayClass = "Marvel\\Otp\\Gateways\\" . ucfirst($gateway) . 'Gateway';
        try {
            return new OtpGateway(new $gateWayClass());
        } catch (\Throwable $e) {
            // Log the issue and fallback to a local/testing gateway that requires no credentials
            Log::warning('OTP gateway unavailable, falling back to LocalGateway: ' . $e->getMessage());
            return new OtpGateway(new LocalGateway());
        }
    }

    protected function verifyOtp(Request $request)
    {
        $id = $request->otp_id;
        $code = $request->code;
        $phoneNumber = $request->phone_number;
        try {
            $otpGateway = $this->getOtpGateway();
            $verifyOtpCode = $otpGateway->checkVerification($id, $code, $phoneNumber);
            if ($verifyOtpCode->isValid()) {
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Send OTP Code - ROUTE DISABLED
     * Uncomment routes in Routes.php to enable
     */
    public function sendOtpCode(Request $request)
    {
        $phoneNumber = $request->phone_number;
        try {
            if (empty($phoneNumber)) {
                return ['message' => 'ERROR.EMPTY_MOBILE_NUMBER', 'success' => false];
            }

            $otpGateway = $this->getOtpGateway();
            $sendOtpCode = $otpGateway->startVerification($phoneNumber);
            if (!$sendOtpCode->isValid()) {
                return ['message' => OTP_SEND_FAIL, 'success' => false];
            }
            $user = User::where('phone_number', $phoneNumber)->first();
            return [
                'message' => OTP_SEND_SUCCESSFUL,
                'success' => true,
                'provider' => config('auth.active_otp_gateway'),
                'otp_id' => $sendOtpCode->getId(),
                // include static OTP to help frontend testing
                // 'otp' => '123456',
                'phone_number' => $phoneNumber,
                'is_contact_exist' => $user ? true : false
            ];
        } catch (MarvelException $e) {
            throw new MarvelException(INVALID_GATEWAY);
        }
    }

    /**
     * Verify OTP Code - ROUTE DISABLED
     * Uncomment routes in Routes.php to enable
     */
    public function verifyOtpCode(Request $request)
    {
        try {
            if ($this->verifyOtp($request)) {
                return [
                    "message" => OTP_SEND_SUCCESSFUL,
                    "success" => true,
                ];
            }
            throw new MarvelException(OTP_VERIFICATION_FAILED);
        } catch (\Throwable $e) {
            throw new MarvelException(OTP_VERIFICATION_FAILED);
        }
    }

    /**
     * Login via OTP - ROUTE DISABLED
     * Uncomment routes in Routes.php to enable
     */
    public function otpLogin(Request $request)
    {
        try {
            if ($request->has("email")) {
                return $this->verifyLoginOtp($request);
            }
            if ($request->has("phone_number") && $this->verifyOtp($request)) {
                $user = User::where('phone_number', $request->phone_number)->first();
                if (!$user) {
                    return $this->apiResponse(REQUIRED_INFO_MISSING, 404, false);
                } else {
                    $user = User::where('id', $user->id)->first();
                }

                $token = $user->createToken('auth_token',[],now()->addWeek())->plainTextToken;

                return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, ['token' => $token]);
            } else {
                return $this->apiResponse(OTP_VERIFICATION_FAILED, 400, false);
            }
        } catch (\Throwable $e) {
            return $this->apiResponse(INVALID_GATEWAY, 422, false);
        }
    }

    public function updateContact(Request $request)
    {
        $phoneNumber = $request->phone_number;
        $user_id = $request->user_id;

        try {
            if ($this->verifyOtp($request)) {
                $user = User::find($user_id);
                $user->profile()->updateOrCreate(
                    ['customer_id' => $user_id],
                    [
                        'contact' => $phoneNumber
                    ]
                );
                return [
                    "message" => CONTACT_UPDATE_SUCCESSFUL,
                    "success" => true,
                ];
            }
            return $this->apiResponse(CONTACT_UPDATE_FAILED, 400, false);
        } catch (\Exception $e) {
            return $this->apiResponse(INVALID_GATEWAY, 422, false);
        }
    }

    /**
     * @OA\Post(
     *     path="/add-points",
     *     operationId="addPoints",
     *     tags={"User Management"},
     *     summary="Add Loyalty Points",
     *     description="Add loyalty/reward points to a customer's wallet. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"customer_id", "points"},
     *             @OA\Property(property="customer_id", type="integer", example=5, description="User ID to add points to"),
     *             @OA\Property(property="points", type="number", example=100, description="Number of points to add")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Points added successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function addPoints(Request $request)
    {
        $request->validate([
            'points' => 'required|numeric',
            'customer_id' => ['required', 'exists:Marvel\Database\Models\User,id']
        ]);
        $points = $request->points;
        $customer_id = $request->customer_id;

        $wallet = Wallet::firstOrCreate(['customer_id' => $customer_id]);
        $wallet->total_points = $wallet->total_points + $points;
        $wallet->available_points = $wallet->available_points + $points;
        $wallet->save();
        return $this->apiResponse(POINTS_ADDED_SUCCESSFULLY, 200, true);
    }

    /**
     * @OA\Post(
     *     path="/users/make-admin",
     *     operationId="makeOrRevokeAdmin",
     *     tags={"User Management"},
     *     summary="Toggle Admin Status",
     *     description="Grant or revoke SUPER_ADMIN permission from a user. If user is admin, revokes; if not, grants. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id"},
     *             @OA\Property(property="user_id", type="integer", example=5, description="User ID to toggle admin status")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Admin status toggled successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function makeOrRevokeAdmin(Request $request)
    {
        $user = $request->user();
        if ($this->repository->hasPermission($user)) {
            $user_id = $request->user_id;
            try {
                $newUser = $this->repository->findOrFail($user_id);
                if ($newUser->type === UserType::ADMIN->value) {
                    $oldRoles = $newUser->roles->pluck('name')->toArray();
                    $newUser->update(['type' => UserType::USER->value]);
                    UserRolesUpdated::dispatch($newUser, $oldRoles, $newUser->roles()->pluck('name')->toArray());
                    return $this->apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true);
                }
            } catch (Exception $e) {
                throw new MarvelException(USER_NOT_FOUND);
            }
            $oldRoles = $newUser->roles->pluck('name')->toArray();
            $newUser->update(['type' => UserType::ADMIN->value]);
            UserRolesUpdated::dispatch($newUser, $oldRoles, $newUser->roles()->pluck('name')->toArray());

            return $this->apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true);
        }

        throw new MarvelException(NOT_AUTHORIZED);
    }

    /**
     * @OA\Post(
     *     path="/subscribe-to-newsletter",
     *     operationId="subscribeNewsletter",
     *     tags={"Users"},
     *     summary="Subscribe to newsletter",
     *     description="Add an email address to the platform's newsletter subscription list.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="buyer@example.com")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Successfully subscribed", @OA\JsonContent(type="boolean", example=true)),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Something went wrong")
     * )
     */
    public function subscribeToNewsletter(Request $request)
    {
        try {
            $email = $request->email;
            Newsletter::subscribeOrUpdate($email);
            return $this->apiResponse(EMAIL_SENT_SUCCESSFUL, 200, true);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Post(
     *     path="/update-email",
     *     operationId="updateEmail",
     *     tags={"Authentication"},
     *     summary="Update User Email",
     *     description="Update the current user's email address. Requires authentication.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="newemail@example.com")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Email updated successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function updateUserEmail(Request $request)
    {
        $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()?->id),
            ],
        ]);
        return $this->repository->updateEmail($request);
    }

    public function myStaffs(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        return $this->fetchMyStaffs($request)->paginate($limit);
    }

    public function fetchMyStaffs(Request $request)
    {
        $user = $request->user();
        if ($this->repository->hasPermission($user, $request->shop_id)) {
            return $this->repository->whereRelation('managed_shop', 'owner_id', '=', $user->id);
        }
        return $this->repository->whereRelation('managed_shop', 'owner_id', '=', null);
    }

    public function allStaffs(Request $request)
    {
        $user = $request->user();
        $limit = $request->limit ? $request->limit : 15;
        if ($this->repository->hasPermission($user)) {
            return $this->repository->permission(Permission::STAFF)->paginate($limit);
        }
        return $this->repository->permission(null)->paginate($limit);
    }


    public function verifyLicenseKey(LicenseRequest $request, MarvelVerification $verification)
    {
        try {
            $licenseKey = $request->license_key;
            $language = $request['language'] ?? DEFAULT_LANGUAGE;
            $marvel = $verification->verify($licenseKey);
            if (!$marvel->getTrust()) {
                throw new MarvelNotFoundException(INVALID_LICENSE_KEY);
            }
            return $marvel->modifySettingsData($language);
        } catch (MarvelException $th) {
            throw new MarvelException(INVALID_LICENSE_KEY);
        }
    }

    public function fetchUsersByPermission(Request $request)
    {
        $user = $request->user() ?? null;
        $permission = strtolower($request->permission) ?? true;
        $is_active = $request->is_active ?? true;
        $query = $this->repository->where('is_active', $is_active);
        if (!$this->repository->hasPermission($user, $request->shop_id)) {
            return $query->permission(null);
        }
        switch ($permission) {
            case Role::SUPER_ADMIN:
                $query->role($permission);
                break;
            case Permission::STORE_OWNER:
                $excludeUsers = User::role(Role::SUPER_ADMIN)->pluck('id')->toArray();
                if (isset($request->exclude)) {
                    $excludeUsers = [...$excludeUsers, $request->exclude];
                }
                $query->permission($permission)->whereNotIn('id', $excludeUsers);
                break;
            case Permission::STAFF:
                $query->permission($permission);
                break;
            case Permission::CUSTOMER:
                $excludeUsers = User::role([Role::SUPER_ADMIN, Role::STORE_OWNER, Role::STAFF])
                    ->pluck('id')->toArray();
                $query->permission($permission)->whereNotIn('id', $excludeUsers);
                break;
            default:
                $query->permission(null);
                break;
        }
        return $query;
    }
}
