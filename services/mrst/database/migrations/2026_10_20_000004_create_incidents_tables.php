<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Published incidents and their human-approved revisions. The revision columns mirror the
     * static site's Incident type (prototype/app/morashiter-data.ts) so msgn can copy them as-is.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('display_code')->unique();
            $table->string('origin');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->foreignId('withdrawn_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('incident_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status');
            $table->string('approval_method');
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('incident_review_id')->nullable()->constrained('incident_reviews')->nullOnDelete();
            $table->string('name');
            $table->string('company');
            $table->string('type');
            $table->string('scale');
            $table->text('brief');
            $table->string('date', 10);
            $table->string('incident_status');
            $table->string('disclosure');
            $table->string('report')->nullable();
            $table->string('quantity');
            $table->text('data_type');
            $table->text('summary');
            $table->text('target');
            $table->text('action');
            $table->text('response');
            $table->text('source');
            $table->text('contact');
            $table->timestamps();
            $table->unique(['incident_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_revisions');
        Schema::dropIfExists('incidents');
    }
};
