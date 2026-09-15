<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('template_id');
            $table->string('name');
            $table->enum('trigger_type', ['before_due', 'on_due', 'after_due', 'overdue'])->default('before_due');
            $table->unsignedInteger('days_offset')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('message_templates')->onDelete('restrict');

            $table->index(['business_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_rules');
    }
};
