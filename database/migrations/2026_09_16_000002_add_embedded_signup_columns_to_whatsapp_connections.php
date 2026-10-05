<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            // What Meta tells us about the number once it is connected.
            $table->string('display_phone_number')->nullable()->after('business_account_id');
            $table->string('verified_name')->nullable()->after('display_phone_number');
            $table->string('quality_rating', 20)->nullable()->after('verified_name');
            $table->string('messaging_limit_tier', 40)->nullable()->after('quality_rating');
            $table->string('platform_type', 30)->nullable()->after('messaging_limit_tier'); // CLOUD_API / ON_PREMISE / coexistence
            // How the row was created: 'embedded' (Connect WhatsApp) or 'manual'.
            $table->string('onboarding_source', 20)->default('manual')->after('status');
            $table->foreignId('connected_by')->nullable()->after('onboarding_source')->constrained('users')->nullOnDelete();
            $table->timestamp('webhook_subscribed_at')->nullable()->after('connected_at');
            $table->timestamp('credit_line_attached_at')->nullable()->after('webhook_subscribed_at');
            $table->timestamp('templates_synced_at')->nullable()->after('credit_line_attached_at');
            // Two-step verification PIN set during registration (encrypted).
            $table->text('pin_encrypted')->nullable()->after('access_token_encrypted');
            $table->text('last_error')->nullable()->after('last_verified_at');

            $table->index('business_account_id');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            // Meta's own id for the template and why it was rejected, if it was.
            $table->string('meta_template_id')->nullable()->after('whatsapp_template_id');
            $table->string('rejection_reason')->nullable()->after('status');
            $table->boolean('is_platform')->default(false)->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['meta_template_id', 'rejection_reason', 'is_platform']);
        });
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropIndex(['business_account_id']);
            $table->dropConstrainedForeignId('connected_by');
            $table->dropColumn([
                'display_phone_number', 'verified_name', 'quality_rating', 'messaging_limit_tier', 'platform_type',
                'onboarding_source', 'webhook_subscribed_at', 'credit_line_attached_at', 'templates_synced_at', 'pin_encrypted', 'last_error',
            ]);
        });
    }
};
