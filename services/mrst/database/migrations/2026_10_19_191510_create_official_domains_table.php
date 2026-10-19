<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosts an administrator has confirmed as a company's own site. msic fetches only these.
     */
    public function up(): void
    {
        Schema::create('official_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('host')->unique();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_domains');
    }
};
