<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VehicleMovement;
use App\Services\PushNotificationService;
use App\Support\WorkflowStage;
use Illuminate\Console\Command;

class TestPushNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'push:test {--movement= : Vehicle movement ID} {--stage=first_weighment : Target workflow stage} {--vehicle=TN 09 LM 9999 : Vehicle registration number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger a test push notification for workflow transitions (Security Gate, Weighbridge, Quality, Operations, Exit)';

    /**
     * Execute the console command.
     */
    public function handle(PushNotificationService $pushService): int
    {
        $movementId = $this->option('movement');
        $stage = $this->option('stage');

        $this->info("=================================================");
        $this->info("   WEIGHBRIDGE PUSH NOTIFICATION TEST RUNNER   ");
        $this->info("=================================================");

        // 1. Locate or select a vehicle movement
        if ($movementId) {
            $movement = VehicleMovement::with(['company', 'material'])->find($movementId);
        } else {
            $movement = VehicleMovement::with(['company', 'material'])->latest()->first();
        }

        if (!$movement) {
            $this->error("No vehicle movement found. Creating a temporary simulated movement...");
            $company = \App\Models\Company::first();
            $movement = new VehicleMovement([
                'id' => 999,
                'movement_ref' => 'TEST-' . date('Ymd-His'),
                'company_id' => $company ? $company->id : 1,
                'vehicle_number' => $this->option('vehicle') ?? 'TN 09 LM 9999',
                'status' => 'first_weighment_pending',
                'current_stage' => $stage,
                'weighing_mode' => 'loaded_first',
            ]);
            $movement->setRelation('company', $company);
        }

        $this->line("Movement: <comment>#{$movement->id} ({$movement->vehicle_number})</comment>");
        $this->line("Company:  <comment>" . ($movement->company?->name ?? 'Default Company') . " (#{$movement->company_id})</comment>");
        $this->line("Stage:    <info>{$stage}</info> (" . WorkflowStage::label($stage) . ")");

        // 2. Resolve required permission & targeted recipients
        $requiredPermission = PushNotificationService::STAGE_PERMISSIONS[$stage] ?? 'weighments.create';
        $this->line("Target Permission: <comment>{$requiredPermission}</comment>");

        $recipients = $pushService->resolveRecipients($movement->company_id, $requiredPermission);
        $this->info("Found {$recipients->count()} staff eligible for this notification:");

        foreach ($recipients as $recipient) {
            $tokensCount = $recipient->deviceTokens()->where('is_active', true)->count();
            $tokenBadge = $tokensCount > 0 ? "<info>[{$tokensCount} Active Token(s)]</info>" : "<fg=yellow>[No token registered]</>";
            $this->line("  👤 {$recipient->name} ({$recipient->email}) - {$tokenBadge}");
        }

        // 3. Build message
        [$title, $body] = $pushService->buildMessage($movement, $stage);
        $this->newLine();
        $this->info("Notification Payload:");
        $this->line("  Title: <comment>{$title}</comment>");
        $this->line("  Body:  {$body}");
        $this->newLine();

        // 4. Trigger dispatch
        $this->info("Dispatching notification...");
        $pushService->notifyStageTransition($movement, $stage);

        $this->info("✔ Trigger completed! Check storage/logs/laravel.log or target mobile devices.");
        $this->info("=================================================");

        return self::SUCCESS;
    }
}
