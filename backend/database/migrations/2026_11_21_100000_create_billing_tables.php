<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions and payments. Platform level: none of these rows belongs to a
 * tenant. A plan is archived, never deleted; a payment keeps the provider's
 * raw payloads for reconciliation, and they are never returned by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price_usd', 12, 2)->nullable();
            $table->decimal('price_tzs', 14, 2)->nullable();
            $table->string('billing_period', 20)->default('monthly'); // monthly | yearly
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_custom_quote')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['archived_at', 'sort_order']);
        });

        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 30)->unique();      // SUB-2026-0001
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2)->nullable();   // null for a custom quote
            $table->string('organisation_name', 200);
            $table->string('contact_name', 160);
            $table->string('email', 190);
            $table->string('phone', 30);
            $table->string('country', 80)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 30);                   // pending_payment | paid | quote_requested | active | rejected | cancelled
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('subscription_request_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);                 // sandbox | snippe
            $table->string('method', 20);                   // mobile_money | card
            $table->string('phone', 30)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->string('status', 20);                   // pending | succeeded | failed | cancelled
            $table->string('provider_reference', 100)->nullable();
            $table->string('checkout_url', 1000)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_reference']);
            $table->index(['subscription_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscription_requests');
        Schema::dropIfExists('plans');
    }
};
