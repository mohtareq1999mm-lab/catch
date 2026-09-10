<?php

namespace Marvel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Marvel\Traits\ApiResponse;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = $request->input('per_page', 15);
        $notifications = $user->notifications()
            ->latest()
            ->paginate($perPage);

        $data = $notifications->map(function (DatabaseNotification $notification) {
            return $this->formatNotification($notification);
        });

        return $this->apiResponse(NOTIFICATIONS_FETCHED, 200, true, [
            'data' => $data,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
            ],
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->unreadNotifications()
            ->latest()
            ->get();

        $data = $notifications->map(function (DatabaseNotification $notification) {
            return $this->formatNotification($notification);
        });

        return $this->apiResponse(UNREAD_NOTIFICATIONS_FETCHED, 200, true, [
            'data' => $data,
            'meta' => [
                'total' => $notifications->count(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $user = request()->user();

        $notification = $user->notifications()->findOrFail($id);

        return $this->apiResponse(NOTIFICATION_FETCHED, 200, true, $this->formatNotification($notification));
    }

    public function markAsRead(string $id): JsonResponse
    {
        $user = request()->user();

        $notification = $user->notifications()->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $this->apiResponse(NOTIFICATION_MARKED_READ, 200, true, $this->formatNotification($notification->fresh()));
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = $user->unreadNotifications()->count();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return $this->apiResponse(ALL_NOTIFICATIONS_MARKED_READ, 200, true, [
            'marked_count' => $count,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $user = request()->user();

        $notification = $user->notifications()->findOrFail($id);
        $notification->delete();

        return $this->apiResponse(NOTIFICATION_DELETED, 200, true);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = $user->notifications()->count();

        $user->notifications()->delete();

        return $this->apiResponse(ALL_NOTIFICATIONS_DELETED, 200, true, [
            'deleted_count' => $count,
        ]);
    }

    private function formatNotification(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        $locale = app()->getLocale();

        $resolve = function ($value) use ($locale) {
            if (is_array($value)) {
                return $value[$locale] ?? $value['en'] ?? '';
            }

            return (string) $value;
        };

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $resolve($data['title'] ?? ''),
            'message' => $resolve($data['message'] ?? ''),
            'icon' => $data['icon'] ?? 'bell',
            'resource_type' => $data['resource_type'] ?? '',
            'resource_id' => $data['resource_id'] ?? null,
            'action_url' => $data['action_url'] ?? '',
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
        ];
    }
}
