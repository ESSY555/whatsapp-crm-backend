<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('status');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('billing_category', 20)->nullable()->after('type');
            $table->timestamp('charged_at')->nullable()->after('read_at');
        });

        // Businesses created before billing existed get the standard trial
        // window measured from signup.
        DB::table('businesses')->whereNull('trial_ends_at')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('businesses')->where('id', $row->id)->update([
                    'trial_ends_at' => Carbon::parse($row->created_at)->addDays(14),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['billing_category', 'charged_at']);
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
