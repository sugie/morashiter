<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companies' own announcements fetched by msic. These, not news articles, are the basis
     * for analysis and publication. Originals are kept privately; a content change is a new row.
     */
    public function up(): void
    {
        Schema::create('official_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('official_domain_id')->constrained('official_domains');
            $table->foreignId('link_candidate_id')->nullable()->constrained('official_link_candidates')->nullOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->text('final_url');
            $table->char('content_sha256', 64);
            $table->string('content_type');
            $table->text('private_object_key');
            $table->text('title')->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->timestampTz('fetched_at');
            $table->timestamps();
            $table->unique(['url_hash', 'content_sha256']);
        });

        Schema::table('official_link_candidates', function (Blueprint $table) {
            $table->string('error_code')->nullable()->after('status');
            $table->timestampTz('fetched_at')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('official_link_candidates', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'fetched_at']);
        });
        Schema::dropIfExists('official_documents');
    }
};
