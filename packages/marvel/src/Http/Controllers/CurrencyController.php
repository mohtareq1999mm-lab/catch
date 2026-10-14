<?php

namespace Marvel\Http\Controllers;

use App\Exceptions\CurrencyInactiveException;
use App\Exceptions\CurrencyInUseException;
use App\Exceptions\CurrencyRateNotFoundException;
use App\Exceptions\ExchangeRateProviderException;
use App\Exceptions\ExchangeRateValidationException;
use App\Enums\RateMode;
use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRateModeRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use App\Models\Currency;
use App\Services\Currency\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Http\Resources\Currency\CurrencyResource;
use Marvel\Traits\ApiResponse;

class CurrencyController extends CoreController
{
    use ApiResponse;

    private $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
        $this->middleware('permission:' . Permission::VIEW_CURRENCIES, ['only' => ['index', 'show']]);
        $this->middleware('permission:' . Permission::CREATE_CURRENCY, ['only' => ['store']]);
        $this->middleware('permission:' . Permission::UPDATE_CURRENCY, ['only' => ['update']]);
        $this->middleware('permission:' . Permission::DELETE_CURRENCY, ['only' => ['destroy']]);
        $this->middleware('permission:' . Permission::SET_BASE_CURRENCY, ['only' => ['setBase']]);
        $this->middleware('permission:' . Permission::SET_CATALOG_CURRENCY, ['only' => ['setCatalog']]);
        $this->middleware('permission:' . Permission::UPDATE_CURRENCY, ['only' => ['setRateMode']]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 15), 100));
        $search = $request->query('search');
        $code = $request->query('code');
        $isActive = $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null;
        $sortOrder = $request->has('sort_order') ? (int) $request->query('sort_order') : null;

        $currencies = Currency::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('numeric_code', 'like', "%{$search}%")
                        ->orWhere('name->en', 'like', "%{$search}%")
                        ->orWhere('name->ar', 'like', "%{$search}%")
                        ->orWhere('symbol->en', 'like', "%{$search}%")
                        ->orWhere('symbol->ar', 'like', "%{$search}%")
                        ->orWhere('country_name->en', 'like', "%{$search}%")
                        ->orWhere('country_name->ar', 'like', "%{$search}%");
                });
            })
            ->when($code, fn ($query) => $query->where('code', $code))
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->when($sortOrder !== null, fn ($query) => $query->where('sort_order', $sortOrder))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->paginate($limit);

        $currencyData = CurrencyResource::collection($currencies)->response()->getData(true);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => $currencyData['data'] ?? [],
            'page' => $currencyData['meta']['current_page'] ?? 0,
            'current_page' => $currencyData['meta']['current_page'] ?? 0,
            'from' => $currencyData['meta']['from'] ?? 0,
            'to' => $currencyData['meta']['to'] ?? 0,
            'last_page' => $currencyData['meta']['last_page'] ?? 0,
            'path' => $currencyData['meta']['path'] ?? '',
            'per_page' => $currencyData['meta']['per_page'] ?? 0,
            'total' => $currencyData['meta']['total'] ?? 0,
            'next_page_url' => $currencyData['links']['next'] ?? '',
            'prev_page_url' => $currencyData['links']['prev'] ?? '',
            'last_page_url' => $currencyData['links']['last'] ?? '',
            'first_page_url' => $currencyData['links']['first'] ?? '',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $currency = Currency::query()->withTrashed()->find($id);

        if (!$currency) {
            return $this->apiResponse(CURRENCY_NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CurrencyResource::make($currency));
    }

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        $currency = $this->currencyService->storeCurrency($request->validated());

        return $this->apiResponse(CURRENCY_CREATED_SUCCESSFULLY, 200, true, CurrencyResource::make($currency));
    }

    public function update(UpdateCurrencyRequest $request, int $id): JsonResponse
    {
        $currency = Currency::query()->withTrashed()->find($id);

        if (!$currency) {
            return $this->apiResponse(CURRENCY_NOT_FOUND, 404, false);
        }

        $currency = $this->currencyService->updateCurrency($currency, $request->validated());

        return $this->apiResponse(CURRENCY_UPDATED_SUCCESSFULLY, 200, true, CurrencyResource::make($currency));
    }

    public function destroy(int $id): JsonResponse
    {
        $currency = Currency::query()->withTrashed()->find($id);

        if (!$currency) {
            return $this->apiResponse(CURRENCY_NOT_FOUND, 404, false);
        }

        try {
            $this->currencyService->deleteCurrency($currency);
        } catch (CurrencyInUseException $e) {
            if ($e->reason === CurrencyInUseException::REASON_BASE_CURRENCY) {
                return $this->apiResponse(CANNOT_DELETE_BASE_CURRENCY, 409, false);
            }

            return $this->apiResponse(CANNOT_DELETE_CURRENCY_IN_USE, 409, false);
        }

        return $this->apiResponse(CURRENCY_DELETED_SUCCESSFULLY, 200, true);
    }

    public function setBase(int $id): JsonResponse
    {
        $currency = Currency::query()->withTrashed()->find($id);

        if (!$currency) {
            return $this->apiResponse(CURRENCY_NOT_FOUND, 404, false);
        }

        try {
            $this->currencyService->setBaseCurrency($currency);
        } catch (CurrencyInactiveException $e) {
            return $this->apiResponse(CURRENCY_INACTIVE, 422, false);
        } catch (CurrencyRateNotFoundException $e) {
            return $this->apiResponse(EXCHANGE_RATE_NOT_FOUND, 422, false);
        } catch (CurrencyInUseException $e) {
            return $this->apiResponse(CANNOT_CHANGE_BASE_CURRENCY_FINANCIAL_ORDERS_EXIST, 409, false);
        }

        return $this->apiResponse(SET_BASE_CURRENCY_SUCCESSFULLY, 200, true, CurrencyResource::make($currency));
    }
    public function setCatalog(int $id): JsonResponse
    {
        $currency = Currency::query()->withTrashed()->find($id);

        if (!$currency) {
            return $this->apiResponse(CURRENCY_NOT_FOUND, 404, false);
        }

        try {
            $this->currencyService->setCatalogCurrency($currency);
        } catch (CurrencyInactiveException $e) {
            return $this->apiResponse(CURRENCY_INACTIVE, 422, false);
        } catch (CurrencyRateNotFoundException $e) {
            return $this->apiResponse(EXCHANGE_RATE_NOT_FOUND, 422, false);
        }

        return $this->apiResponse(SET_CATALOG_CURRENCY_SUCCESSFULLY, 200, true, CurrencyResource::make($currency));
    }

    public function setRateMode(UpdateCurrencyRateModeRequest $request, int $id): JsonResponse
    {
        $currency = Currency::query()->withTrashed()->find($id);

        if (!$currency) {
            return $this->apiResponse(CURRENCY_NOT_FOUND, 404, false);
        }

        try {
            $validated = $request->validated();
            $currency = $this->currencyService->setRateMode(
                $currency,
                RateMode::from($validated['mode']),
                $validated['manual_rate'] ?? null,
            );
        } catch (CurrencyRateNotFoundException|\InvalidArgumentException|ExchangeRateProviderException|ExchangeRateValidationException $e) {
            return $this->apiResponse(EXCHANGE_RATE_NOT_FOUND, 422, false);
        }

        return $this->apiResponse(CURRENCY_UPDATED_SUCCESSFULLY, 200, true, CurrencyResource::make($currency));
    }
}
