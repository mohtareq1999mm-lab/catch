<?php

namespace Tests\Feature\Localization;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Repositories\BecameSellersRepository;
use Marvel\Database\Repositories\CommissionRepository;
use Marvel\Http\Controllers\BecameSellerController;
use Marvel\Http\Requests\BecameSellersRequest;
use Mockery;
use Tests\TestCase;

/**
 * The become-seller page is a public, unauthenticated GraphQL field
 * (`becameSeller(language: String)`) backed by a rememberForever cache.
 *
 * Before the fix the raw `language` argument was concatenated into the cache
 * key, so any anonymous caller could create one permanent entry per arbitrary
 * string, and a write only retired the currently requested language. These
 * tests pin the corrected contract: the key space is bounded to the supported
 * locale set, locales never share an entry, and any write retires all of them.
 *
 * The repositories are mocked because `became_sellers`/`commissions` are
 * imported from demo SQL, not created by the migrations this suite runs.
 */
class BecameSellerCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.supported_locales' => ['en', 'ar'],
            'shop.default_language' => 'en',
        ]);

        Cache::flush();
    }

    /** @test */
    public function arbitrary_language_values_cannot_create_cache_entries(): void
    {
        $pageByLanguage = [];
        $getCalls = [];
        $controller = $this->controller($pageByLanguage, $getCalls);

        $captured = [];
        Cache::spy();
        Cache::shouldReceive('rememberForever')
            ->andReturnUsing(function (string $key, callable $callback) use (&$captured) {
                $captured[] = $key;

                return $callback();
            });

        // Arbitrary strings, mixed case and surrounding whitespace must all
        // resolve to the bounded set. 'en' is the default content language, so
        // unknown values fall back to it.
        foreach (['en', 'ar', 'zz1', 'zz2', 'fr', 'ar ', 'EN'] as $language) {
            $controller->index(Request::create('/graphql', 'POST', ['language' => $language]));
        }

        $unique = array_values(array_unique($captured));
        sort($unique);

        $this->assertSame(
            ['cached_became_seller_ar', 'cached_became_seller_en'],
            $unique,
            'unsupported language values must not create their own cache entries',
        );

        $this->assertSame(
            [],
            array_values(array_diff($getCalls, ['en', 'ar'])),
            'unsupported language values must not be used to read page content',
        );
    }

    /** @test */
    public function english_and_arabic_pages_are_cached_independently(): void
    {
        $pageByLanguage = [
            'en' => ['page' => 'OLD-EN'],
            'ar' => ['page' => 'OLD-AR'],
        ];
        $getCalls = [];
        $controller = $this->controller($pageByLanguage, $getCalls);

        $this->assertSame('OLD-EN', $controller->index($this->pageRequest('en'))['page_options']['page']);
        $this->assertSame('OLD-AR', $controller->index($this->pageRequest('ar'))['page_options']['page']);

        // Change the source behind the cache layer: cached locale entries must
        // keep serving the payload built for their own locale.
        $pageByLanguage['en']['page'] = 'MUTATED-EN';
        $pageByLanguage['ar']['page'] = 'MUTATED-AR';

        $this->assertSame('OLD-EN', $controller->index($this->pageRequest('en'))['page_options']['page']);
        $this->assertSame('OLD-AR', $controller->index($this->pageRequest('ar'))['page_options']['page']);
    }

    /** @test */
    public function writing_the_page_retires_every_supported_locale_entry(): void
    {
        $pageByLanguage = [
            'en' => ['page' => 'OLD-EN'],
            'ar' => ['page' => 'OLD-AR'],
        ];
        $getCalls = [];
        $controller = $this->controller($pageByLanguage, $getCalls);

        // Warm both locale variants.
        $controller->index($this->pageRequest('en'));
        $controller->index($this->pageRequest('ar'));

        $pageByLanguage['en']['page'] = 'NEW-EN';
        $pageByLanguage['ar']['page'] = 'NEW-AR';

        $controller->store($this->storeRequest('en'));

        $this->assertSame(
            'NEW-EN',
            $controller->index($this->pageRequest('en'))['page_options']['page'],
            'the written locale kept serving the stale payload',
        );
        $this->assertSame(
            'NEW-AR',
            $controller->index($this->pageRequest('ar'))['page_options']['page'],
            'a non-written locale kept serving the stale payload',
        );
    }

    /** @test */
    public function store_rejects_languages_outside_the_supported_set(): void
    {
        $this->assertSame([], $this->languageErrors('en'));
        $this->assertSame([], $this->languageErrors('ar'));
        $this->assertSame([], $this->languageErrors(null));

        $this->assertNotSame([], $this->languageErrors('zz'));
        $this->assertNotSame([], $this->languageErrors('fr'));
    }

    /**
     * @param  array<string, array<string, string>>  $pageByLanguage
     * @param  list<string>  $getCalls
     */
    private function controller(array &$pageByLanguage, array &$getCalls): BecameSellerController
    {
        $repository = Mockery::mock(BecameSellersRepository::class);
        $repository->shouldReceive('getData')
            ->andReturnUsing(function (string $language) use (&$pageByLanguage, &$getCalls) {
                $getCalls[] = $language;

                return $pageByLanguage[$language] ?? ['page' => 'fallback'];
            });
        $repository->shouldReceive('where')->andReturnSelf();
        $repository->shouldReceive('first')->andReturn(null);
        $repository->shouldReceive('create')->andReturnUsing(fn (array $attributes) => $attributes);

        $commission = Mockery::mock(CommissionRepository::class);
        $commission->shouldReceive('get')->andReturn([]);
        $commission->shouldReceive('storeCommission')->andReturnNull();

        return new BecameSellerController($repository, $commission);
    }

    private function pageRequest(string $language): Request
    {
        return Request::create('/graphql', 'POST', ['language' => $language]);
    }

    private function storeRequest(?string $language): BecameSellersRequest
    {
        return BecameSellersRequest::create('/graphql', 'POST', [
            'language' => $language,
            'page_options' => ['faqTitle' => 'FAQ'],
            'commissions' => [],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function languageErrors(?string $language): array
    {
        $validator = Validator::make(
            [
                'language' => $language,
                'page_options' => ['faqTitle' => 'FAQ'],
                'commission' => [],
            ],
            (new BecameSellersRequest())->rules(),
        );

        return $validator->errors()->get('language');
    }
}
