<?php

namespace App\Jobs;

use App\Events\AiResponseGenerated;
use App\Services\OpenAi\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAiRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $prompt;
    protected $options;

    public function __construct($prompt, $options = [])
    {
        $this->prompt = $prompt;
        $this->options = $options;
    }

    public function handle(Service $openAiService)
    {
        $userId = $this->options['user_id'] ?? null;
        
        try {
            $result = $openAiService->generateResponse($this->prompt, $this->options);
            
            // Broadcast the result to the user if they're authenticated
            if ($userId) {
                event(new AiResponseGenerated($userId, $result));
            }
            
            return $result;
        } catch (\Exception $e) {
            // If user is authenticated, notify them of the error
            if ($userId) {
                event(new AiResponseGenerated($userId, [
                    'error' => $e->getMessage()
                ]));
            }
            
            throw $e;
        }
    }
}
