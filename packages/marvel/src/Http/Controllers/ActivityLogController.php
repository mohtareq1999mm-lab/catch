<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Spatie\Activitylog\Models\Activity;
use Marvel\Http\Resources\ActivityLogResource;

class ActivityLogController extends CoreController
{
    public function __construct()
    {
        $this->middleware('permission:' . Permission::VIEW_ACTIVITY_LOG);
    }
    public function index(Request $request)
    {
        $query = Activity::query();

        if ($request->filled('log_name')) {
            $query->where('log_name', $request->log_name);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

       
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 15);
        $logs = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => __('activity.logs_fetched'),
            'data' => ActivityLogResource::collection($logs),
            'meta' => [
                "current_page" => $logs->currentPage(),
                "from" => $logs->firstItem(),
                "to" => $logs->lastItem(),
                "last_page" => $logs->lastPage(),
                "per_page" => $logs->perPage(),
                "total" => $logs->total(),
            ]
        ]);
    }
}
