// tests/Feature/AiAssistantTest.php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        
        // Mock the OpenAI API responses
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'This is a test response from the AI.'
                        ]
                    ]
                ],
                'usage' => [
                    'total_tokens' => 50
                ]
            ], 200),
            
            'api.openai.com/v1/moderations' => Http::response([
                'results' => [
                    [
                        'flagged' => false
                    ]
                ]
            ], 200)
        ]);
    }

    public function test_can_generate_ai_response()
    {
        $response = $this->postJson('/api/ai/assistant', [
            'prompt' => 'Test prompt'
        ]);
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'response',
                'timestamp',
                'model',
                'tokens_used'
            ]);
        
        $this->assertDatabaseHas('ai_logs', [
            'prompt' => 'Test prompt',
        ]);
    }

    public function test_validates_prompt_length()
    {
        $response = $this->postJson('/api/ai/assistant', [
            'prompt' => 'Hi' // Too short
        ]);
        
        $response->assertStatus(422);
    }

    public function test_authenticated_user_usage_is_tracked()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)
            ->postJson('/api/ai/assistant', [
                'prompt' => 'Test prompt for authenticated user'
            ]);
        
        $response->assertStatus(200);
        
        $this->assertDatabaseHas('ai_logs', [
            'user_id' => $user->id,
            'prompt' => 'Test prompt for authenticated user',
        ]);
        
        $this->assertDatabaseHas('user_ai_usage', [
            'user_id' => $user->id,
        ]);
    }

    public function test_can_generate_report()
    {
        $response = $this->postJson('/api/ai/report');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'response',
                'timestamp',
                'model',
                'tokens_used'
            ]);
    }

    public function test_can_submit_feedback()
    {
        // First create an AI response
        $aiResponse = $this->postJson('/api/ai/assistant', [
            'prompt' => 'Test prompt for feedback'
        ]);
        
        $responseId = $aiResponse->json('id');
        
        // Then submit feedback for it
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)
            ->postJson('/api/ai/feedback', [
                'response_id' => $responseId,
                'rating' => 'helpful',
                'comment' => 'This was very useful!'
            ]);
        
        $response->assertStatus(200);
        
        $this->assertDatabaseHas('ai_feedback', [
            'ai_log_id' => $responseId,
            'user_id' => $user->id,
            'rating' => 'helpful',
            'comment' => 'This was very useful!'
        ]);
    }
}
