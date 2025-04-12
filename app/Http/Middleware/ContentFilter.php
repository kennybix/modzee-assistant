<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ContentFilter
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('prompt')) {
            $prompt = $request->input('prompt');
            
            // Check against prohibited terms
            foreach (config('ai.moderation.prohibited_terms') as $term) {
                if (stripos($prompt, $term) !== false) {
                    return response()->json([
                        'error' => 'Your prompt contains prohibited content'
                    ], 422);
                }
            }
            
            // Check max length
            $maxLength = config('ai.limits.max_prompt_length');
            if (strlen($prompt) > $maxLength) {
                return response()->json([
                    'error' => "Your prompt exceeds the maximum length of {$maxLength} characters"
                ], 422);
            }
        }
        
        return $next($request);
    }
}
