<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Coupon\Distribution\Discovery\AvailableCouponsService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Faqs;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * HTTP-level proof that localized REST payloads are cached per locale and
 * invalidated for every locale variant.
 *
 * Endpoint under test: GET /api/v1/faqs (admin FAQ listing). It is the
 * canonical FINAL_LOCALIZED_PAYLOAD cache in this codebase: the controller
 * stores the already-resolved FaqResource array (translated with
 * app()->getLocale()) through App\Traits\HasCache, so before the locale
 * suffix was added an `lang: en` request replayed the Arabic array until TTL.
 *
 * Requirements: MySQL (uses the project's normal test tables). The cache store
 * is whatever phpunit.xml configures (`array`, which is taggable), so these
 * tests exercise the native tag path; the tag-registry fallback for tag-less
 * stores is covered by tests/performance/hascache-selftest.php and the Redis
 * runtime audit.
 */
class HttpLocaleCacheIntegrationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $admin;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        $this->createAllTestTables();
        config(['scout.driver' => 'null']);

        // Cache isolation between tests (the store is shared for the process).
        Cache::flush();

        App::setLocale('en');

        foreach (['view-faqs', 'update-faq'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $this->admin = User::create([
            'name' => 'FAQ Admin',
            'email' => 'faq.admin@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000099',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->admin->givePermissionTo(['view-faqs', 'update-faq']);

        Sanctum::actingAs($this->admin);
    }

    /** @test */
    public function localized_payloads_are_cached_separately_per_locale(): void
    {
        $faq = $this->createFaq();

        // --- Arabic: miss, payload built in Arabic ----------------------------
        $ar = $this->getJson('/api/v1/faqs?limit=50', $this->headers('ar'));
        $ar->assertOk();
        $this->assertSame('عنوان عربي', $this->faqTitle($ar, $faq->id));

        // --- English: must build its own entry, never reuse the Arabic one ----
        $en = $this->getJson('/api/v1/faqs?limit=50', $this->headers('en'));
        $en->assertOk();
        $this->assertSame('English title', $this->faqTitle($en, $faq->id));

        // --- Change the row behind the cache layer's back ---------------------
        // The controller caches the already-resolved FaqResource array, so a
        // cache hit must keep serving the payload built for ITS locale. A shared
        // key would replay the Arabic payload to `lang: en` (the regression).
        DB::table('faqs')->where('id', $faq->id)->update([
            'faq_title' => json_encode(['en' => 'DB-mutated title', 'ar' => 'عنوان معدل']),
        ]);

        $arCached = $this->getJson('/api/v1/faqs?limit=50', $this->headers('ar'));
        $arCached->assertOk();
        $this->assertSame('عنوان عربي', $this->faqTitle($arCached, $faq->id), 'ar request stopped being a cache hit');

        $enCached = $this->getJson('/api/v1/faqs?limit=50', $this->headers('en'));
        $enCached->assertOk();
        $this->assertSame('English title', $this->faqTitle($enCached, $faq->id), 'en request reused the ar entry');
    }

    /** @test */
    public function updating_a_translation_invalidates_every_locale_variant(): void
    {
        $faq = $this->createFaq();

        // Warm both locale entries.
        $this->getJson('/api/v1/faqs?limit=50', $this->headers('ar'))->assertOk();
        $this->getJson('/api/v1/faqs?limit=50', $this->headers('en'))->assertOk();

        $update = $this->putJson("/api/v1/faqs/{$faq->id}", [
            'faq_title' => [
                'ar' => 'عنوان محدث',
                'en' => 'Updated title',
            ],
        ], $this->headers('en'));
        $update->assertOk();

        $ar = $this->getJson('/api/v1/faqs?limit=50', $this->headers('ar'));
        $ar->assertOk();
        $this->assertSame('عنوان محدث', $this->faqTitle($ar, $faq->id), 'ar variant was not invalidated');

        $en = $this->getJson('/api/v1/faqs?limit=50', $this->headers('en'));
        $en->assertOk();
        $this->assertSame('Updated title', $this->faqTitle($en, $faq->id), 'en variant was not invalidated');
    }

    /** @test */
    public function missing_locale_header_uses_the_documented_english_default(): void
    {
        $faq = $this->createFaq();

        // Poison the worker locale: the middleware must still resolve to `en`.
        App::setLocale('ar');

        $response = $this->getJson('/api/v1/faqs?limit=50', ['Accept' => 'application/json']);
        $response->assertOk();

        $this->assertSame('English title', $this->faqTitle($response, $faq->id));
    }

    /** @test */
    public function unsupported_locale_header_falls_back_to_the_application_locale(): void
    {
        $faq = $this->createFaq();

        // App::setLocale() rewrites config('app.locale'); restore the shipped
        // default (config/app.php: ar) so the fallback contract is what is tested.
        App::setLocale('en');
        config(['app.locale' => 'ar']);

        $response = $this->getJson('/api/v1/faqs?limit=50', $this->headers('fr'));
        $response->assertOk();

        $this->assertSame('عنوان عربي', $this->faqTitle($response, $faq->id));
    }

    /** @test */
    public function coupon_availability_cache_entries_are_scoped_per_user_and_locale(): void
    {
        [$userA, $userB] = $this->createCustomers();

        // The availability payload carries coupon names resolved through
        // Spatie HasTranslations; the key must therefore carry both the user
        // (personalized visibility) and the active locale. The user dimension
        // is opaque (id only), so assert on the arguments the service actually
        // hands to the cache, not on rendered output.
        $captured = [];
        Cache::spy();
        Cache::shouldReceive('remember')
            ->andReturnUsing(function (string $key) use (&$captured) {
                $captured[] = $key;

                return ['data' => [], 'meta' => []];
            });

        $service = $this->app->make(AvailableCouponsService::class);

        App::setLocale('ar');
        $service->forUser($userA);

        App::setLocale('en');
        $service->forUser($userA);

        $service->forUser($userB);

        $this->assertCount(3, $captured, 'each user/locale pair must produce its own entry');
        $this->assertSame(3, count(array_unique($captured)), 'two identities shared one coupon cache entry');

        $this->assertStringContainsString(':' . $userA->getKey() . ':', $captured[0], 'entry is not user-scoped');
        $this->assertStringContainsString(':ar:', $captured[0], 'entry is not locale-scoped');
        $this->assertStringContainsString(':en:', $captured[1], 'entry is not locale-scoped');
        $this->assertStringContainsString(':' . $userB->getKey() . ':', $captured[2], 'entry is not user-scoped');
    }

    private function createFaq(): Faqs
    {
        return Faqs::create([
            'faq_title' => [
                'en' => 'English title',
                'ar' => 'عنوان عربي',
            ],
            'faq_description' => [
                'en' => 'English description',
                'ar' => 'وصف عربي',
            ],
            'status' => 1,
        ]);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function createCustomers(): array
    {
        $make = function (string $email, string $phone): User {
            return User::create([
                'name' => 'Customer ' . $email,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make('Password123!'),
                'phone_number' => $phone,
                'is_active' => true,
                'type' => 'user',
            ]);
        };

        return [
            $make('locale.cache.a@example.com', '01000000101'),
            $make('locale.cache.b@example.com', '01000000102'),
        ];
    }

    private function headers(?string $locale): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($locale !== null) {
            $headers['lang'] = $locale;
        }

        return $headers;
    }

    private function faqTitle(TestResponse $response, int $id): ?string
    {
        // Envelope: {status, message, success, data: {data: [...rows...], page...}}
        foreach ($response->json('data.data') ?? [] as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row['faq_title'] ?? null;
            }
        }

        return null;
    }
}
