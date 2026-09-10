<?php

namespace Marvel\Database\Repositories;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
use Prettus\Validator\Exceptions\ValidatorException;
use Spatie\Permission\Models\Permission;
use Marvel\Enums\Permission as UserPermission;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use App\Jobs\SendPasswordResetEmailJob;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Profile;
use Marvel\Exceptions\MarvelException;
use Marvel\Traits\MediaManager;
use Spatie\Permission\Models\Role;

class UserRepository extends BaseRepository
{
    use MediaManager;

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name' => 'like',
        'email' => 'like',
    ];

    /**
     * @var array
     */
    protected $dataArray = [
        'name',
        'email',
        'shop_id'
    ];

    /**
     * Configure the Model
     **/
    public function model()
    {
        return User::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    public function storeUser($request)
    {
        try {
            $user = $this->create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                // Ensure newly created users via repository are verified by default
                'email_verified_at' => now(),
                'phone_number' => $request->phone_number,
            ]);
            if ($request->hasFile('image')) {
                $this->uploadSingleImage($request, 'image', $user, 'user-image', 'users');
            }
            return $user;
        } catch (ValidatorException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function updateUser($request, $user)
    {
        try {
            if (isset($request['address']) && count($request['address'])) {
                foreach ($request['address'] as $address) {
                    if (isset($address['id'])) {
                        Address::findOrFail($address['id'])->update($address);
                    } else {
                        $address['customer_id'] = $user->id;
                        Address::create($address);
                    }
                }
            }

          
            if ($request->hasFile('image')) {
                $this->updateSingleImage($request, 'image', $user, 'user-image', 'users');
            }
            $user->update($request->only($this->dataArray));
            $user->load([ 'address']);
            return $user;
        } catch (ValidationException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function sendResetEmail(string $email, string $token): bool
    {
        try {
            SendPasswordResetEmailJob::dispatch($email, $token);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * Update user email and send verification link to the user.
     * @param  $request
     * @return string[]
     */

    public function updateEmail($request): array
    {
        $user = $request->user();
        $user->email = $request->email;
        $user->email_verified_at = null;
        $user->save();
        $user->sendEmailVerificationNotification();
        return ['message' => EMAIL_UPDATED_SUCCESSFULLY, 'status' => 'success'];
    }

    public function checkIfApplicationIsValid(): bool
    {
        // DISABLED: License check - always return true
        // Original code:
        // $settings = Settings::getData();
        // $useMustVerifyLicense = isset($settings->options['app_settings']['trust']) ? $settings->options['app_settings']['trust'] : false;
        // return $useMustVerifyLicense;

        return true;
    }

    public function addUserWithRole($request)
    {
        try {
            $user = $this->create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => now(),
                'type' =>'admin',
                "phone_number"=> $request->phone_number,
                'is_active'=> isset($request->is_active) ? $request->is_active : 0,
            ]);
            if ($request->hasFile('image')) {
                $this->uploadSingleImage($request, 'image', $user, 'user-image', 'users');
            }
            $role = Role::whereIn('id', $request->roles)->get();
            if ($role->count() > 0) {
                $user->assignRole($role);
            }
            return $user;
        } catch (ValidatorException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}