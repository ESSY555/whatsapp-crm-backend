<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant operational views: WhatsApp connections, audit trail and
 * queue health.
 */
class SystemController extends ApiController
{
    public function connections(Request $request)
    {
        $query = WhatsAppConnection::withoutGlobalScopes()->with('business:id,name,slug,status');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->successResponse('WhatsApp connections retrieved successfully.', $query->latest()->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function auditLogs(Request $request)
    {
        $query = AuditLog::withoutGlobalScopes()->with(['user:id,name,email', 'business:id,name,slug']);

        if ($businessId = $request->query('business_id')) {
            $query->where('business_id', (int) $businessId);
        }
        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action . '%');
        }
        if ($userId = $request->query('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        return $this->successResponse('Audit logs retrieved successfully.', $query->latest('created_at')->paginate(min((int) $request->query('per_page', 30), 100)));
    }

    public function status()
    {
        $failed = DB::table('failed_jobs')->latest('failed_at')->limit(20)->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'job' => $this->jobName($job->payload),
                'exception' => mb_substr((string) $job->exception, 0, 400),
                'failed_at' => $job->failed_at,
            ]);

        $pendingByQueue = DB::table('jobs')->selectRaw('queue, count(*) as c, min(available_at) as oldest')->groupBy('queue')->get()
            ->map(fn ($row) => ['queue' => $row->queue, 'count' => (int) $row->c, 'oldest_available_at' => $row->oldest ? date('c', (int) $row->oldest) : null]);

        return $this->successResponse('System status retrieved successfully.', [
            'app' => [
                'env' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'timezone' => config('app.timezone'),
                'queue_connection' => config('queue.default'),
                'mail_mailer' => config('mail.default'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
            'whatsapp' => [
                'graph_url' => config('services.whatsapp.graph_url'),
                'app_id_set' => (bool) config('services.whatsapp.app_id'),
                'app_secret_set' => (bool) config('services.whatsapp.app_secret'),
                'verify_token_set' => (bool) config('services.whatsapp.verify_token'),
            ],
            'queue' => [
                'pending' => (int) DB::table('jobs')->count(),
                'pending_by_queue' => $pendingByQueue,
                'failed' => (int) DB::table('failed_jobs')->count(),
                'recent_failures' => $failed,
            ],
        ]);
    }

    public function retryFailedJobs(Request $request)
    {
        $validated = $request->validate(['id' => ['nullable', 'string', 'max:64']]);

        Artisan::call('queue:retry', ['id' => [$validated['id'] ?? 'all']]);

        return $this->successResponse(trim(Artisan::output()) ?: 'Failed jobs queued for retry.');
    }

    public function flushFailedJobs()
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->delete();

        return $this->successResponse("Removed {$count} failed job(s).");
    }

    private function jobName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);
        return $decoded['displayName'] ?? null;
    }
}
