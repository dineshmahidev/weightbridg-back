<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceToken;
use App\Models\VehicleMovement;
use App\Support\WorkflowStage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    /**
     * Map workflow stage to the permission slug required to act on it.
     */
    public const STAGE_PERMISSIONS = [
        WorkflowStage::MAIN_GATE_ENTRY => 'entries.create',
        WorkflowStage::QUALITY_CHECK => 'quality.create',
        WorkflowStage::FIRST_WEIGHMENT => 'weighments.create',
        WorkflowStage::MATERIAL_OPERATION => 'operations.create',
        WorkflowStage::SECOND_WEIGHMENT => 'weighments.create',
        WorkflowStage::FINAL_VERIFICATION => 'weighments.verify',
        WorkflowStage::EXIT_APPROVAL => 'exits.approve',
        WorkflowStage::SECURITY_GATE_EXIT => 'exits.complete',
        WorkflowStage::COMPLETED => 'dashboards.view',
    ];

    /**
     * Automatically trigger push notification to relevant staff when a vehicle enters a new stage.
     */
    public function notifyStageTransition(VehicleMovement $movement, string $stage, ?User $actor = null): void
    {
        try {
            $requiredPermission = self::STAGE_PERMISSIONS[$stage] ?? null;
            if (! $requiredPermission) {
                return;
            }

            // 1. Identify recipient users belonging to the movement's company who hold the required permission
            $recipients = $this->resolveRecipients($movement->company_id, $requiredPermission, $actor?->id);

            if ($recipients->isEmpty()) {
                Log::info("PushNotification: No matching recipients found for stage '{$stage}' at company #{$movement->company_id}");
                return;
            }

            // 2. Build title and message body tailored for this stage
            [$title, $body] = $this->buildMessage($movement, $stage);

            $payloadData = [
                'movement_id' => $movement->id,
                'movement_ref' => $movement->movement_ref,
                'vehicle_number' => $movement->vehicle_number,
                'stage' => $stage,
                'status' => $movement->status,
                'url' => "/vehicles/{$movement->id}",
            ];

            // 3. Fetch device tokens for recipients
            $tokens = UserDeviceToken::query()
                ->whereIn('user_id', $recipients->pluck('id'))
                ->where('is_active', true)
                ->pluck('token')
                ->unique()
                ->values()
                ->all();

            if (empty($tokens)) {
                Log::info("PushNotification: Stage '{$stage}' for vehicle {$movement->vehicle_number} — {$recipients->count()} staff targeted, but no active device tokens registered.");
                return;
            }

            // 4. Send via Push Gateway (Expo & FCM)
            $this->sendToTokens($tokens, $title, $body, $payloadData);

            Log::info("PushNotification: Dispatched to " . count($tokens) . " device(s) for vehicle {$movement->vehicle_number} entering '{$stage}'.");
        } catch (\Throwable $e) {
            Log::error("PushNotification error during stage transition: " . $e->getMessage(), [
                'exception' => $e,
                'movement_id' => $movement->id,
                'stage' => $stage,
            ]);
        }
    }

    /**
     * Resolve users in the company who have the permission, excluding the actor who triggered it.
     */
    public function resolveRecipients(int $companyId, string $permission, ?int $actorId = null)
    {
        return User::query()
            ->where('status', 'active')
            ->when($actorId, fn ($q) => $q->where('id', '!=', $actorId))
            ->where(function ($query) use ($companyId, $permission) {
                // Either user is Super Admin
                $query->whereHas('role', fn ($r) => $r->where('slug', 'super_admin'))
                    // Or user belongs to the company and their role has the permission
                    ->orWhere(function ($q) use ($companyId, $permission) {
                        $q->whereHas('companies', fn ($c) => $c->where('companies.id', $companyId))
                            ->whereHas('role.permissions', fn ($p) => $p->where('slug', $permission));
                    });
            })
            ->get(['id', 'name', 'email']);
    }

    /**
     * Build contextual notification title and body based on the stage.
     */
    public function buildMessage(VehicleMovement $movement, string $stage): array
    {
        $vehicle = $movement->vehicle_number;
        $ref = $movement->movement_ref;
        $material = $movement->material?->name ?? 'Freight';

        return match ($stage) {
            WorkflowStage::QUALITY_CHECK => [
                "🧪 Quality Inspection Required — {$vehicle}",
                "Vehicle {$vehicle} ({$material}) arrived at Gate 1. Sample testing required.",
            ],
            WorkflowStage::FIRST_WEIGHMENT => [
                "🚚 Vehicle Ready for 1st Weighment — {$vehicle}",
                "Vehicle {$vehicle} is waiting on the scale. Please record Initial " . ($movement->weighing_mode === 'empty_first' ? 'Tare' : 'Gross') . " Weight.",
            ],
            WorkflowStage::MATERIAL_OPERATION => [
                "📦 Ready for Loading/Unloading — {$vehicle}",
                "First weight recorded for {$vehicle} ({$material}). Vehicle proceeding to cargo bay.",
            ],
            WorkflowStage::SECOND_WEIGHMENT => [
                "⚖️ Vehicle Ready for 2nd Weighment — {$vehicle}",
                "Cargo operation completed for {$vehicle}. Returning to weighbridge for final " . ($movement->weighing_mode === 'empty_first' ? 'Gross' : 'Tare') . " Weight.",
            ],
            WorkflowStage::FINAL_VERIFICATION => [
                "🔍 Weight Verification Needed — {$vehicle}",
                "Net Weight calculated for {$vehicle} ({$movement->net_weight} kg). Supervisor verification needed.",
            ],
            WorkflowStage::EXIT_APPROVAL => [
                "📋 Gate Pass Approval Needed — {$vehicle}",
                "Weighment complete for {$vehicle} (Net: {$movement->net_weight} kg). Exit pass approval required.",
            ],
            WorkflowStage::SECURITY_GATE_EXIT => [
                "🟢 Gate Exit Cleared — {$vehicle}",
                "Exit pass approved for {$vehicle}. Security may allow vehicle outward gate clearance.",
            ],
            WorkflowStage::COMPLETED => [
                "🏁 Trip Completed — {$vehicle}",
                "Vehicle {$vehicle} ({$ref}) has departed the facility successfully.",
            ],
            default => [
                "🚚 Movement Update: {$vehicle}",
                "Vehicle {$vehicle} is now at " . WorkflowStage::label($stage) . " ({$ref}).",
            ],
        };
    }

    /**
     * Send push notification to a batch of tokens.
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        $expoTokens = [];
        $fcmTokens = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, 'ExponentPushToken[') || str_starts_with($token, 'ExpoPushToken[')) {
                $expoTokens[] = $token;
            } else {
                $fcmTokens[] = $token;
            }
        }

        // 1. Dispatch Expo tokens via official Expo Push API
        if (! empty($expoTokens)) {
            $this->dispatchExpoPush($expoTokens, $title, $body, $data);
        }

        // 2. Dispatch FCM native tokens
        if (! empty($fcmTokens)) {
            $this->dispatchFcmPush($fcmTokens, $title, $body, $data);
        }
    }

    /**
     * Send via Expo Push Notification API.
     */
    protected function dispatchExpoPush(array $tokens, string $title, string $body, array $data): void
    {
        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'priority' => 'high',
                'channelId' => 'weighbridge_alerts',
            ];
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://exp.host/--/api/v2/push/send', $messages);

            if (! $response->successful()) {
                Log::warning('Expo Push API responded with error: ' . $response->body());
            }
        } catch (\Throwable $e) {
            Log::warning('Expo Push API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Send via Firebase Cloud Messaging.
     */
    protected function dispatchFcmPush(array $tokens, string $title, string $body, array $data): void
    {
        $serverKey = config('services.fcm.server_key') ?? env('FCM_SERVER_KEY');
        if (! $serverKey) {
            Log::debug('FCM_SERVER_KEY not configured in .env. Skipping direct FCM dispatch.');
            return;
        }

        try {
            Http::timeout(8)
                ->withHeaders([
                    'Authorization' => 'key=' . $serverKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://fcm.googleapis.com/fcm/send', [
                    'registration_ids' => $tokens,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $data,
                    'priority' => 'high',
                ]);
        } catch (\Throwable $e) {
            Log::warning('FCM legacy dispatch failed: ' . $e->getMessage());
        }
    }
}
