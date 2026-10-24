<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('billing_source', 20)->nullable()->after('billing_category');
            $table->decimal('unit_price', 10, 2)->nullable()->after('billing_source');
            $table->timestamp('refunded_at')->nullable()->after('charged_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['billing_source', 'unit_price', 'refunded_at']);
        });
    }
};
