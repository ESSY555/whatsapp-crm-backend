<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active, expired, cancelled
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
