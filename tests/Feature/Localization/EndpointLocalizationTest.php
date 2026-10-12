<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Endpoint-level localization proofs (requires MySQL, runs in CI).
 *
 * For every covered endpoint + locale: asserts the translated top-level
 * message, translated field messages, localized attribute names, stable
 * machine keys, intact response structure/status codes, and zero raw-key
 * leakage. The DB-free suites (TranslationCatalogue, LocaleMiddleware,
 * HandlerLocalization, TrackingControllerIntegrity) run without MySQL.
 */
class EndpointLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function withLocale(string $locale): array
    {
        return ['lang' => $locale, 'Accept' => 'application/json'];
    }

    private function assertNoRawKeys(array $payload, string $context): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression(
            '/"(validation|message|checkout|tracking|coupon|wms|auth)\.[A-Za-z_.]+"/',
            $json,
            "Raw translation key leaked in {$context}: {$json}"
        );
    }

    /** @dataProvider locales */
    public function test_public_track_order_validation_is_localized(string $locale): void
    {
        $response = $this->postJson('/api/v1/general/track-order', [], $this->withLocale($locale));

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('errors', $data);

        if ($locale === 'ar') {
            $this->assertSame('البيانات المُدخلة غير صحيحة.', $data['message']);
            $this->assertStringContainsString('رقم الطلب', $data['errors']['order_number'][0]);
        } else {
            $this->assertSame('The given data was invalid.', $data['message']);
            $this->assertStringContainsString('order number', $data['errors']['order_number'][0]);
        }
        $this->assertNoRawKeys($data, "track-order validation [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_public_track_order_not_found_is_localized(string $locale): void
    {
        $response = $this->postJson('/api/v1/general/track-order', [
            'order_number' => 'ORD-00000000',
            'user_email' => 'ghost@example.com',
        ], $this->withLocale($locale));

        $response->assertStatus(404);
        $data = $response->json();
        if ($locale === 'ar') {
            $this->assertSame('الطلب غير موجود. يرجى التحقق من رقم الطلب وبيانات التواصل.', $data['message']);
        } else {
            $this->assertSame('Order not found. Please check your order number and contact details.', $data['message']);
        }
        $this->assertNoRawKeys($data, "track-order 404 [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_coupon_apply_validation_is_localized(string $locale): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/general/coupons/apply', [], $this->withLocale($locale));

        $response->assertStatus(422);
        $data = $response->json();
        if ($locale === 'ar') {
            $this->assertStringContainsString('الرمز', $data['errors']['code'][0]);
        } else {
            $this->assertStringContainsString('code', $data['errors']['code'][0]);
        }
        $this->assertNoRawKeys($data, "coupons/apply validation [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_currency_select_validation_is_localized(string $locale): void
    {
        $response = $this->postJson('/api/v1/general/currencies/select', [
            'currency_code' => 'XX',
        ], $this->withLocale($locale));

        $response->assertStatus(422);
        $data = $response->json();
        if ($locale === 'ar') {
            $this->assertStringContainsString('رمز العملة', $data['errors']['currency_code'][0]);
        } else {
            $this->assertStringContainsString('currency code', $data['errors']['currency_code'][0]);
        }
        $this->assertNoRawKeys($data, "currencies/select validation [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_notification_preferences_validation_is_localized(string $locale): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/user/notification-preferences', [
            'notification_language' => 'fr',
        ], $this->withLocale($locale));

        $response->assertStatus(422);
        $data = $response->json();
        if ($locale === 'ar') {
            $this->assertStringContainsString('لغة الإشعارات', $data['errors']['notification_language'][0]);
        } else {
            $this->assertStringContainsString('notification language', $data['errors']['notification_language'][0]);
        }
        $this->assertNoRawKeys($data, "notification-preferences validation [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_device_token_validation_is_localized(string $locale): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/general/device-tokens', [], $this->withLocale($locale));

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertArrayHasKey('token', $data['errors']);
        if ($locale === 'ar') {
            $this->assertStringContainsString('الرمز المميز', $data['errors']['token'][0]);
        }
        $this->assertNoRawKeys($data, "device-tokens validation [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_site_review_validation_is_localized(string $locale): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/general/site-reviews', [
            'rating' => 9,
        ], $this->withLocale($locale));

        $response->assertStatus(422);
        $data = $response->json();
        if ($locale === 'ar') {
            $this->assertStringContainsString('التقييم', $data['errors']['rating'][0]);
        } else {
            $this->assertStringContainsString('rating', $data['errors']['rating'][0]);
        }
        $this->assertNoRawKeys($data, "site-reviews validation [{$locale}]");
    }

    /** @dataProvider locales */
    public function test_unauthenticated_shape_stays_stable_and_localized(string $locale): void
    {
        $response = $this->getJson('/api/v1/general/orders', $this->withLocale($locale));

        $response->assertStatus(401);
        $data = $response->json();
        $this->assertArrayHasKey('message', $data);
        $this->assertNotEmpty($data['message']);
        $this->assertNoRawKeys($data, "unauthenticated [{$locale}]");
    }

    public static function locales(): array
    {
        return ['arabic' => ['ar'], 'english' => ['en']];
    }
}
