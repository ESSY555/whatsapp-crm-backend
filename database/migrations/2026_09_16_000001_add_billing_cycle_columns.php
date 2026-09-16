<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            // Seam for a future payment processor; see App\Enums\PaymentMethod.
            $table->string('method', 30)->default('manual_receipt')->after('plan_id');
            // What the business says they transferred; `amount` stays the plan price.
            $table->decimal('amount_claimed', 12, 2)->nullable()->after('amount');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Set when the period ends: full access continues until this date,
            // then the daily lifecycle job expires the subscription.
            $table->dateTime('grace_ends_at')->nullable()->after('ends_at');
        });

        Schema::table('message_usage', function (Blueprint $table) {
            // Usage is counted per billing cycle, so rows are keyed by the
            // subscription they belong to (null while on trial).
            $table->foreignId('subscription_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            // New index first: on MySQL the business_id FK needs an index that
            // starts with business_id at all times.
            $table->unique(['business_id', 'subscription_id', 'period_start', 'category'], 'message_usage_cycle_unique');
            $table->dropUnique(['business_id', 'period_start', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('message_usage', function (Blueprint $table) {
            $table->unique(['business_id', 'period_start', 'category']);
            $table->dropUnique('message_usage_cycle_unique');
            $table->dropConstrainedForeignId('subscription_id');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('grace_ends_at');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn(['method', 'amount_claimed']);
        });
    }
};
