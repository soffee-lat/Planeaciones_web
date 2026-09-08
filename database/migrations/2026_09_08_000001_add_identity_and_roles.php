<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active');
            $table->timestampTz('onboarding_completed_at')->nullable();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended'))");
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->timestampsTz();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->primary(['user_id', 'role_id']);
        });
        foreach (\App\Enums\RoleCode::cases() as $role) {
            DB::table('roles')->insert(['code' => $role->value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    public function down(): void {
        Schema::dropIfExists('role_user'); Schema::dropIfExists('roles');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['status', 'onboarding_completed_at']));
    }
};

