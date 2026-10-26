<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Register or update a mobile device push token for the authenticated user.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ]);

        $user = $request->user();

        $deviceToken = UserDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $data['token'],
            ],
            [
                'platform' => $data['platform'] ?? 'android',
                'device_name' => $data['device_name'] ?? null,
                'is_active' => true,
                'last_active_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Device push token registered successfully',
            'data' => [
                'id' => $deviceToken->id,
                'platform' => $deviceToken->platform,
                'is_active' => $deviceToken->is_active,
            ],
        ]);
    }

    /**
     * Deactivate a device token when user logs out.
     */
    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $user = $request->user();

        UserDeviceToken::query()
            ->where('user_id', $user->id)
            ->where('token', $data['token'])
            ->update([
                'is_active' => false,
                'last_active_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Device push token deactivated successfully',
        ]);
    }

    /**
     * Trigger a test notification to verify push delivery on user's devices.
     */
    public function testTrigger(Request $request, \App\Services\PushNotificationService $pushService): JsonResponse
    {
        $user = $request->user();
        $tokens = $user->deviceTokens()->where('is_active', true)->pluck('token')->all();

        if (empty($tokens)) {
            return response()->json([
                'success' => false,
                'message' => 'No active device tokens found for your user account. Please open the mobile app to register your device.',
                'registered_tokens_count' => 0,
            ], 422);
        }

        $title = '🔔 Weighbridge Test Alert';
        $body = 'Test push notification triggered successfully at ' . now()->format('h:i:s A') . ' for ' . $user->name;
        $data = [
            'type' => 'test_alert',
            'timestamp' => now()->toIso8601String(),
            'user_id' => $user->id,
        ];

        $pushService->sendToTokens($tokens, $title, $body, $data);

        return response()->json([
            'success' => true,
            'message' => 'Test notification dispatched to ' . count($tokens) . ' device(s).',
            'devices_notified' => count($tokens),
        ]);
    }
}

