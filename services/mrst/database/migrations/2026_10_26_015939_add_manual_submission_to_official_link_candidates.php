<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_link_candidates', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_document_id')->nullable()->change();
            $table->foreignId('submitted_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('last_retried_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('last_retried_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX official_candidates_manual_url_unique ON official_link_candidates (url_hash) WHERE source_document_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('official_link_candidates')->whereNull('source_document_id')->exists()) {
            throw new RuntimeException('Manual submissions exist. Preserve their provenance; use a forward migration instead of rollback.');
        }
        DB::statement('DROP INDEX official_candidates_manual_url_unique');
        Schema::table('official_link_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by_admin_id');
            $table->dropConstrainedForeignId('last_retried_by_admin_id');
            $table->dropColumn('last_retried_at');
            $table->unsignedBigInteger('source_document_id')->nullable(false)->change();
        });
    }
};
