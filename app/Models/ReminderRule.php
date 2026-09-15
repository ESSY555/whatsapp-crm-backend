<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReminderRule extends Model
{
    use BelongsToBusiness;

    public const TRIGGER_TYPES = ['before_due', 'on_due', 'after_due', 'overdue'];

    protected $fillable = ['name', 'trigger_type', 'days_offset', 'template_id', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'days_offset' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function getTriggerDisplay(): string
    {
        return match ($this->trigger_type) {
            'before_due' => "{$this->days_offset} days before due date",
            'on_due' => 'Due today',
            'after_due' => "{$this->days_offset} days overdue",
            'overdue' => 'Any time after the due date',
            default => $this->trigger_type,
        };
    }

    /**
     * Whether this rule matches the invoice today. Dates are compared at day
     * granularity so the daily scheduler produces the same answer all day.
     */
    public function matchesInvoice(Invoice $invoice): bool
    {
        if (!$this->enabled || !$invoice->due_date || !$invoice->isOpen()) {
            return false;
        }

        // Positive when the due date is in the future, negative once overdue.
        $daysUntilDue = (int) today()->diffInDays($invoice->due_date->copy()->startOfDay(), false);

        return match ($this->trigger_type) {
            'before_due' => $daysUntilDue === $this->days_offset && $daysUntilDue > 0,
            'on_due' => $daysUntilDue === 0,
            'after_due' => $daysUntilDue === -$this->days_offset && $daysUntilDue < 0,
            'overdue' => $daysUntilDue < 0,
            default => false,
        };
    }
}
