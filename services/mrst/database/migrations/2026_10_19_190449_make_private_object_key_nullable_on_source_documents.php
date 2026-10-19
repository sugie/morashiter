<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * News articles are used for discovery only; msnc no longer stores a private copy.
     */
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->text('private_object_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->text('private_object_key')->nullable(false)->change();
        });
    }
};
