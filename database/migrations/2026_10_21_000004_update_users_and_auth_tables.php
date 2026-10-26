<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 30)->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('role_id')->constrained('departments')->nullOnDelete();
            $table->string('status', 20)->default('active')->after('department_id');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('timezone', 60)->nullable()->after('last_login_at');
            $table->string('avatar_url')->nullable()->after('timezone');

            $table->index('role_id');
            $table->index('department_id');
            $table->index('status');
        });

        Schema::create('user_company', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
            $table->index('company_id');
        });

        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tokens');
        Schema::dropIfExists('user_company');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['mobile', 'status', 'last_login_at', 'timezone', 'avatar_url']);
        });
    }
};
