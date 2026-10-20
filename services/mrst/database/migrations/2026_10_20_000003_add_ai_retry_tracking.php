<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * attempts: real AI calls made for a document, so retries stop at a limit.
     * ai_usage_days: every real call per Japanese calendar day, so retries count toward the cap.
     */
    public function up(): void
    {
        Schema::table('incident_reviews', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('error_code');
        });

        Schema::create('ai_usage_days', function (Blueprint $table) {
            $table->id();
            $table->date('usage_date');
            $table->string('provider');
            $table->unsignedInteger('calls')->default(0);
            $table->timestamps();
            $table->unique(['usage_date', 'provider']);
        });

        // Existing real analyses were made once each before this change.
        DB::table('incident_reviews')->where('analyzer', '!=', 'mock')->update(['attempts' => 1]);
        DB::statement(<<<'SQL'
            insert into ai_usage_days (usage_date, provider, calls, created_at, updated_at)
            select (analyzed_at at time zone 'Asia/Tokyo')::date, analyzer, count(*), now(), now()
            from incident_reviews
            where analyzer <> 'mock' and analyzed_at is not null
            group by 1, 2
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_days');
        Schema::table('incident_reviews', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
