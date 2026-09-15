<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Payments are immutable: corrections are recorded as reversal rows.
            $table->string('status')->default('completed')->after('notes'); // completed, reversed, reversal
            $table->foreignId('reversal_of_id')->nullable()->after('status')->constrained('payments')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversal_of_id');
            $table->string('idempotency_key', 100)->nullable()->after('reversed_at');

            $table->unique(['business_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropColumn(['status', 'reversed_at', 'idempotency_key']);
        });
    }
};
