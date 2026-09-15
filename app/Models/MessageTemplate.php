<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageTemplate extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'disabled'];

    protected $fillable = ['name', 'category', 'language', 'content', 'whatsapp_template_id', 'status', 'variables'];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    public function reminderRules(): HasMany
    {
        return $this->hasMany(ReminderRule::class, 'template_id');
    }

    public function reminderRulesCount(): int
    {
        return $this->reminderRules()->count();
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Placeholder names used in the content, e.g. {{customer_name}} => customer_name.
     */
    public function placeholders(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $this->content, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Replace {{placeholders}} with the supplied values; unknown ones are left blank.
     */
    public function render(array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn ($m) => (string) ($variables[$m[1]] ?? ''),
            $this->content,
        );
    }
}
