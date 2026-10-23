<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_domain_investigations', function (Blueprint $table) {
            $table->id();
            $table->string('host', 253);
            $table->unsignedInteger('generation');
            $table->string('status')->default('queued');
            $table->string('recommendation')->default('hold');
            $table->string('organization_name')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('raw_proposal')->nullable();
            $table->text('reason')->nullable();
            $table->string('error_code')->nullable();
            $table->text('final_url')->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->jsonb('pages')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('rule_version');
            $table->string('prompt_version');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['host', 'generation']);
            $table->index(['status', 'id']);
        });
        Schema::create('official_domain_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('official_domain_investigations');
            $table->string('host', 253);
            $table->string('decision');
            $table->string('company_name')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies');
            $table->foreignId('admin_id')->constrained('admin_users');
            $table->text('reason');
            $table->jsonb('confirmations');
            $table->timestampTz('decided_at');
        });
        Schema::create('domain_research_budgets', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('used')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_research_budgets');
        Schema::dropIfExists('official_domain_decisions');
        Schema::dropIfExists('official_domain_investigations');
    }
};
