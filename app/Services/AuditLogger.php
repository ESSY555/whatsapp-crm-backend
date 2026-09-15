<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records who did what to which record. Always writes for the current tenant;
 * a missing tenant context (e.g. inside a job) is a no-op rather than an error.
 */
class AuditLogger
{
    public function __construct(private Request $request)
    {
    }

    public function log(string $action, Model|string $entity, ?int $entityId = null, ?array $old = null, ?array $new = null): ?AuditLog
    {
        if (!TenantManager::has()) {
            return null;
        }

        if ($entity instanceof Model) {
            $entityId = $entity->getKey();
            $entity = class_basename($entity);
        }

        return AuditLog::create([
            'user_id' => $this->request->user()?->id,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'old_values' => $old ? $this->sanitize($old) : null,
            'new_values' => $new ? $this->sanitize($new) : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }

    /**
     * Log the difference between a model's original and current attributes.
     */
    public function logChanges(string $action, Model $model): ?AuditLog
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if ($changes === []) {
            return null;
        }
        $original = array_intersect_key($model->getOriginal(), $changes);

        return $this->log($action, $model, null, $original, $changes);
    }

    private function sanitize(array $values): array
    {
        foreach (['password', 'access_token', 'access_token_encrypted', 'app_secret', 'token'] as $secret) {
            if (array_key_exists($secret, $values)) {
                $values[$secret] = '[redacted]';
            }
        }
        return $values;
    }
}
