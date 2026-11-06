<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_announcement_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('official_link_candidate_id')->constrained('official_link_candidates')->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->jsonb('proposals')->nullable();
            $table->jsonb('rule_candidates')->nullable();
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->string('analyzer', 80)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('dismissed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('dismiss_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'id']);
        });
        // One queued or running search per candidate.
        DB::statement("CREATE UNIQUE INDEX official_announcement_searches_active_unique ON official_announcement_searches (official_link_candidate_id) WHERE status IN ('queued', 'running')");
        Schema::create('announcement_search_budgets', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedInteger('used')->default(0);
        });
        Schema::table('official_link_candidates', function (Blueprint $table): void {
            $table->foreignId('suggested_by_search_id')->nullable()->constrained('official_announcement_searches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('official_link_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suggested_by_search_id');
        });
        Schema::dropIfExists('announcement_search_budgets');
        Schema::dropIfExists('official_announcement_searches');
    }
};
