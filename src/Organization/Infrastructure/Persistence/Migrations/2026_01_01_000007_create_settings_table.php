<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // tenant | company | branch — scope_id is the id of that record.
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->string('group', 80);
            $table->string('key', 120);
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'scope_type', 'scope_id', 'group', 'key'], 'settings_scope_unique');
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->string('type', 30);
            $table->string('scope', 40);
            $table->unsignedBigInteger('current_value')->default(0);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->primary(['tenant_id', 'type', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
    }
};
