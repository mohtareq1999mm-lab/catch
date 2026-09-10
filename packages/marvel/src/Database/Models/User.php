<?php

namespace Marvel\Database\Models;

// DISABLED: Email verification not needed
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserType;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use App\Events\UserRolesUpdated;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
// DISABLED: Notifiable trait causes SMTP connection attempts
// use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Database\Factories\UserFactory;
use Marvel\Enums\OrderStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;

class User extends Authenticatable implements MustVerifyEmail, HasMedia
{
    // DISABLED: use Notifiable;
    use HasRoles {
        assignRole as protected assignRoleViaTrait;
        syncRoles as protected syncRolesViaTrait;
        removeRole as protected removeRoleViaTrait;
    }
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;
    use InteractsWithMedia;
    use HasFactory;
    use HasOneTimePasswords;



    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'type',
        // Allow setting verification timestamp explicitly on creation/update
        'email_verified_at',
        'phone_number',
        'remember_token',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $appends = ['email_verified'];

    protected static function newFactory()
    {
        return UserFactory::new();
    }

    protected static function boot()
    {
        parent::boot();
        // Order by updated_at desc
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('updated_at', 'desc');
        });
    }

    public function assignRole(...$roles)
    {
        $oldRoles = $this->roles->pluck('name')->toArray();
        $result = $this->assignRoleViaTrait(...$roles);
        $this->unsetRelation('roles');
        $newRoles = $this->roles->pluck('name')->toArray();
        if ($oldRoles !== $newRoles) {
            event(new UserRolesUpdated($this, $oldRoles, $newRoles));
        }
        return $result;
    }

    public function syncRoles(...$roles)
    {
        $oldRoles = $this->roles->pluck('name')->toArray();
        $result = $this->syncRolesViaTrait(...$roles);
        $this->unsetRelation('roles');
        $newRoles = $this->roles->pluck('name')->toArray();
        if ($oldRoles !== $newRoles) {
            event(new UserRolesUpdated($this, $oldRoles, $newRoles));
        }
        return $result;
    }

    public function removeRole($role)
    {
        $oldRoles = $this->roles->pluck('name')->toArray();
        $result = $this->removeRoleViaTrait($role);
        $this->unsetRelation('roles');
        $newRoles = $this->roles->pluck('name')->toArray();
        if ($oldRoles !== $newRoles) {
            event(new UserRolesUpdated($this, $oldRoles, $newRoles));
        }
        return $result;
    }

    public function getEmailVerifiedAttribute(): bool
    {
        return $this->hasVerifiedEmail();
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification());
    }


    /**
     * @return HasMany
     */
    public function address(): HasMany
    {
        return $this->hasMany(Address::class, 'customer_id');
    }

    /**
     * @return HasOne
     */

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

/**
     * @return HasOne
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'customer_id');
    }

    /**
     * @return HasOne
     */
    public function preference(): HasOne
    {
        return $this->hasOne(\App\Models\UserPreference::class, 'user_id');
    }

    /**
     * @return HasOne
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'customer_id');
    }

    /**
     * @return HasMany
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'owner_id');
    }

    /**
     * @return HasMany
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'customer_id');
    }

    /**
     * @return BelongsTo
     */
    public function managed_shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * @return HasMany
     */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class, 'user_id', 'id');
    }

    /**
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'user_id');
    }

    /**
     * Follow shop
     *
     * @return BelongsToMany
     */
    public function follow_shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'user_shop');
    }


    /**
     * Follow shop
     *
     * @return HasMany
     */
    public function payment_gateways(): HasMany
    {
        return $this->HasMany(PaymentGateway::class, 'user_id');
    }

    /**
     * faqs
     *
     * @return HasMany
     */
    public function faqs(): HasMany
    {
        return $this->HasMany(Faqs::class);
    }

    /**
     * terms and conditions
     *
     * @return HasMany
     */
    public function terms_and_conditions(): HasMany
    {
        return $this->HasMany(TermsAndConditions::class);
    }

    /**
     * coupons
     *
     * @return BelongsToMany
     */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_usages')
            ->withPivot(['order_id', 'used_at'])
            ->withTimestamps();
    }

    /**
     * Backward-compatible alias for the coupon relation.
     *
     * @return BelongsToMany
     */
    public function coupon(): BelongsToMany
    {
        return $this->coupons();
    }

    /**
     * @return HasMany
     */
    public function couponUsages(): HasMany
    {
        return $this->hasMany(CouponUsage::class, 'user_id');
    }

    /**
     * @return HasOne
     */
    public function metrics(): HasOne
    {
        return $this->hasOne(CustomerMetrics::class, 'user_id');
    }

    /**
     * @return HasMany
     */
    public function couponClaims(): HasMany
    {
        return $this->hasMany(CouponClaim::class, 'user_id');
    }

    public function loadLastOrder()
    {
        $data = $this->orders()->whereNull('parent_id')
            ->where('order_status', OrderStatus::COMPLETED)
            ->latest()->first();
        $this->setRelation('last_order', $data);

        return $this;
    }

    /**
     * Backward-compatible method for verifying one-time passwords.
     * Delegates to the Spatie one-time-passwords consumer and returns
     * a boolean indicating whether the password was valid.
     *
     * @param string $password
     * @return bool
     */
    public function verifyOneTimePassword(string $password): bool
    {
        $result = $this->consumeOneTimePassword($password);

        return method_exists($result, 'isOk') ? $result->isOk() : false;
    }

    public function receivesBroadcastNotificationsOn($notification = null): string
    {
        if ($this->type === UserType::ADMIN->value) {
            return 'admin.notifications';
        }

        return 'users.' . $this->id;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('user-image')->useDisk('users');
    }
}