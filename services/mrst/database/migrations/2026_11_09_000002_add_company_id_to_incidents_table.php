<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company that published the incident, so a shopping host (merchant_domains) can find its
     * incidents. Nullable: hand-entered incidents are linked later on the correction form.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('origin')->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
