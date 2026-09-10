<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Marvel\Traits\ApiResponse;

class DeviceTokenController extends Controller
{
    use ApiResponse;

    private const CLIENTS = ['client_a', 'client_b'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'client' => ['required', 'string', Rule::in(self::CLIENTS)],
            'platform' => ['sometimes', 'string', Rule::in(['android', 'ios'])],
        ]);

        // Token is globally unique: re-registration reassigns it to this user.
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'client' => $data['client'],
                'platform' => $data['platform'] ?? 'android',
                'last_used_at' => now(),
            ]
        );

        return $this->apiResponse(DEVICE_TOKEN_REGISTERED, 200, true, ['uuid' => $device->uuid]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return $this->apiResponse(DEVICE_TOKEN_REMOVED, 200, true);
    }
}