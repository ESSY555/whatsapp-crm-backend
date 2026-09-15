<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Digits-only copy of phone so inbound WhatsApp messages resolve to a customer.
            $table->string('phone_normalized', 32)->nullable()->after('phone');
            $table->index(['business_id', 'phone_normalized']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'created_at']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'issue_date']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['customer_id', 'whatsapp_connection_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'phone_normalized']);
            $table->dropIndex(['business_id', 'status']);
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropColumn('phone_normalized');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropIndex(['business_id', 'issue_date']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'whatsapp_connection_id']);
        });
    }
};
