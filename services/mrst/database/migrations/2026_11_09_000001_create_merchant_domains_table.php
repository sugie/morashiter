<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shopping-site hosts (where people buy), confirmed by an administrator with an evidence URL.
     * Separate from official_domains, which are the hosts where a company publishes its notices.
     * The extension's bucketed JSON is keyed by registrable_domain and matched on the exact host.
     */
    public function up(): void
    {
        Schema::create('merchant_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('host')->unique();
            $table->string('registrable_domain')->index();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('evidence_url', 2048);
            $table->text('note')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_domains');
    }
};
