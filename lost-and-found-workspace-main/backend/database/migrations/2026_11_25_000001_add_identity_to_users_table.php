<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'identity')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('identity', 20)->default('一般成員');
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_identity_check");
                DB::statement("ALTER TABLE users ADD CONSTRAINT users_identity_check CHECK (identity IN ('管理員', '一般成員'))");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'identity')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_identity_check");
            }
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('identity');
            });
        }
    }
};
