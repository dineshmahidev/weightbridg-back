<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->string('unit', 20)->default('kg');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index('company_id');
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registration_number', 30);
            $table->string('vehicle_type', 40)->nullable();
            $table->string('make_model', 80)->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'registration_number']);
            $table->index('registration_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('materials');
    }
};
