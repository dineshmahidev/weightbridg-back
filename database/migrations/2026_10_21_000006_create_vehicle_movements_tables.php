<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_ref', 40)->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vehicle_number', 30);
            $table->string('driver_name')->nullable();
            $table->string('driver_mobile', 30)->nullable();
            $table->string('driver_license_no', 40)->nullable();
            $table->string('supplier_or_customer')->nullable();
            $table->string('purpose', 40);
            $table->string('weighing_mode', 20)->default('empty_first');
            $table->string('status', 40);
            $table->string('current_stage', 40);
            $table->text('security_remarks')->nullable();
            $table->timestamp('entry_at')->nullable();
            $table->timestamp('stage_started_at')->nullable();
            $table->timestamp('stage_completed_at')->nullable();
            $table->timestamp('exit_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('exit_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('empty_weight', 14, 3)->nullable();
            $table->decimal('loaded_weight', 14, 3)->nullable();
            $table->decimal('net_weight', 14, 3)->nullable();
            $table->string('weight_unit', 20)->default('kg');
            $table->foreignId('weight_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weight_verified_at')->nullable();
            $table->string('quality_result', 10)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'entry_at']);
            $table->index(['company_id', 'exit_at']);
            $table->index('vehicle_number');
            $table->index('material_id');
            $table->index('current_stage');
            $table->index('quality_result');
        });

        Schema::create('movement_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_movement_id')->constrained('vehicle_movements')->cascadeOnDelete();
            $table->string('stage', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->text('remarks')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['vehicle_movement_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_histories');
        Schema::dropIfExists('vehicle_movements');
    }
};
