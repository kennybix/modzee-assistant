<?php

namespace App\Services\OpenAi;

// Make sure to import the AssistantDataService
use App\Services\AssistantDataService;
use App\Events\AiResponseGenerated; // Keep if used elsewhere
use App\Models\AiLog;
use App\Models\UserAiUsage; // Ensure this model & methods exist/work
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception; // Import base Exception class

class Service
{
    protected Client $client; // Assuming this is App\Services\OpenAi\Client
    protected AssistantDataService $assistantDataService; // Service to load data
    protected string $model;

    // Inject both Client and AssistantDataService
    public function __construct(Client $client, AssistantDataService $assistantDataService)
    {
        $this->client = $client;
        $this->assistantDataService = $assistantDataService; // Store injected service
        $this->model = config('services.openai.model', 'gpt-4o-mini'); // Use services config key, provide fallback
    }

    /**
     * Generates a response, adding context from AssistantDataService based on prompt analysis.
     *
     * @param string $prompt The original user prompt.
     * @param array $options Optional parameters like 'user_id', 'persona', 'previous_messages'.
     * @return array ['id' => ?int, 'reply' => string, 'timestamp' => string] Matches PDF requirement.
     * @throws Exception
     */
    public function generateResponse(string $prompt, array $options = []): array
    {
        $userId = $options['user_id'] ?? null;
        $persona = $options['persona'] ?? 'general';
        // Ensure previous messages structure is compatible [['role'=>'user', 'content'=>'...'], ...]
        $previousMessages = $options['previous_messages'] ?? [];

        // --- Existing Checks (Limits, Moderation - Implement fully if needed) ---
        if ($userId) {
            // Placeholder - Replace with actual UserAiUsage::checkUserLimit($userId) logic if required
            // Example: Assume limit check passes or is handled elsewhere for now
             Log::info("Usage limit check passed/skipped for user: {$userId}");
        }

        // Use 'services.openai.moderation' to align with other keys
        if (config('services.openai.moderation.enabled', false)) {
            $this->moderateContent($prompt); // Assumes this method exists and works
        }
        // --- End Existing Checks ---


        // --- Context Retrieval Logic ---
        $contextDataString = $this->getContextDataForPrompt($prompt);
        $finalUserContent = $prompt; // Default to original prompt

        if (!empty($contextDataString)) {
            // Prepend context to the user's prompt, instructing the AI
            // Ensure total prompt length doesn't exceed model limits
            $contextPrefix = "Use ONLY the following data to answer the user's question accurately. Do not make assumptions beyond this data.\n\nContext Data:\n```json\n" . $contextDataString . "\n```\n\nUser Question: ";
            $finalUserContent = $contextPrefix . $prompt;
            Log::info('OpenAI prompt augmented with context data.');
            // Consider truncating $finalUserContent if it might exceed token limits
        } else {
            Log::info('No specific context data identified or added for prompt.');
        }
        // --- End Context Retrieval Logic ---


        // --- Caching (Adjusted for Context) ---
        // Use consistent config keys 'services.openai.cache.*'
        $cacheKey = 'ai_response_' . md5($finalUserContent . $persona . json_encode($previousMessages)); // Cache key includes context
        $useCache = config('services.openai.cache.enabled', false);
        $cacheTTL = config('services.openai.cache.ttl', 0); // Cache time-to-live in seconds

        // Disable cache *reads* if context was added, to ensure AI sees the fresh context
        if (!empty($contextDataString)) {
            $useCache = false;
            Log::info('Cache read skipped due to dynamic context.');
        }

        if ($useCache && $cacheTTL > 0 && Cache::has($cacheKey)) {
            Log::info('Returning cached AI response.', ['key' => $cacheKey]);
            $cachedResponse = Cache::get($cacheKey);
            // Ensure cached response matches the required return structure
            return [
                'id' => $cachedResponse['id'] ?? null,
                'reply' => $cachedResponse['reply'] ?? 'Error retrieving cached response.',
                'timestamp' => $cachedResponse['timestamp'] ?? now()->toISOString(),
            ];
        }
        // --- End Caching Logic ---


        // Build messages array for OpenAI API call
        $messages = [
            ['role' => 'system', 'content' => $this->getPersonaPrompt($persona)],
        ];
        // Add previous conversation context (limited)
        $limitedHistory = array_slice($previousMessages, -5); // Example: keep last 5 messages
        foreach ($limitedHistory as $message) {
             if (isset($message['role'], $message['content']) && is_string($message['role']) && is_string($message['content'])) {
                 $messages[] = ['role' => $message['role'], 'content' => $message['content']];
             }
        }
        // Add current prompt (potentially augmented with context)
        $messages[] = ['role' => 'user', 'content' => $finalUserContent];


        // Call OpenAI API via the injected client
        $result = null;
        $startTime = microtime(true);
        try {
             Log::debug('Sending request to OpenAI client', ['model' => $this->model, 'message_count' => count($messages)]);
             // Ensure your Client->chatCompletion method exists and accepts options
             $result = $this->client->chatCompletion($messages, [
                 'model' => $this->model,
                 'temperature' => 0.5, // Adjusted for potentially data-focused Q&A
                 'max_tokens' => 1500, // Adjust as needed
             ]);
             $duration = microtime(true) - $startTime;
             Log::debug('Received raw response from OpenAI client', ['duration_ms' => round($duration * 1000)]);
        
            // **** ADD THIS LOG ****
            Log::info('Raw OpenAI API Result Body:', ['result_body' => $result]);
            // **** END ADDED LOG ****

            } catch (Exception $e) {
             Log::error('OpenAI Client Exception in Service:', ['message' => $e->getMessage()]);
             throw new Exception('Failed to get response from AI provider.', $e->getCode(), $e); // Re-throw standardized exception
        }

        // Process result
        // $aiResponse = $result['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';
       
        $aiResponse = 'Sorry, the format of the AI response was unexpected.'; // More specific default
        if (isset($result['choices'][0]['message']['content']) && is_string($result['choices'][0]['message']['content'])) {
            # log before trim
            Log::info('AI response before trim:', ['aiResponse' => $result['choices'][0]['message']['content']]);
            $aiResponse = trim($result['choices'][0]['message']['content']);
            // Log the trimmed response
            Log::info('AI response after trim:', ['aiResponse' => $aiResponse]);
            // Check if the extracted content is actually empty
            if (empty($aiResponse)) {
                $aiResponse = 'Sorry, the AI returned an empty response.';
                Log::warning('OpenAI returned empty content string.', ['result_body' => $result]);
            }
        } elseif (!isset($result['choices'][0]['message']['content'])) {
             Log::error('Failed to extract content key `[choices][0][message][content]` from OpenAI response structure.', ['result_body' => $result]);
        } else {
             // Content key exists but is not a string
             Log::error('Value at `[choices][0][message][content]` is not a string.', ['result_body' => $result]);
        }
        
        // Log the final value being used
        Log::info('Processed AI Response (final value):', ['aiResponse' => $aiResponse]);

        // Use actual token usage if available, otherwise estimate
        $promptTokens = $result['usage']['prompt_tokens'] ?? $this->estimateTokens($finalUserContent);
        $completionTokens = $result['usage']['completion_tokens'] ?? $this->estimateTokens($aiResponse);
        $tokensUsed = $result['usage']['total_tokens'] ?? ($promptTokens + $completionTokens);
        $actualModel = $result['model'] ?? $this->model; // Use model returned in response if available


        // Calculate cost
        $costPerToken = $this->getCostPerToken($actualModel);
        $cost = $tokensUsed * $costPerToken;

        // Log the interaction using AiLog model
        $log = null;
         try {
             $logData = [
                 'user_id' => $userId,
                 'prompt' => mb_substr($prompt, 0, 65535), // Log original prompt
                 'response' => mb_substr($aiResponse, 0, 65535), // Truncate response if needed
                 'model' => $actualModel,
                 'tokens_used' => $tokensUsed,
                 'cost' => $cost,
                 'persona' => $persona,
                 'context' => !empty($contextDataString) ? ['context_data_used' => true] : null, // Indicate context use
             ];
             $log = AiLog::create($logData);
             if ($log && $log->exists) {
                 Log::info('AiLog created successfully with ID:', ['id' => $log->id]);
             } else {
                  Log::warning('AiLog::create did not return a valid model instance or failed to save.');
             }
         } catch (Exception $e) {
             Log::error('Failed to create AiLog entry:', ['error' => $e->getMessage()]);
             // Continue without log ID
         }



        // Track user usage
        if ($userId && $tokensUsed > 0) {
            $this->trackUsage($tokensUsed, $cost, $userId); // Assumes this method exists/works
        }

        // Prepare final response object matching PDF requirement for /api/ai/assistant
        $responsePayload = [
            'id' => $log?->id, // Use nullsafe operator in case log creation failed
            'reply' => $aiResponse, // Use 'reply' key
            'timestamp' => now()->toISOString(), // Use current ISO timestamp
        ];



        // Cache the response payload (if caching enabled and *no context* was added)
        if ($useCache && empty($contextDataString) && $cacheTTL > 0) {
            Cache::put($cacheKey, $responsePayload, $cacheTTL);
            Log::info('AI response cached.', ['key' => $cacheKey]);
        }
        // log the response payload
        Log::info('Final response payload:', ['response_payload' => $responsePayload]);
        return $responsePayload; // Return array matching controller/PDF expectation
    }


    /**
     * Generates a report summary based on provided employee data.
     *
     * @param array $employeeData The array of employee data loaded by AssistantDataService.
     * @param string $reportModel Optional specific model for reporting tasks.
     * @return string The AI-generated report summary content.
     * @throws Exception If the API call fails or response is invalid.
     */
    public function generateReportSummary(array $employeeData, string $reportModel = 'gpt-4o-mini'): string // Or use gpt-4 for potentially better analysis
    {
        if (empty($employeeData)) {
            Log::warning('generateReportSummary called with empty employee data.');
            return 'No employee data was provided to generate the report.';
        }

        // Convert data to JSON - consider truncating if very large
        // Use JSON_PRETTY_PRINT for logging readability if needed, but not for the actual prompt
        $jsonData = json_encode($employeeData);
        // Check for encoding errors
        if ($jsonData === false) {
            Log::error('Failed to encode employee data to JSON for report.', ['json_last_error' => json_last_error_msg()]);
            throw new Exception('Could not prepare data for report generation.');
        }

        // Construct the specific prompt based on PDF example [cite: 15, 101]
        $reportPrompt = "Given this JSON data representing employee information:\n\n```json\n" . $jsonData . "\n```\n\nSummarize any concerning trends for management. Focus specifically on engagement scores, training completion percentages, and attendance rates across different teams or departments if possible. Provide actionable insights or recommendations based on the data. Format the summary clearly, perhaps using bullet points for key findings.";

        $messages = [
            // System message tailored for report analysis
            ['role' => 'system', 'content' => 'You are a business analyst assistant specialized in creating HR and performance report summaries from provided data. Focus on trends, insights, and recommendations.'],
            ['role' => 'user', 'content' => $reportPrompt]
        ];

        $startTime = microtime(true);
        try {
            Log::debug('Sending Report Generation Request to OpenAI client', ['model' => $reportModel, 'message_count' => count($messages)]);
            $result = $this->client->chatCompletion($messages, [
                'model' => $reportModel,
                'temperature' => 0.4, // Lower temperature for more factual reporting
                'max_tokens' => 1500, // Allow ample space for summary
            ]);
            $duration = microtime(true) - $startTime;
            Log::debug('Received raw Report Generation Response from OpenAI client', ['duration_ms' => round($duration * 1000)]);
            Log::info('Raw OpenAI Report Result Body:', ['result_body' => $result]); // Log raw result

            // Use robust extraction
            $reportContent = 'Sorry, the format of the AI report response was unexpected.'; // Default
            if (isset($result['choices'][0]['message']['content']) && is_string($result['choices'][0]['message']['content'])) {
                 $reportContent = trim($result['choices'][0]['message']['content']);
                 if (empty($reportContent)) {
                      $reportContent = 'Sorry, the AI returned an empty report.';
                      Log::warning('OpenAI returned empty content string for report.', ['result_body' => $result]);
                 }
            } else { Log::error('Failed to extract content from OpenAI report response structure.', ['result_body' => $result]); }
            Log::info('Processed AI Report (final value):', ['reportContent' => $reportContent]);

            // Note: Token usage/cost calculation could be added here if needed for reports too

            return $reportContent;

        } catch (Exception $e) {
            Log::error('OpenAI Client Exception during Report Generation:', ['message' => $e->getMessage()]);
            // Re-throw a specific message for reporting failure
            throw new Exception('Failed to generate report summary via AI provider.', $e->getCode(), $e);
        }
    }


    /**
     * Analyze prompt and retrieve relevant context data as a JSON string.
     * Basic keyword-based implementation - enhance as needed.
     *
     * @param string $prompt
     * @return string|null JSON string of context data or null.
     */
    protected function getContextDataForPrompt(string $prompt): ?string
    {
        $lowerPrompt = strtolower($prompt);
        $contextData = null;
        $dataFetched = false; // Flag to fetch only one relevant dataset per query (simplification)

        // --- More specific checks first ---
        // Check for specific employee name [cite: 16, 18, 20, 21, 23, 25, 27, 28, 30, 32, 34, 36]
        if (!$dataFetched && (str_contains($lowerPrompt, 'employee') || str_contains($lowerPrompt, 'staff') || preg_match('/\b(jane\s+doe|john\s+smith|sara\s+khan|michael\s+chen|emily\s+johnson|david\s+wilson|priya\s+patel|robert\s+garcia|jennifer\s+lee|thomas\s+wright|olivia\s+martinez|nathan\s+thompson)\b/i', $lowerPrompt, $nameMatch))) {
             $name = $nameMatch[1] ?? null; // Extracted name
             if ($name || preg_match('/(?:employee|staff|person)\s+"?([\w\s.-]+)"?/i', $prompt, $matches)) {
                  $nameToSearch = $name ?? trim($matches[1]);
                  Log::debug('Context: Trying to get employee by name', ['name' => $nameToSearch]);
                  $contextData = $this->assistantDataService->getEmployeeByName($nameToSearch); // Assumes this exists
                  $dataFetched = ($contextData !== null);
             }
        }
        // Check for specific customer name [cite: 1, 3, 5, 7, 8, 10, 12, 14]
        elseif (!$dataFetched && (str_contains($lowerPrompt, 'customer') || str_contains($lowerPrompt, 'client') || preg_match('/\b(global\s+tech|pinnacle\s+financial|healthfirst\s+networks|retail\s+revolution|innovate\s+manufacturing|fasttrack\s+logistics|creative\s+solutions|greentech\s+energy)\b/i', $lowerPrompt, $nameMatch))) {
             $name = $nameMatch[1] ?? null;
             if ($name || preg_match('/(?:customer|client)\s+"?([\w\s.&-]+)"?/i', $prompt, $matches)) {
                 $nameToSearch = $name ?? trim($matches[1]);
                 Log::debug('Context: Trying to get customer by name', ['name' => $nameToSearch]);
                 // $contextData = $this->assistantDataService->getCustomerByName($nameToSearch); // Assuming this method exists
                 Log::warning('getCustomerByName method not implemented in AssistantDataService example.'); // Placeholder
                 $dataFetched = ($contextData !== null);
             }
        }
        // Check for specific quarter/year sales data [cite: 38, 45, 53]
        elseif (!$dataFetched && (str_contains($lowerPrompt, 'sales') || str_contains($lowerPrompt, 'revenue')) && preg_match('/q([1-4])/i', $lowerPrompt, $qMatch) && preg_match('/\b(2024|2023)\b/', $lowerPrompt, $yMatch)) {
            $quarter = 'Q' . $qMatch[1];
            $year = (int)$yMatch[0];
            Log::debug('Context: Trying to get sales data for quarter/year', ['quarter' => $quarter, 'year' => $year]);
            $contextData = $this->assistantDataService->getSalesDataForQuarter($quarter, $year); // Assumes this exists
            $dataFetched = ($contextData !== null);
        }

        // --- Broader checks if nothing specific found yet ---
        // Check for sales targets [cite: 61, 68, 74]
        if (!$dataFetched && (str_contains($lowerPrompt, 'sales target') || str_contains($lowerPrompt, 'target'))) {
             Log::debug('Context: Getting sales targets data');
             $contextData = $this->assistantDataService->getSalesTargets();
             $dataFetched = true;
        }
        // Check for general sales data
        elseif (!$dataFetched && (str_contains($lowerPrompt, 'sales') || str_contains($lowerPrompt, 'revenue'))) {
             Log::debug('Context: Getting all sales data');
             $contextData = $this->assistantDataService->getSalesData();
             $dataFetched = true;
        }
        // Check for team data
        elseif (!$dataFetched && (str_contains($lowerPrompt, 'team'))) {
             Log::debug('Context: Getting teams data');
             $contextData = $this->assistantDataService->getTeamsData();
             $dataFetched = true;
        }
        // Avoid loading ALL employees/customers by default unless explicitly necessary
        // Consider adding checks like "list all employees"

        if ($contextData) {
            // Convert the PHP array/object to a compact JSON string for the prompt
            return json_encode($contextData); // Compact JSON uses fewer tokens
        }

        return null; // No relevant context identified
    }


    // --- Keep/Implement other existing methods ---

    public function streamResponse(string $prompt, array $options = [], callable $callback = null)
    {
         // TODO: Implement context injection for streaming similar to generateResponse
         Log::warning('streamResponse called, but context injection is not implemented.');
         throw new Exception('Streaming with context not implemented.');
    }

    public function generateReportAnalysis(array $data, array $options = [])
    {
         // TODO: Refactor to potentially use getContextDataForPrompt or avoid double context
         Log::warning('generateReportAnalysis may need refactoring to ensure correct context handling.');
         $formattedData = json_encode($data);
         $prompt = "Given this JSON data: {$formattedData}, summarize any concerning trends... Provide actionable insights.";
         return $this->generateResponse($prompt, $options); // Calls the modified generateResponse
    }

    private function moderateContent($prompt)
    {
         // Use the injected client
         try {
             Log::info('Performing content moderation check...'); // Add log
             // Ensure your client has a 'moderation' method
             $result = $this->client->moderation($prompt);
             if (isset($result['results'][0]['flagged']) && $result['results'][0]['flagged']) {
                 Log::warning('Content moderation flagged prompt', ['categories' => $result['results'][0]['categories'] ?? []]);
                 throw new Exception('Your prompt was flagged by our content moderation system');
             }
             Log::info('Content moderation check passed.');
         } catch (Exception $e) {
             Log::error('Content moderation API error: ' . $e->getMessage());
             throw new Exception('Content moderation check failed.', 0, $e);
         }
    }

    private function getPersonaPrompt($persona)
    {
        // Keep existing persona logic
        $personas = [
            'general' => 'You are a helpful assistant for modzee.',
            'sales' => 'You are a sales analytics expert for modzee. Focus on revenue trends and forecasts.',
            'hr' => 'You are an HR advisor for modzee. Focus on employee engagement and retention strategies.',
            'technical' => 'You are a technical advisor for modzee. Provide detailed technical explanations and solutions.',
        ];
        return $personas[$persona] ?? $personas['general'];
    }

    private function trackUsage($tokensUsed, $cost, $userId)
    {
        if (!$userId || $tokensUsed <= 0) { return; } // Don't track guests or zero usage
    
        try {
            $monthYear = now()->format('Y-m');
            UserAiUsage::updateOrCreate(
                ['user_id' => $userId, 'month' => $monthYear],
                [
                    'tokens_used' => DB::raw("tokens_used + " . (int)$tokensUsed),
                    'estimated_cost' => DB::raw("estimated_cost + " . (float)$cost)
                ]
            );
             Log::debug('User usage tracked.', ['user_id' => $userId, 'month' => $monthYear, 'tokens' => $tokensUsed]);
        } catch (Exception $e) {
            Log::error('Failed to track user usage.', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function estimateTokens($text): int
    {
        // Very rough estimate
        return (int) (strlen($text) / 4);
    }

    private function getCostPerToken(string $model): float
    {
         // IMPORTANT: Update these costs based on current OpenAI pricing!
         $costs = [
             'gpt-4o-mini' => 0.00000015, // Input: $0.15 / 1M tokens
             'gpt-4o' => 0.000005,       // Input: $5.00 / 1M tokens
             'gpt-4-turbo' => 0.00001,  // Input: $10.00 / 1M tokens
             'gpt-4' => 0.00003,        // Input: $30.00 / 1M tokens
             'gpt-3.5-turbo' => 0.0000005, // Input: $0.50 / 1M tokens
             // Output costs are often different - this calculation is simplified
         ];

         // Use str_starts_with for broader matching (e.g., gpt-4-turbo-preview)
         foreach ($costs as $modelPrefix => $cost) {
              if (str_starts_with($model, $modelPrefix)) {
                   return $cost;
              }
         }
         Log::warning('Unknown model for cost calculation:', ['model' => $model]);
         return 0.000001; // Fallback cost
    }

} // End of Service class