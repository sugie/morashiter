<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI drafts extracted from an official announcement, waiting for a human decision.
     * Nothing is published from here without an approval.
     */
    public function up(): void
    {
        Schema::create('incident_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('official_document_id')->unique()->constrained('official_documents')->cascadeOnDelete();
            $table->string('status');
            $table->string('analyzer');
            $table->jsonb('analysis')->nullable();
            $table->string('error_code')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'analyzer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_reviews');
    }
};
