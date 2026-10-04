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
        Schema::create('source_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('crawl_sources');
            $table->foreignId('crawl_run_id')->constrained('crawl_runs');
            $table->text('canonical_url');
            $table->text('final_url');
            $table->char('url_hash', 64);
            $table->char('content_sha256', 64);
            $table->text('title');
            $table->date('published_on')->nullable();
            $table->timestampTz('fetched_at');
            $table->unsignedSmallInteger('http_status');
            $table->string('content_type');
            $table->text('private_object_key');
            $table->string('extraction_version');
            $table->timestamps();
            $table->unique(['source_id', 'url_hash', 'content_sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_documents');
    }
};
