<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every msgn publish attempt. Exactly one row is live; built artefacts are archived privately
     * under msgn/releases/{release_id} so a failed publish can restore the live one.
     */
    public function up(): void
    {
        Schema::create('site_releases', function (Blueprint $table) {
            $table->id();
            $table->string('release_id', 64)->nullable()->index();
            $table->string('status');
            $table->char('snapshot_sha256', 64)->nullable();
            $table->jsonb('incident_ids')->nullable();
            $table->string('data_as_of', 10)->nullable();
            $table->boolean('indexable')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('triggered_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_releases');
    }
};
