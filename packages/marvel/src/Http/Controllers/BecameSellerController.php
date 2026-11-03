<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Address;
use Marvel\Database\Repositories\BecameSellersRepository;
use Marvel\Exceptions\MarvelException;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Commission;
use Marvel\Database\Repositories\CommissionRepository;
use Marvel\Http\Requests\BecameSellersRequest;
use Marvel\Http\Requests\CommissionRequest;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * @OA\Tag(name="BecameSeller", description="Endpoints for users to apply to become sellers")
 *
 * @OA\Schema(
 *     schema="BecameSeller",
 *     type="object",
 *     @OA\Property(property="page_options", type="object", description="Configuration for the 'Become a Seller' page"),
 *     @OA\Property(property="commissions", type="array", @OA\Items(type="object"), description="Platform commission structures")
 * )
 */
class BecameSellerController extends CoreController
{
    public $repository;
    public $commission;

    public function __construct(BecameSellersRepository $repository, CommissionRepository $commission)
    {
        $this->repository = $repository;
        $this->commission = $commission;
    }


    /**
     * @OA\Get(
     *     path="/became-seller",
     *     operationId="getBecameSellerData",
     *     tags={"BecameSeller"},
     *     summary="Get information for becoming a seller",
     *     description="Retrieve static content and commission options for the 'Become a Seller' landing page.",
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(ref="#/components/schemas/BecameSeller")
     *     )
     * )
     */
    public function index(Request $request)
    {
        // Bind the cache key to the supported locale set. `language` is
        // untrusted input (GraphQL `becameSeller(language:)` is public and
        // unauthenticated), so using it raw would let any caller create a
        // permanent rememberForever entry per arbitrary string.
        $language = $this->resolveLanguage($request->language);

        return Cache::rememberForever(
            'cached_became_seller_' . $language,
            function () use ($language) {
                return [
                    'page_options' => $this->repository->getData($language),
                    'commissions' => $this->commission->get()
                ];
            }
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param BecameSellersRequest $request
     * @return mixed
     * @throws ValidatorException
     */

    public function store(BecameSellersRequest $request)
    {
        $language = $this->resolveLanguage($request->language);

        $request->merge([
            'page_options' => [
                ...$request->page_options,
            ]
        ]);
        
        $this->commission->storeCommission($request['commissions'], $language);

        $data = $this->repository->where('language', $request->language)->first();
        if ($data) {
            
            $becomeSeller =  tap($data)->update($request->only(['page_options']));
        } else {
            $becomeSeller =  $this->repository->create(['page_options' => $request['page_options'], 'language' => $language]);
        }
        // Invalidate AFTER the write: forgetting first lets a concurrent read
        // re-populate the old payload, which then survives the write.
        $this->forgetSellerPageCache();
        return $becomeSeller;
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            return $this->repository->first();
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param BecameSellersRequest $request
     * @param int $id
     * @return JsonResponse
     * @throws ValidatorException
     */
    public function update(BecameSellersRequest $request, $id)
    {
        $settings = $this->repository->first();
        if (isset($settings->id)) {
            $becomeSeller = $this->repository->update($request->only(['page_options']), $settings->id);
        } else {
            $becomeSeller = $this->repository->create(['page_options' => $request['page_options']]);
        }

        $this->forgetSellerPageCache();

        return $becomeSeller;
    }

    /**
     * Drop the cached public page for every locale the read path can write.
     *
     * The read path clamps `language` to the supported set (falling back to
     * the default language), so this bounded set is the complete key space.
     * Entries live forever (rememberForever): a missed variant would serve a
     * stale page until the cache is flushed manually.
     */
    private function forgetSellerPageCache(): void
    {
        foreach ($this->cacheLocales() as $locale) {
            Cache::forget('cached_became_seller_' . $locale);
        }
    }

    /**
     * Locales that can own a cache entry: the supported set plus the default
     * content language (`shop.default_language`), which the read path falls
     * back to for missing/unsupported input.
     *
     * @return list<string>
     */
    private function cacheLocales(): array
    {
        return array_values(array_unique(array_merge(
            $this->supportedLocales(),
            [$this->defaultLocale()],
        )));
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        $locales = config('app.supported_locales', ['en', 'ar']);
        $locales = is_array($locales) ? $locales : [];

        $normalized = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                continue;
            }

            $locale = strtolower(trim($locale));
            if ($locale !== '') {
                $normalized[] = $locale;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function defaultLocale(): string
    {
        $default = strtolower(trim((string) config('shop.default_language', 'en')));

        return $default !== '' ? $default : 'en';
    }

    private function resolveLanguage(?string $language): string
    {
        $candidate = is_string($language) ? strtolower(trim($language)) : '';

        return in_array($candidate, $this->cacheLocales(), true)
            ? $candidate
            : $this->defaultLocale();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return array
     */
    public function destroy($id)
    {
        throw new MarvelException(ACTION_NOT_VALID);
    }
}
