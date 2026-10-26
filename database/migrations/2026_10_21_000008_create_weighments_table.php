<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_movement_id')->constrained('vehicle_movements')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('weight_type', 20);
            $table->decimal('weight', 14, 3);
            $table->string('unit', 20)->default('kg');
            $table->decimal('weight_in_kg', 14, 3);
            $table->string('weighbridge_ref', 60)->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weighed_at');
            $table->text('remarks')->nullable();
            $table->boolean('is_correction')->default(false);
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vehicle_movement_id', 'sequence']);
            $table->index('weight_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighments');
    }
};
