<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use App\Models\UserDeviceToken;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class NotificationPreferencesController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $preferences = UserNotificationPreference::forUser($request->user()->id);

        return $this->apiResponse('Preferences retrieved.', 200, true, $preferences);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'email_enabled' => 'sometimes|boolean',
            'sms_enabled' => 'sometimes|boolean',
            'push_enabled' => 'sometimes|boolean',
            'websocket_enabled' => 'sometimes|boolean',
            'event_preferences' => 'sometimes|array',
            'quiet_hours_start' => 'sometimes|nullable|date_format:H:i',
            'quiet_hours_end' => 'sometimes|nullable|date_format:H:i',
            'respect_quiet_hours' => 'sometimes|boolean',
            'notification_language' => 'sometimes|in:en,ar',
        ]);

        $preferences = UserNotificationPreference::forUser($request->user()->id);
        $preferences->update($validated);

        return $this->apiResponse('Notification preferences updated', 200, true, $preferences->fresh());
    }

    public function registerDevice(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:500',
            'platform' => 'required|in:ios,android,web',
            'device_name' => 'nullable|string|max:255',
            'device_id' => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:50',
            'os_version' => 'nullable|string|max:50',
        ]);

        $device = UserDeviceToken::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'token' => $validated['token'],
                'platform' => $validated['platform'],
            ],
            [
                'device_name' => $validated['device_name'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );

        return $this->apiResponse('Device registered successfully', 200, true, $device);
    }

    public function unregisterDevice(Request $request, int $deviceId)
    {
        $device = UserDeviceToken::where('user_id', $request->user()->id)
            ->where('id', $deviceId)
            ->first();

        if (!$device) {
            return $this->apiResponse('Device not found.', 404, false);
        }

        $device->deactivate();

        return $this->apiResponse('Device unregistered', 200, true);
    }

    public function notificationHistory(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);

        $notifications = \App\Models\OrderNotification::where('user_id', $request->user()->id)
            ->with('order:id,order_number,status')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->apiResponse('Notification history retrieved.', 200, true, $notifications);
    }
}
