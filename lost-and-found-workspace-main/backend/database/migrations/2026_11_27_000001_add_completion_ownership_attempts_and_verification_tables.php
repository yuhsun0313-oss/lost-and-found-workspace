<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
            if (!Schema::hasColumn('items', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (!Schema::hasColumn('items', 'completed_delete_deadline')) {
                $table->timestamp('completed_delete_deadline')->nullable()->index();
            }
        });

        if (!Schema::hasTable('item_claim_attempts')) {
            Schema::create('item_claim_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('item_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('claimant_email')->index();
                $table->integer('attempt_count')->default(0);
                $table->string('status', 30)->default('active');
                $table->longText('last_proof_image')->nullable();
                $table->text('last_reason')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('verification_codes')) {
            Schema::create('verification_codes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('purpose', 40)->index(); // email_old | email_new | password_change
                $table->string('target_email');
                $table->string('code', 10);
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('item_claim_attempts');

        Schema::table('items', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('items', 'user_id') ? 'user_id' : null,
                Schema::hasColumn('items', 'completed_at') ? 'completed_at' : null,
                Schema::hasColumn('items', 'completed_delete_deadline') ? 'completed_delete_deadline' : null,
            ]);
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
