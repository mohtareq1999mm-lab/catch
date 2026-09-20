<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsExportService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Marvel\Traits\ApiResponse;

class AnalyticsExportController extends Controller
{
    use ApiResponse;

    public function __construct(private AnalyticsExportService $exportService)
    {
        $this->middleware(['auth:sanctum']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }
        try {
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('export-analytics')) {
                return;
            }
            if (method_exists($user, 'can') && $user->can('export-analytics')) {
                return;
            }
            if (($user->type ?? null) === 'admin' || ($user->role ?? null) === 'admin') {
                if (method_exists($user, 'hasPermissionTo')) {
                    abort(403, 'Forbidden. Missing required permission: export-analytics.');
                }
                return;
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
        }

        abort(403, 'Forbidden. Missing required permission: export-analytics.');
    }

    public function exportOrders(Request $request)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after:date_from',
            'status' => 'nullable|string',
        ]);

        $dateFrom = Carbon::parse($validated['date_from']);
        $dateTo = Carbon::parse($validated['date_to']);
        $status = $validated['status'] ?? null;

        $filepath = $this->exportService->exportOrders($dateFrom, $dateTo, $status);

        return response()->download($filepath)->deleteFileAfterSend(true);
    }

    public function exportCustomerLTV(Request $request)
    {
        $this->authorizeAdmin($request);
        $filepath = $this->exportService->exportCustomerLTV();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }

    public function exportPerformance(Request $request)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after:date_from',
        ]);

        $dateFrom = Carbon::parse($validated['date_from']);
        $dateTo = Carbon::parse($validated['date_to']);

        $filepath = $this->exportService->exportPerformanceMetrics($dateFrom, $dateTo);

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}
