<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_topup_amount', 14, 2)->default(10000.00);
            $table->decimal('meta_utility_rate_usd', 8, 4)->default(0.0101);
            $table->decimal('usd_ngn_rate', 14, 2)->default(1500.00);
            $table->decimal('markup_percent', 6, 2)->default(100.00);
            $table->decimal('vat_percent', 5, 2)->default(0.00);
            $table->decimal('low_balance_threshold', 14, 2)->default(200.00);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
