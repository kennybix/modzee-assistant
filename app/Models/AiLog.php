<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiLog extends Model
{
    protected $fillable = [
        'user_id',
        'prompt',
        'response',
        'model',
        'tokens_used',
        'cost',
        'persona',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class);
    }

    // Analytics methods
    public static function getUsageByDay($startDate = null, $endDate = null)
    {
        $query = self::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(tokens_used) as tokens, SUM(cost) as total_cost')
            ->groupBy('date')
            ->orderBy('date');
            
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }
        
        return $query->get();
    }

    public static function getPersonaDistribution($startDate = null, $endDate = null)
    {
        $query = self::selectRaw('persona, COUNT(*) as count')
            ->groupBy('persona')
            ->orderBy('count', 'desc');
            
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }
        
        return $query->get();
    }

    public static function getFeedbackStats()
    {
        return self::selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM ai_feedback WHERE ai_feedback.ai_log_id = ai_logs.id AND ai_feedback.rating = "helpful"
            ) THEN 1 ELSE 0 END) as helpful,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM ai_feedback WHERE ai_feedback.ai_log_id = ai_logs.id AND ai_feedback.rating = "not_helpful"
            ) THEN 1 ELSE 0 END) as not_helpful
        ')->first();
    }
}
