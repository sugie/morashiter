<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links found in a news article that may point to the company's own announcement.
     * msnc only records them; msic later fetches and verifies the official page.
     */
    public function up(): void
    {
        Schema::create('official_link_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained('source_documents')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->string('host');
            $table->string('anchor_text', 200)->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['source_document_id', 'url_hash']);
            $table->index(['status', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_link_candidates');
    }
};
