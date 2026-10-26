<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_movement_id')->constrained('vehicle_movements')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('location')->nullable();
            $table->text('material_details')->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('quantity_unit', 20)->nullable();
            $table->string('assigned_staff')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('remarks')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_movement_id', 'type']);
            $table->index('status');
        });

        Schema::create('exit_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_movement_id')->constrained('vehicle_movements')->cascadeOnDelete();
            $table->string('decision', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique('vehicle_movement_id');
            $table->index('decision');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('exit_approvals');
        Schema::dropIfExists('material_operations');
    }
};
