<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained('crawl_runs');
            $table->foreignId('source_id')->constrained('crawl_sources');
            $table->text('normalized_url');
            $table->char('url_hash', 64);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('leased_until')->nullable();
            $table->string('lease_token')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('source_documents');
            $table->string('error_code')->nullable();
            $table->timestampTz('discovered_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['crawl_run_id', 'url_hash']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['source_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_items');
    }
};
