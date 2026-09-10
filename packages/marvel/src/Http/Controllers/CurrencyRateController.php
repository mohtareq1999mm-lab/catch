<?php

namespace Marvel\Http\Controllers;

use App\Exceptions\CurrencyInUseException;
use App\Http\Requests\Currency\StoreCurrencyRateRequest;
use App\Http\Requests\Currency\UpdateCurrencyRateRequest;
use App\Models\CurrencyRate;
use App\Services\Currency\CurrencyRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Http\Resources\Currency\CurrencyRateResource;
use Marvel\Traits\ApiResponse;

class CurrencyRateController extends CoreController
{
    use ApiResponse;

    private $currencyRateService;

    public function __construct(CurrencyRateService $currencyRateService)
    {
        $this->currencyRateService = $currencyRateService;
        $this->middleware('permission:' . Permission::VIEW_EXCHANGE_RATES, ['only' => ['index', 'show']]);
        $this->middleware('permission:' . Permission::CREATE_EXCHANGE_RATE, ['only' => ['store']]);
        $this->middleware('permission:' . Permission::UPDATE_EXCHANGE_RATE, ['only' => ['update']]);
        $this->middleware('permission:' . Permission::DELETE_EXCHANGE_RATE, ['only' => ['destroy']]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 15), 100));
        $currencyId = $request->has('currency_id') ? (int) $request->query('currency_id') : null;
        $effectiveDate = $request->query('effective_date');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $code = $request->query('code');

        $rates = $this->currencyRateService->list($currencyId, $effectiveDate, $dateFrom, $dateTo, $code, $limit);

        $rateData = CurrencyRateResource::collection($rates)->response()->getData(true);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => $rateData['data'] ?? [],
            'page' => $rateData['meta']['current_page'] ?? 0,
            'current_page' => $rateData['meta']['current_page'] ?? 0,
            'from' => $rateData['meta']['from'] ?? 0,
            'to' => $rateData['meta']['to'] ?? 0,
            'last_page' => $rateData['meta']['last_page'] ?? 0,
            'path' => $rateData['meta']['path'] ?? '',
            'per_page' => $rateData['meta']['per_page'] ?? 0,
            'total' => $rateData['meta']['total'] ?? 0,
            'next_page_url' => $rateData['links']['next'] ?? '',
            'prev_page_url' => $rateData['links']['prev'] ?? '',
            'last_page_url' => $rateData['links']['last'] ?? '',
            'first_page_url' => $rateData['links']['first'] ?? '',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $rate = CurrencyRate::query()->with('currency')->find($id);

        if (!$rate) {
            return $this->apiResponse(CURRENCY_RATE_NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CurrencyRateResource::make($rate));
    }

    public function store(StoreCurrencyRateRequest $request): JsonResponse
    {
        $rate = $this->currencyRateService->store($request->validated());

        return $this->apiResponse(CURRENCY_RATE_CREATED_SUCCESSFULLY, 200, true, CurrencyRateResource::make($rate->load('currency')));
    }

    public function update(UpdateCurrencyRateRequest $request, int $id): JsonResponse
    {
        $rate = CurrencyRate::query()->find($id);

        if (!$rate) {
            return $this->apiResponse(CURRENCY_RATE_NOT_FOUND, 404, false);
        }

        $rate = $this->currencyRateService->update($rate, $request->validated());

        return $this->apiResponse(CURRENCY_RATE_UPDATED_SUCCESSFULLY, 200, true, CurrencyRateResource::make($rate->load('currency')));
    }

    public function destroy(int $id): JsonResponse
    {
        $rate = CurrencyRate::query()->find($id);

        if (!$rate) {
            return $this->apiResponse(CURRENCY_RATE_NOT_FOUND, 404, false);
        }

        try {
            $this->currencyRateService->delete($rate);
        } catch (CurrencyInUseException $e) {
            return $this->apiResponse(CANNOT_DELETE_CURRENCY_IN_USE, 409, false);
        }

        return $this->apiResponse(CURRENCY_RATE_DELETED_SUCCESSFULLY, 200, true);
    }
}
