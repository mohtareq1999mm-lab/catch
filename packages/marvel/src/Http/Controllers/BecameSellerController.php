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
    /**
     * Locales the public page can be cached in (same set CheckLangMiddleware
     * accepts — `lang` header / `language` query param are the `en|ar` codes).
     */
    private const SUPPORTED_LOCALES = ['en', 'ar'];

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
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
        return Cache::rememberForever(
            'cached_became_seller_' . $language,
            function () use ($request) {
                return [
                    'page_options' => $this->repository->getData($request->language),
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
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

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
        $this->forgetSellerPageCache($language);
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

        $this->forgetSellerPageCache($request->language);

        return $becomeSeller;
    }

    /**
     * Drop the cached public page for every locale, not only the one being
     * written: `page_options` and `commissions` are read back per language, and
     * the old code only cleared the key of the current request. Entries live
     * forever (rememberForever), so a missed variant never self-heals.
     */
    private function forgetSellerPageCache(?string $language = null): void
    {
        $locales = self::SUPPORTED_LOCALES;

        if (is_string($language) && $language !== '' && !in_array($language, $locales, true)) {
            $locales[] = $language;
        }

        foreach ($locales as $locale) {
            Cache::forget('cached_became_seller_' . $locale);
        }
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
