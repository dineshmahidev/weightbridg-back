<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('data_type', 20)->default('number');
            $table->string('unit', 20)->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('min_value', 14, 3)->nullable();
            $table->decimal('max_value', 14, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_movement_id')->constrained('vehicle_movements')->cascadeOnDelete();
            $table->string('result', 10);
            $table->text('remarks')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('parameter_values')->nullable();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at');
            $table->timestamps();

            $table->unique('vehicle_movement_id');
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_inspections');
        Schema::dropIfExists('quality_parameters');
    }
};
