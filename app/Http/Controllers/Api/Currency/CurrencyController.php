<?php

namespace App\Http\Controllers\Api\Currency;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SelectCurrencyRequest;
use App\Http\Resources\Currency\CurrencyResource;
use App\Models\Currency;
use App\Services\Currency\CurrencyService;
use App\Services\Currency\UserCurrencyPreferenceService;
use App\Traits\HasCache;
use Marvel\Traits\ApiResponse;

class CurrencyController extends Controller
{
    use ApiResponse, HasCache;

    public function index()
    {
        $currencies = Currency::query()->active()->orderBy('sort_order')->orderBy('code')->get();

        $currenciesCache = $this->remember(
            FrontendResource::CURRENCIES->value,
            md5(request()->fullUrl()),
            CurrencyResource::collection($currencies),
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $currenciesCache);
    }

    public function select(SelectCurrencyRequest $request)
    {
        $currencyCode = strtoupper($request->validated()['currency_code']);

        $user = auth('sanctum')->user() ?? auth()->user();

        $preferenceService = app(UserCurrencyPreferenceService::class);

        if ($user) {
            $preferenceService->setUserPreference($user, $currencyCode);
        }

        app(CurrencyService::class)->forgetEffectiveCode();

        $currency = Currency::query()->where('code', $currencyCode)->first();

        return $this->apiResponse(
            CURRENCY_SELECTED_SUCCESSFULLY,
            200,
            true,
            new CurrencyResource($currency)
        );
    }
}
