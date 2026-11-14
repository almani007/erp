<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax authority reporting (FBR POS / Digital Invoicing), one row per document.
        Schema::create('fiscal_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->string('system', 20);                // fbr_pos | fbr_di
            $table->string('document_type', 20);         // sale | sales_return
            $table->uuid('document_id');
            $table->string('document_no', 60);
            $table->string('status', 20);                // pending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->string('fiscal_invoice_no', 120)->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['system', 'document_type', 'document_id']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->string('url', 500);
            $table->jsonb('events');                     // ["sales.sale.completed", …] or ["*"]
            $table->string('secret', 80);                // signs each delivery (HMAC-SHA256)
            $table->string('status', 20)->default('active');
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id');
            $table->uuid('outbox_event_id');
            $table->string('event_type', 120);
            $table->string('status', 20);                // pending | delivered | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_body', 1000)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('webhook_subscriptions')->cascadeOnDelete();
            $table->index(['status', 'next_attempt_at']);
        });

        // Every WhatsApp / SMS / email sent, with its outcome.
        Schema::create('message_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('channel', 20);               // whatsapp | sms | email
            $table->string('recipient', 200);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('status', 20);                // sent | failed
            $table->string('error', 1000)->nullable();
            $table->string('related_type', 60)->nullable();
            $table->uuid('related_id')->nullable();
            $table->string('provider_id', 120)->nullable();
            $table->uuid('created_by')->nullable();      // null = sent automatically
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_api_client')->default(false); // a machine login for another system
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('hs_code', 20)->nullable();   // PCT / HS code for FBR
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('province', 40)->nullable();  // FBR digital invoicing needs the buyer's province
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('fiscal_invoice_no', 120)->nullable(); // printed on the receipt with its QR code
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->string('fiscal_invoice_no', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', fn (Blueprint $table) => $table->dropColumn('fiscal_invoice_no'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('fiscal_invoice_no'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('province'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('hs_code'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_api_client'));
        Schema::dropIfExists('message_log');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('fiscal_submissions');
    }
};
