<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment attempt. Retrying a reference creates a new row
        // with a fresh gateway_reference; earlier attempts are kept for audit
        // and can still complete if the customer pays them.
        Schema::create(config('bdpaygate.table', 'bdpaygate_payments'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('gateway', 32);
            $table->string('reference', 64);
            $table->string('gateway_reference', 64);
            $table->string('gateway_payment_id', 128)->nullable()->index();
            $table->string('gateway_transaction_id', 128)->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->string('status', 24)->index();
            $table->boolean('requires_review')->default(false);
            $table->string('failure_reason')->nullable();
            $table->string('description')->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('customer')->nullable();
            $table->json('meta')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'reference']);
            $table->unique(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('bdpaygate.table', 'bdpaygate_payments'));
    }
};
