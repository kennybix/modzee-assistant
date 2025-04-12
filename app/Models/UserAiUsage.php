<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAiUsage extends Model
{
    protected $fillable = [
        'user_id',
        'month',
        'tokens_used',
        'estimated_cost',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getCurrentMonthUsage($userId)
    {
        $currentMonth = now()->format('Y-m');
        
        return self::firstOrCreate([
            'user_id' => $userId,
            'month' => $currentMonth
        ]);
    }

    public static function checkUserLimit($userId)
    {
        $usage = self::getCurrentMonthUsage($userId);
        $limit = config('ai.limits.monthly_token_limit');
        
        return [
            'usage' => $usage->tokens_used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $usage->tokens_used),
            'percentage' => $limit > 0 ? min(100, ($usage->tokens_used / $limit) * 100) : 100,
            'limit_exceeded' => $usage->tokens_used >= $limit
        ];
    }
}
