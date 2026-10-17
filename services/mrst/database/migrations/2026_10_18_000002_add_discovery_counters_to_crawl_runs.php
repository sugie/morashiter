<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_runs', function (Blueprint $table) {
            $table->timestampTz('discovery_finished_at')->nullable()->after('finished_at');
            $table->unsignedInteger('discovered_count')->default(0)->after('discovery_finished_at');
            $table->unsignedInteger('succeeded_count')->default(0)->after('discovered_count');
            $table->unsignedInteger('skipped_count')->default(0)->after('succeeded_count');
            $table->unsignedInteger('failed_count')->default(0)->after('skipped_count');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_runs', function (Blueprint $table) {
            $table->dropColumn([
                'discovery_finished_at',
                'discovered_count',
                'succeeded_count',
                'skipped_count',
                'failed_count',
            ]);
        });
    }
};
