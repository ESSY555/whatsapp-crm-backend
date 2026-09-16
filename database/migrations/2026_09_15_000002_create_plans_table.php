<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('interval_days')->default(30);
            // Monthly quotas by billing category; null means unlimited.
            $table->unsignedInteger('utility_quota')->nullable();
            $table->unsignedInteger('marketing_quota')->nullable();
            $table->boolean('allows_broadcasts')->default(false);
            $table->unsignedInteger('max_team_members')->nullable();
            $table->boolean('is_trial_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
