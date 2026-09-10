<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Stub controller for karim007/laravel-bkash-tokenize routes.
 *
 * The vendor package `karim007/laravel-bkash-tokenize` registers
 * `GET /bkash/*` routes pointing to `App\Http\Controllers\BkashTokenizePaymentController`.
 * This stub ensures `php artisan route:list`, `config:cache`, and `schedule:run`
 * do not throw ReflectionException when the controller is missing.
 *
 * If bKash Tokenize is actually used, replace these stubs with the real
 * implementation from the package documentation.
 */
class BkashTokenizePaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'bKash payment not implemented'], 501);
    }

    public function createPayment(Request $request): JsonResponse
    {
        return response()->json(['message' => 'bKash createPayment not implemented'], 501);
    }

    public function callBack(Request $request): JsonResponse
    {
        return response()->json(['message' => 'bKash callback not implemented'], 501);
    }

    public function searchTnx(string $trxID): JsonResponse
    {
        return response()->json(['message' => 'bKash search not implemented', 'trxID' => $trxID], 501);
    }

    public function refund(Request $request): JsonResponse
    {
        return response()->json(['message' => 'bKash refund not implemented'], 501);
    }

    public function refundStatus(Request $request): JsonResponse
    {
        return response()->json(['message' => 'bKash refundStatus not implemented'], 501);
    }
}
