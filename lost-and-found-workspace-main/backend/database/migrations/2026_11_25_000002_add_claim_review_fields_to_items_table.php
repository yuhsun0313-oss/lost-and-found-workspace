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
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('items', 'claimant_email')) {
                $table->string('claimant_email')->nullable();
            }
            if (!Schema::hasColumn('items', 'vlm_fail_count')) {
                $table->integer('vlm_fail_count')->default(0);
            }
            if (!Schema::hasColumn('items', 'review_status')) {
                $table->string('review_status', 30)->default('pending');
            }
            if (!Schema::hasColumn('items', 'manual_review_deadline')) {
                $table->timestamp('manual_review_deadline')->nullable();
            }
            if (!Schema::hasColumn('items', 'claim_proof_image')) {
                $table->longText('claim_proof_image')->nullable();
            }
            if (!Schema::hasColumn('items', 'vlm_last_reason')) {
                $table->text('vlm_last_reason')->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE items DROP CONSTRAINT IF EXISTS items_review_status_check");
            DB::statement("ALTER TABLE items ADD CONSTRAINT items_review_status_check CHECK (review_status IN ('pending', 'needs_manual_review', 'completed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE items DROP CONSTRAINT IF EXISTS items_review_status_check");
        }

        Schema::table('items', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('items', 'claimant_email') ? 'claimant_email' : null,
                Schema::hasColumn('items', 'vlm_fail_count') ? 'vlm_fail_count' : null,
                Schema::hasColumn('items', 'review_status') ? 'review_status' : null,
                Schema::hasColumn('items', 'manual_review_deadline') ? 'manual_review_deadline' : null,
                Schema::hasColumn('items', 'claim_proof_image') ? 'claim_proof_image' : null,
                Schema::hasColumn('items', 'vlm_last_reason') ? 'vlm_last_reason' : null,
            ]);
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
