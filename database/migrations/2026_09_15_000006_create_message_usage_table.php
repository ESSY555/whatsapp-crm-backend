<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One counter row per business, calendar month and billing category.
        Schema::create('message_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->string('category'); // utility, marketing, service
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'period_start', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_usage');
    }
};
