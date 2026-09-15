<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('type')->constrained('message_templates')->nullOnDelete();
            $table->json('metadata')->nullable()->after('error_message');
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'customer_id']);
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('metadata');
        });
    }
};
