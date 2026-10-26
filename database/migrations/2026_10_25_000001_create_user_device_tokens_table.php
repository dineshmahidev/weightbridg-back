<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 500)->index();
            $table->string('platform', 30)->default('android'); // android, ios, web
            $table->string('device_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
