<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_hash', 64);
            $table->uuid('client_request_id');
            $table->unique(['owner_hash', 'client_request_id']);
            $table->string('product_id');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('catalog_version');
            $table->uuid('memo_id')->nullable();
            $table->uuid('create_request_id')->unique();
            $table->uuid('capture_request_id')->unique();
            $table->string('paypal_order_id')->nullable()->unique();
            $table->string('paypal_capture_id')->nullable()->unique();
            $table->string('status')->default('create_unknown');
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('capture_started_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_orders');
    }
};
