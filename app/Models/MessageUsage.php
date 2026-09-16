<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Monthly message counters per billing category. Incremented only when a
 * message actually leaves through the Cloud API.
 */
class MessageUsage extends Model
{
    use BelongsToBusiness;

    protected $table = 'message_usage';

    public const CATEGORIES = ['utility', 'marketing', 'service'];

    protected $fillable = ['period_start', 'category', 'sent_count', 'failed_count'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'sent_count' => 'integer', 'failed_count' => 'integer'];
    }

    /** First day of the current calendar month, the quota period. */
    public static function currentPeriod(): Carbon
    {
        return now()->startOfMonth();
    }
}
