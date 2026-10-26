<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Plan::query()->whereIn('code', ['starter', 'business', 'professional'])->update([
            'allows_broadcasts' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Plan::query()->whereIn('code', ['starter', 'business', 'professional'])->update([
            'allows_broadcasts' => false,
        ]);
    }
};
