<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Canonical 422 envelope regression: {message, status:false, errors:{field:[messages]}}.
 *
 * Locale is selected ONLY by the `lang` request header (en default; en|ar allowed)
 * via CheckLangMiddleware. Expected strings below were captured from real
 * execution against resources/lang/{en,ar}/validation.php (never guessed).
 *
 * Setup patterns reused from StaticPageValidationTest (canonical envelope helper),
 * CategoryValidationTest / CartPhase02FixesTest (RefreshDatabase + Sanctum + factory).
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private const GENERAL_PREFIX = '/api/v1/general';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Validation tests must not flake on rate limits (login/cart/admin limiters).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    // -------------------------------------------------------------------------
    // Helpers (StaticPageValidationTest pattern, extended with exact-text asserts)
    // -------------------------------------------------------------------------

    /**
     * @param array<string,string> $expected field => exact expected first message
     */
    private function assertCanonicalEnvelope(TestResponse $response, array $expected, ?string $expectedMessage = null): array
    {
        $response->assertStatus(422);
        $json = $response->json();

        $this->assertArrayHasKey('message', $json, 'Expected canonical envelope message key');
        $this->assertArrayHasKey('status', $json, 'Expected canonical envelope status key');
        $this->assertFalse($json['status'], 'Expected canonical envelope status=false');
        $this->assertArrayHasKey('errors', $json, 'Expected canonical envelope errors key');
        $this->assertIsArray($json['errors']);

        foreach ($expected as $field => $message) {
            $this->assertArrayHasKey($field, $json['errors'], "Expected validation error key '{$field}'");
            $this->assertNotEmpty($json['errors'][$field]);
            $this->assertSame($message, $json['errors'][$field][0], "Exact message mismatch for '{$field}'");
        }

        if ($expectedMessage !== null) {
            $this->assertSame($expectedMessage, $json['message'], 'Exact envelope message mismatch');
        }

        return $json;
    }

    private function customer(): User
    {
        return User::factory()->create(['type' => 'user']);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Validation Product',
            'slug' => 'validation-product-'.Str::uuid(),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);
    }

    private function adminWithProductPermissions(): User
    {
        Permission::firstOrCreate(['name' => 'create-product', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view-products', 'guard_name' => 'api']);
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->givePermissionTo(['create-product', 'view-products']);

        return $admin;
    }

    private function packer(): User
    {
        Permission::firstOrCreate(['name' => 'packing-execute', 'guard_name' => 'api']);
        $packer = User::factory()->create(['type' => 'staff']);
        $packer->givePermissionTo('packing-execute');

        return $packer;
    }

    // -------------------------------------------------------------------------
    // 1. POST register missing first_name + invalid email (UserCreateRequest)
    // -------------------------------------------------------------------------

    public function test_register_missing_first_name_and_invalid_email_en(): void
    {
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::PREFIX.'/register', [
            'last_name' => 'User',
            'email' => 'not-an-email',
            'phone_number' => '01012345671',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'policy' => '1',
        ]);

        $this->assertCanonicalEnvelope($response, [
            'first_name' => 'The first name field is required.',
            'email' => 'The email must be a valid email address.',
        ], 'The first name field is required. (and 1 more error)');
    }

    public function test_register_missing_first_name_and_invalid_email_ar(): void
    {
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/register', [
            'last_name' => 'User',
            'email' => 'not-an-email',
            'phone_number' => '01012345672',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'policy' => '1',
        ]);

        $this->assertCanonicalEnvelope($response, [
            'first_name' => 'الاسم الأول مطلوب.',
            'email' => 'يجب أن يكون البريد الالكتروني عنوان بريد إلكتروني صحيح البُنية',
        ], 'الاسم الأول مطلوب. (and 1 more error)');
    }

    // -------------------------------------------------------------------------
    // 2. POST token/login missing password (UserAuthEmailAndPasswordRequest)
    // -------------------------------------------------------------------------

    public function test_token_missing_password_en(): void
    {
        User::create([
            'name' => 'Token User',
            'email' => 'token@example.com',
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000009901',
            'type' => 'user',
            'is_active' => true,
        ]);

        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::PREFIX.'/token', [
            'email' => 'token@example.com',
        ]);

        $this->assertCanonicalEnvelope($response, [
            'password' => 'The password field is required.',
        ], 'The password field is required.');
    }

    public function test_token_missing_password_ar(): void
    {
        User::create([
            'name' => 'Token User',
            'email' => 'token@example.com',
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000009901',
            'type' => 'user',
            'is_active' => true,
        ]);

        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/token', [
            'email' => 'token@example.com',
        ]);

        $this->assertCanonicalEnvelope($response, [
            'password' => 'كلمة السر مطلوب.',
        ], 'كلمة السر مطلوب.');
    }

    // -------------------------------------------------------------------------
    // 3. POST address missing title (AddressRequest, nested address.* handling)
    // -------------------------------------------------------------------------

    public function test_address_missing_title_en(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::PREFIX.'/address', [
            'address' => [
                'zip' => '12345',
                'city' => 'Cairo',
                'state' => 'Cairo',
                'country' => 'EG',
                'street_address' => '123 Street',
            ],
        ]);

        $this->assertCanonicalEnvelope($response, [
            'title' => 'The title field is required.',
        ], 'The title field is required.');
    }

    public function test_address_missing_title_ar(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/address', [
            'address' => [
                'zip' => '12345',
                'city' => 'Cairo',
                'state' => 'Cairo',
                'country' => 'EG',
                'street_address' => '123 Street',
            ],
        ]);

        $this->assertCanonicalEnvelope($response, [
            'title' => 'العنوان مطلوب.',
        ], 'العنوان مطلوب.');
    }

    // -------------------------------------------------------------------------
    // 4. POST cart store (CartCreateRequest)
    // -------------------------------------------------------------------------

    public function test_cart_missing_product_id_en(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::PREFIX.'/cart', [
            'item' => ['quantity' => 1, 'shipping_method' => 'scheduled'],
        ]);

        $this->assertCanonicalEnvelope($response, [
            'item.product_id' => 'The item.product id field is required.',
        ], 'The item.product id field is required.');
    }

    public function test_cart_missing_product_id_ar(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/cart', [
            'item' => ['quantity' => 1, 'shipping_method' => 'scheduled'],
        ]);

        $this->assertCanonicalEnvelope($response, [
            'item.product_id' => 'item.product id مطلوب.',
        ], 'item.product id مطلوب.');
    }

    public function test_cart_quantity_zero_en(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::PREFIX.'/cart', [
            'item' => ['product_id' => $product->id, 'quantity' => 0, 'shipping_method' => 'scheduled'],
        ]);

        $this->assertCanonicalEnvelope($response, [
            'item.quantity' => 'The item.quantity must be at least 1.',
        ], 'The item.quantity must be at least 1.');
    }

    public function test_cart_quantity_zero_ar(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/cart', [
            'item' => ['product_id' => $product->id, 'quantity' => 0, 'shipping_method' => 'scheduled'],
        ]);

        $this->assertCanonicalEnvelope($response, [
            'item.quantity' => 'يجب أن تكون قيمة item.quantity مساوية أو أكبر من 1.',
        ], 'يجب أن تكون قيمة item.quantity مساوية أو أكبر من 1.');
    }

    // -------------------------------------------------------------------------
    // 5. POST checkout missing name (OrderCreateRequest, simple missing-field)
    // -------------------------------------------------------------------------

    public function test_checkout_missing_name_en(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::GENERAL_PREFIX.'/checkout', []);

        $this->assertCanonicalEnvelope($response, [
            'name' => 'The name field is required.',
            'user_phone' => 'The user phone number field is required.',
        ], 'The name field is required. (and 1 more error)');
    }

    public function test_checkout_missing_name_ar(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::GENERAL_PREFIX.'/checkout', []);

        $this->assertCanonicalEnvelope($response, [
            'name' => 'الاسم مطلوب.',
            'user_phone' => 'رقم هاتف المستخدم مطلوب.',
        ], 'الاسم مطلوب. (and 1 more error)');
    }

    // -------------------------------------------------------------------------
    // 6. POST coupons/apply missing code (inline $request->validate)
    // -------------------------------------------------------------------------

    public function test_coupons_apply_missing_code_en(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::GENERAL_PREFIX.'/coupons/apply', []);

        $this->assertCanonicalEnvelope($response, [
            'code' => 'The code field is required.',
        ], 'The code field is required.');
    }

    public function test_coupons_apply_missing_code_ar(): void
    {
        Sanctum::actingAs($this->customer());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::GENERAL_PREFIX.'/coupons/apply', []);

        $this->assertCanonicalEnvelope($response, [
            'code' => 'الرمز مطلوب.',
        ], 'الرمز مطلوب.');
    }

    // -------------------------------------------------------------------------
    // 7. Admin POST products missing name (ProductCreateRequest)
    // -------------------------------------------------------------------------

    public function test_admin_products_missing_name_en(): void
    {
        Sanctum::actingAs($this->adminWithProductPermissions());
        $response = $this->withHeaders(['lang' => 'en'])->postJson(self::PREFIX.'/products', []);

        $json = $this->assertCanonicalEnvelope($response, [
            'name' => 'The name field is required.',
        ], 'The name field is required. (and 7 more errors)');

        // Full missing-payload shape is part of the contract (frozen rules).
        foreach (['description', 'product_type', 'categories', 'images', 'in_stock', 'has_discount', 'has_flash_sale'] as $key) {
            $this->assertArrayHasKey($key, $json['errors'], "Expected validation error key '{$key}'");
        }
    }

    public function test_admin_products_missing_name_ar(): void
    {
        Sanctum::actingAs($this->adminWithProductPermissions());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/products', []);

        $json = $this->assertCanonicalEnvelope($response, [
            'name' => 'الاسم مطلوب.',
        ], 'الاسم مطلوب. (and 7 more errors)');

        foreach (['description', 'product_type', 'categories', 'images', 'in_stock', 'has_discount', 'has_flash_sale'] as $key) {
            $this->assertArrayHasKey($key, $json['errors'], "Expected validation error key '{$key}'");
        }
        $this->assertSame('الوصف مطلوب.', $json['errors']['description'][0]);
    }

    // -------------------------------------------------------------------------
    // 8. WMS POST packages/{id}/seal invalid weight (SealPackageRequest gt:0)
    // -------------------------------------------------------------------------

    public function test_wms_seal_weight_zero_en(): void
    {
        Sanctum::actingAs($this->packer());
        $response = $this->withHeaders(['lang' => 'en'])->postJson('/api/v1/admin/packages/1/seal', ['weight' => 0]);

        $this->assertCanonicalEnvelope($response, [
            'weight' => 'The weight must be greater than 0.',
        ], 'The weight must be greater than 0.');
    }

    public function test_wms_seal_weight_zero_ar(): void
    {
        Sanctum::actingAs($this->packer());
        $response = $this->withHeaders(['lang' => 'ar'])->postJson('/api/v1/admin/packages/1/seal', ['weight' => 0]);

        // ar gt.numeric now interpolates :value correctly (placeholder fix).
        $this->assertCanonicalEnvelope($response, [
            'weight' => 'يجب أن تكون قيمة weight أكبر من 0.',
        ], 'يجب أن تكون قيمة weight أكبر من 0.');
    }

    // -------------------------------------------------------------------------
    // 3. Locale-switch: en -> ar -> en, no leakage, identical structure
    // -------------------------------------------------------------------------

    public function test_locale_switch_en_ar_en_has_no_leakage(): void
    {
        Sanctum::actingAs($this->customer());

        $en1 = $this->withHeaders(['lang' => 'en'])->postJson(self::GENERAL_PREFIX.'/coupons/apply', []);
        $jsonEn1 = $this->assertCanonicalEnvelope($en1, [
            'code' => 'The code field is required.',
        ], 'The code field is required.');

        Sanctum::actingAs($this->customer());
        $ar = $this->withHeaders(['lang' => 'ar'])->postJson(self::GENERAL_PREFIX.'/coupons/apply', []);
        $jsonAr = $this->assertCanonicalEnvelope($ar, [
            'code' => 'الرمز مطلوب.',
        ], 'الرمز مطلوب.');

        Sanctum::actingAs($this->customer());
        $en2 = $this->withHeaders(['lang' => 'en'])->postJson(self::GENERAL_PREFIX.'/coupons/apply', []);
        $jsonEn2 = $this->assertCanonicalEnvelope($en2, [
            'code' => 'The code field is required.',
        ], 'The code field is required.');

        // Identical structure: same keys, only text differs.
        $this->assertSame(array_keys($jsonEn1['errors']), array_keys($jsonAr['errors']));
        $this->assertSame(array_keys($jsonEn1['errors']), array_keys($jsonEn2['errors']));
        $this->assertSame($jsonEn1['errors'], $jsonEn2['errors']);
        $this->assertSame($jsonEn1['message'], $jsonEn2['message']);
        $this->assertNotSame($jsonEn1['errors']['code'][0], $jsonAr['errors']['code'][0]);

        // No leakage: EN repeat must not contain Arabic text.
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $jsonEn2['errors']['code'][0]);
    }

    // -------------------------------------------------------------------------
    // 4. Mixed-language scan: ar failures match Arabic file, no key leakage
    // -------------------------------------------------------------------------

    public function test_ar_failures_contain_no_raw_key_leakage(): void
    {
        $product = $this->product();

        $cases = [
            'register' => fn () => $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/register', [
                'last_name' => 'User',
                'email' => 'not-an-email',
                'phone_number' => '01012345673',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'policy' => '1',
            ]),
            'coupons_apply' => function () {
                Sanctum::actingAs($this->customer());

                return $this->withHeaders(['lang' => 'ar'])->postJson(self::GENERAL_PREFIX.'/coupons/apply', []);
            },
            'checkout' => function () {
                Sanctum::actingAs($this->customer());

                return $this->withHeaders(['lang' => 'ar'])->postJson(self::GENERAL_PREFIX.'/checkout', []);
            },
            'seal' => function () {
                Sanctum::actingAs($this->packer());

                return $this->withHeaders(['lang' => 'ar'])->postJson('/api/v1/admin/packages/1/seal', ['weight' => 0]);
            },
        ];

        // product_id case needs the product id bound now.
        $cases['cart_product'] = function () {
            Sanctum::actingAs($this->customer());

            return $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/cart', [
                'item' => ['quantity' => 1, 'shipping_method' => 'scheduled'],
            ]);
        };
        $cases['cart_qty'] = function () use ($product) {
            Sanctum::actingAs($this->customer());

            return $this->withHeaders(['lang' => 'ar'])->postJson(self::PREFIX.'/cart', [
                'item' => ['product_id' => $product->id, 'quantity' => 0, 'shipping_method' => 'scheduled'],
            ]);
        };

        foreach ($cases as $label => $call) {
            $response = $call();
            $response->assertStatus(422);
            $raw = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

            // No raw translation-key leakage.
            $this->assertStringNotContainsString('validation.', $raw, "Key leakage in '{$label}'");
            $this->assertStringNotContainsString('message.', $raw, "Key leakage in '{$label}'");

            // Every error message carries Arabic script (template is Arabic even
            // when the attribute token itself stays Latin, e.g. "weight").
            foreach ($response->json('errors') as $field => $messages) {
                foreach ((array) $messages as $text) {
                    $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, "Expected Arabic text for '{$label}'.'{$field}'");
                }
            }
        }
    }
}
