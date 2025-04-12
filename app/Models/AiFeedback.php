<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    protected $fillable = [
        'ai_log_id',
        'user_id',
        'rating',
        'comment',
    ];

    public function aiLog(): BelongsTo
    {
        return $this->belongsTo(AiLog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
