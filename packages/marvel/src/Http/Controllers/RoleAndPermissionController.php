<?php

namespace Marvel\Http\Controllers;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Resources\PermissionResource;
use Marvel\Http\Resources\RoleResource;
use Marvel\Http\Resources\UserResource;
use Marvel\Traits\ApiResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;

class RoleAndPermissionController extends CoreController
{
    use ApiResponse;
    public function __construct()
    {
        $this->middleware('permission:' . PermissionEnum::VIEW_ROLES)->only(['getAllRoles']);
        $this->middleware('permission:' . PermissionEnum::VIEW_ROLE)->only('showRole');
        $this->middleware('permission:' . PermissionEnum::CREATE_ROLES)->only('addRole');
        $this->middleware('permission:' . PermissionEnum::UPDATE_ROLES)->only('updateRole');
        $this->middleware('permission:' . PermissionEnum::DELETE_ROLES)->only('destroyRole');

        $this->middleware('permission:' . PermissionEnum::ASSIGN_ROLE)->only('assignRole');
        $this->middleware('permission:' . PermissionEnum::REMOVE_ROLE)->only('removeRoleFromUser');

        $this->middleware('role:' . RoleEnum::SUPER_ADMIN)->only([
            'getAllPermissions',
            'assignPermissionToRole',
            'givePermission',
            'syncPermissions',
            'removePermission',
        ]);
    }

    // ================= ROLES =================

    public function getAllRoles()
    {
        try {
            $limit = request('limit', 10);
            $search = request('search', null);
            $roles = Role::with('permissions')->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%");
            })->paginate($limit);
            $paginated = RoleResource::collection($roles)->response()->getData(true);
            return $this->apiResponse(ROLES_FETCHED_SUCCESSFULLY, 200, true, [
                "data" => $paginated['data'] ?? [],
                "page" => $roles->currentPage(),
                "current_page" => $roles->currentPage(),
                "from" => $roles->firstItem() ?? 0,
                "to" => $roles->lastItem() ?? 0,
                "last_page" => $roles->lastPage(),
                "path" => $roles->path(),
                "per_page" => $roles->perPage(),
                "total" => $roles->total(),
                "next_page_url" => $roles->nextPageUrl() ?? "",
                "prev_page_url" => $roles->previousPageUrl() ?? "",
                "last_page_url" => $roles->url($roles->lastPage()),
                "first_page_url" => $roles->url(1),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function addRole(Request $request)
    {
        try {
            $request->validate([
                'display_name' => 'required|array',
                'display_name.*' => [
                    'required',
                    'string',
                    UniqueTranslationRule::for('roles', 'display_name'),
                ],
            ]);

            $name = strtolower(str_replace(' ', '_', $request->display_name['en']));

            $role = new Role();
            $role->name = $name;
            $role->setTranslations('display_name', $request->display_name);
            $role->guard_name = 'api';
            $role->save();

            return $this->apiResponse(ROLE_ADDED_SUCCESSFULLY, 200, true, RoleResource::make($role));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }
    public function showRole(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);
            $role->load('permissions');
            return $this->apiResponse(ROLE_FETCHED_SUCCESSFULLY, 200, true, RoleResource::make($role));
        } catch (ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function updateRole(Request $request, $id)
    {
        try {
            $role = Role::findById($id, 'api');

            $request->validate([
                'display_name' => 'required|array',
                'display_name.*' => [
                    'required',
                    'string',
                    UniqueTranslationRule::for('roles', 'display_name')->ignore($id),
                ],
            ]);
            $name = strtolower(str_replace(' ', '_', $request->display_name['en']));
            $role->name = $name;
            $role->setTranslations('display_name', $request->display_name);
            $role->save();

            return $this->apiResponse(ROLE_UPDATED_SUCCESSFULLY, 200, true, RoleResource::make($role));
        } catch (RoleDoesNotExist | ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function destroyRole($id)
    {
        try {
            $role = Role::findById($id, 'api');

            if ($role->users()->count() > 0) {
                return $this->apiResponse(CANNOT_DELETE_ROLE_WITH_ASSIGNED_USERS, 409, false);
            }

            $role->delete();
            return $this->apiResponse(ROLE_DELETED_SUCCESSFULLY, 200, true, null);
        } catch (RoleDoesNotExist | ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->apiResponse(CANNOT_DELETE_ROLE_WITH_ASSIGNED_USERS, 409, false);
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function assignRole(Request $request, $userId)
    {
        try {
            $request->validate([
                'role_ids' => 'required|array',
                'role_ids.*' => Rule::exists('roles', 'id')->where(fn($q) => $q->where('guard_name', 'api')),
            ]);

            $user = User::findOrFail($userId);
            if ($user->type === 'user') {
                return $this->apiResponse(CANNOT_ASSIGN_ROLE_TO_USER, 400, false);
            }
            $roles = Role::whereIn('id', $request->role_ids)->where('guard_name', 'api')->get();
            $user->syncRoles($roles)->load('roles', 'permissions');

            return $this->apiResponse(ROLE_ASSIGNED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function removeRoleFromUser(Request $request, $userId)
    {
        try {
            $request->validate([
                'role_ids' => 'required|array',
                'role_ids.*' => Rule::exists('roles', 'id')->where(fn($q) => $q->where('guard_name', 'api')),
            ]);
            $user = User::findOrFail($userId);
            $roles = Role::whereIn('id', $request->role_ids)->where('guard_name', 'api')->get();
            foreach ($roles as $role) {
                $user->removeRole($role);
            }
            $user->load('roles', 'permissions');

            return $this->apiResponse(ROLE_REMOVED_SUCCESSFULLY, 200, true);
        } catch (ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('removeRoleFromUser failed: ' . $e->getMessage(), ['userId' => $userId]);
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    // ================= PERMISSIONS =================

    public function getAllPermissions()
    {
        try {
            $limit = request('limit', 100);
            $search = request('search', null);
            $permissions = Permission::when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })->paginate($limit);
            return $this->apiResponse(PERMISSIONS_FETCHED_SUCCESSFULLY, 200, true, PermissionResource::collection($permissions));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }



    public function assignPermissionToRole(Request $request, $roleId)
    {
        try {
            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where(fn($q) => $q->where('guard_name', 'api'))],
            ]);

            $role = Role::findById($roleId, 'api');

            $permissions = Permission::whereIn('id', $request->permissions)->where('guard_name', 'api')->get();

            $role->syncPermissions($permissions)->load('permissions');

            return $this->apiResponse(PERMISSION_ASSIGNED_SUCCESSFULLY, 200, true, RoleResource::make($role));
        } catch (RoleDoesNotExist | ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    // ================= DIRECT USER PERMISSIONS =================

    public function givePermission(Request $request, $userId)
    {
        try {
            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where(fn($q) => $q->where('guard_name', 'api'))],
            ]);

            $user = User::findOrFail($userId);
            $permissions = Permission::whereIn('id', $request->permissions)->where('guard_name', 'api')->get();

            $user->givePermissionTo($permissions);
            $user->load('roles', 'permissions');

            return $this->apiResponse(PERMISSION_ASSIGNED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function syncPermissions(Request $request, $userId)
    {
        try {
            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where(fn($q) => $q->where('guard_name', 'api'))],
            ]);

            $user = User::findOrFail($userId);
            $permissions = Permission::whereIn('id', $request->permissions)->where('guard_name', 'api')->get();

            $user->syncPermissions($permissions)->load('roles', 'permissions');

            return $this->apiResponse(PERMISSION_ASSIGNED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function removePermission(Request $request, $userId)
    {
        try {
            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where(fn($q) => $q->where('guard_name', 'api'))],
            ]);

            $user = User::findOrFail($userId);
            $permissions = Permission::whereIn('id', $request->permissions)->where('guard_name', 'api')->get();

            foreach ($permissions as $permission) {
                $user->revokePermissionTo($permission);
            }
            $user->load('roles', 'permissions');

            return $this->apiResponse(PERMISSION_ASSIGNED_SUCCESSFULLY, 200, true, UserResource::make($user));
        } catch (ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }
}
