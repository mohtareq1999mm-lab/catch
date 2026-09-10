<?php

namespace App\Services\Notifications;

use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('services.fcm.enabled', false);
    }

    /**
     * Send push to user's active devices.
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        $devices = UserDeviceToken::getActiveForUser($userId);

        if ($devices->isEmpty()) {
            Log::info('No active devices for user', ['user_id' => $userId]);
            return ['sent' => 0, 'failed' => 0, 'results' => []];
        }

        $sent = 0;
        $failed = 0;
        $results = [];

        foreach ($devices as $device) {
            try {
                $result = $this->send($device->token, $title, $body, $data);

                if ($result['success']) {
                    $sent++;
                    $device->touchLastUsed();
                } else {
                    $failed++;
                    if (in_array($result['error_code'] ?? '', ['invalid-registration-token', 'registration-token-not-registered'])) {
                        $device->deactivate();
                    }
                }

                $results[] = [
                    'device_id' => $device->id,
                    'platform' => $device->platform,
                    'success' => $result['success'],
                ];
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Push notification failed for device', [
                    'device_id' => $device->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
    }

    private function send(string $token, string $title, string $body, array $data = []): array
    {
        if (!$this->enabled || !config('services.fcm.credentials')) {
            Log::info('Push notifications disabled/simulated', [
                'token' => substr($token, 0, 20) . '...',
                'title' => $title,
            ]);
            return ['success' => true, 'message_id' => 'test_' . uniqid()];
        }

        // If kreait library not installed, simulate
        if (!class_exists(\Kreait\Firebase\Factory::class)) {
            return ['success' => true, 'message_id' => 'test_' . uniqid()];
        }

        try {
            $factory = (new \Kreait\Firebase\Factory)->withServiceAccount(config('services.fcm.credentials'));
            $messaging = $factory->createMessaging();
            $notification = \Kreait\Firebase\Messaging\Notification::create($title, $body);
            $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $token)
                ->withNotification($notification)
                ->withData($data);

            $result = $messaging->send($message);

            return ['success' => true, 'message_id' => $result];
        } catch (\Throwable $e) {
            $code = method_exists($e, 'getCode') ? (string) $e->getCode() : 'unknown';
            return ['success' => false, 'error' => $e->getMessage(), 'error_code' => $code];
        }
    }

    public function registerDevice(int $userId, string $token, string $platform, ?string $deviceName = null, ?string $deviceId = null): UserDeviceToken
    {
        return UserDeviceToken::updateOrCreate(
            ['user_id' => $userId, 'token' => $token, 'platform' => $platform],
            ['device_name' => $deviceName, 'device_id' => $deviceId, 'is_active' => true, 'last_used_at' => now()]
        );
    }
}
