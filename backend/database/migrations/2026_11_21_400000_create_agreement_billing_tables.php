<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agreed plans and their monthly invoices.
 *
 * SASA is sold business to business: the SASA team agrees a price and scope
 * with each company (an agreement), and the system raises a monthly invoice
 * against it. A payment now belongs EITHER to a website subscription request
 * OR to an invoice, so payments.subscription_request_id becomes nullable and
 * payments.invoice_id is added. Existing payments keep their request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_agreements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained('enquiries')->nullOnDelete();
            $table->string('name', 160);
            $table->string('currency', 3)->default('TZS');   // Snippe settles TZS only
            $table->decimal('monthly_amount', 14, 2);
            $table->unsignedTinyInteger('billing_day');      // 1..28
            $table->date('starts_on');
            $table->date('next_due_on');
            $table->string('status', 20)->default('active'); // active | past_due | suspended | cancelled
            $table->json('modules');
            $table->json('limits')->nullable();              // {projects:int|null, users:int|null}
            $table->text('terms_note')->nullable();          // shown to the customer
            $table->text('internal_note')->nullable();       // never shown to the customer
            $table->boolean('auto_pay')->default(false);
            $table->string('auto_pay_phone', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
            $table->index(['status', 'next_due_on']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 30)->unique();          // INV-2026-0001
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_agreement_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->date('due_on');
            $table->string('status', 20)->default('open');   // open | paid | overdue | void
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_agreement_id', 'period_start']);
            $table->index(['organisation_id', 'period_start']);
            $table->index(['status', 'due_on']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('subscription_request_id')->nullable()->change();
            $table->foreignId('invoice_id')->nullable()->after('subscription_request_id')
                ->constrained('invoices')->restrictOnDelete();
            $table->index(['invoice_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'id']);
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::dropIfExists('invoices');
        Schema::dropIfExists('organisation_agreements');
    }
};
