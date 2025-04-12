<?php

namespace App\Http\Controllers;

use App\Models\UserAiUsage; // Use this model for user usage tracking
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\AiAssistantRequest; // Use Form Request for validation
use App\Models\AiLog;
use App\Services\AssistantDataService; // Keep if needed for generateReport
use App\Services\OpenAi\Service as OpenAiService; // Inject OpenAI Service
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // Keep for other methods if they don't use Form Requests
use Illuminate\Support\Facades\Log;
use Carbon\Carbon; // Use Carbon for date formatting
use Exception; // Import Exception class for error handling
// Removed: use Illuminate\Support\Facades\Http; // No longer needed directly
// Removed: use Illuminate\Support\Facades\Validator; // No longer needed directly


class AiAssistantController extends Controller
{
    // Inject both services via constructor
    protected OpenAiService $openAiService;
    protected AssistantDataService $dataService; // Keep for generateReport

    public function __construct(OpenAiService $openAiService, AssistantDataService $dataService)
    {
        $this->openAiService = $openAiService;
        $this->dataService = $dataService; // Keep injection
    }

    // /**
    //  * Handle the incoming AI assistant request, validated by AiAssistantRequest.
    //  *
    //  * @param AiAssistantRequest $request // Use Form Request
    //  * @return \Illuminate\Http\JsonResponse
    //  */
    // public function handleAssistantRequest(AiAssistantRequest $request): JsonResponse // Renamed method
    // {
    //     // Validation is automatically handled by AiAssistantRequest
    //     // Access validated data using $request->validated()
    //     $validatedData = $request->validated();
    //     $prompt = $validatedData['prompt'];
    //     $timestamp = now(); // Get timestamp early

    //     // Get optional fields if needed for context, using defaults
    //     $persona = $request->input('persona', 'general');
    //     $previousMessages = $request->input('previousMessages', []);

    //     try {
    //         // --- Delegate to OpenAiService ---
    //         // Pass necessary context (prompt, persona, history) to the service method
    //         // The service method will construct the final API payload
    //         $reply = $this->openAiService->generateCompletionForAssistant(
    //             prompt: $prompt,
    //             persona: $persona,
    //             history: $previousMessages // Pass history for context
    //         );
    //         // --- End Delegation ---

    //         // Log the interaction (Bonus task)
    //         // Assuming logInteraction method exists and works as before
    //         $this->logInteraction($prompt, $reply, $persona);

    //         // Return success response strictly matching PDF spec
    //         return response()->json([
    //             'reply' => $reply, // Use 'reply' key
    //             'timestamp' => $timestamp->toISOString(), // Use ISO format timestamp
    //             // Removed 'success' and 'id' keys from response as per PDF
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error('AI Assistant Error: ' . $e->getMessage(), [
    //             'prompt' => $prompt, // Log context
    //             'exception' => $e // Log full exception
    //         ]);

    //         // Return a standardized error response
    //         return response()->json([
    //             'error' => 'Failed to get response from AI service.',
    //             'timestamp' => $timestamp->toISOString(),
    //             // Optionally include more detail in non-production environments
    //             // 'details' => config('app.debug') ? $e->getMessage() : null,
    //         ], 500); // Use 500 status code
    //     }
    // }


    /**
     * Handle the incoming AI assistant request, validated by AiAssistantRequest.
     *
     * @param AiAssistantRequest $request // Use Form Request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleAssistantRequest(AiAssistantRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $prompt = $validatedData['prompt'];
        $timestamp = now(); // Get timestamp early

        // Get optional fields for context
        $persona = $request->input('persona', 'general');
        $previousMessages = $request->input('previousMessages', []);
        $userId = auth()->id(); // Get authenticated user ID if available

        try {
            // *** THIS IS THE KEY CHANGE ***
            // Call the actual 'generateResponse' method from your service
            // Pass parameters as expected by that method (prompt, options array)
            $serviceResponse = $this->openAiService->generateResponse( // Call generateResponse
                $prompt,
                [
                    'persona' => $persona,
                    'previous_messages' => $previousMessages,
                    'user_id' => $userId // Pass user ID for logging/limits
                ]
            );

            // log service response
            Log::info('Service response received', [
                'response' => $serviceResponse,
                'user_id' => $userId // Log user ID for context
            ]);

            // Extract the AI's reply from the service response array
            // Your service returns ['response' => ..., 'timestamp' => ..., 'id' => ...]
            // $reply = $serviceResponse['response'] ?? 'Sorry, no reply could be generated.';
            // // Use the timestamp generated by the service if available, otherwise fallback
            // $responseTimestamp = $serviceResponse['timestamp'] ?? $timestamp;


            // // Note: Logging is already handled within your generateResponse method,
            // // so no need to call $this->logInteraction() here unless you want separate logging.

            // // Return success response strictly matching PDF spec: { reply, timestamp }
            // return response()->json([
            //     'reply' => $reply, // Use the 'response' field from the service return value
            //     // Ensure timestamp is in ISO 8601 format
            //     'timestamp' => $responseTimestamp instanceof \Carbon\Carbon
            //                     ? $responseTimestamp->toISOString()
            //                     : now()->parse($responseTimestamp)->toISOString(),
            // ]);

             // Ensure you have the log ID. If your service returns it in its response array:
            $logId = $serviceResponse['id'] ?? null; // Get ID from service response
            $reply = $serviceResponse['reply'] ?? 'Sorry...';
            $responseTimestamp = $serviceResponse['timestamp'] ?? now();

            // --- Modify the return statement ---
            return response()->json([
                'reply' => $reply,
                'timestamp' => $responseTimestamp instanceof \Carbon\Carbon
                                ? $responseTimestamp->toISOString()
                                : now()->parse($responseTimestamp)->toISOString(),
                'id' => $logId // *** ADD THIS LINE *** Include the log ID in the response
            ]);


        } catch (\Exception $e) {
            // Catch exceptions potentially thrown by generateResponse (e.g., limit exceeded, moderation)
            Log::error('AI Assistant Error: ' . $e->getMessage(), [
                'prompt' => $prompt,
                'exception_class' => get_class($e),
                'exception' => $e // Log full exception for debugging
            ]);

            // Return a standardized error response
            return response()->json([
                // Provide the specific error message if it's safe to do so
                'error' => $e->getMessage() ?: 'Failed to get response from AI service.',
                'timestamp' => $timestamp->toISOString(),
            ], 500); // Use 500 or potentially a 4xx code if it's a user error (like limit)
        }
    }

    /**
     * Generate a report based specifically on employee data analysis.
     * This corresponds to the PDF Bonus Task focusing on employee trends[cite: 101].
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateReport(Request $request): JsonResponse
    {
        Log::info('Received request to generate employee performance report.');
        $timestamp = now();
        $userId = auth()->id(); // Get authenticated user ID if available

        try {
            // 1. Get only the Employee data using AssistantDataService
            // Ensure $this->assistantDataService is injected via constructor
            $employeeData = $this->dataService->getEmployeesData(); //

            if (empty($employeeData)) {
                Log::warning('Cannot generate report: Employee data is empty or unavailable.');
                return response()->json([
                    'error' => 'Employee data required for the report is currently unavailable.',
                    'reply' => null,
                    'id' => null,
                    'timestamp' => $timestamp->toISOString(),
                ], 404);
            }

            // 2. Call the dedicated service method for report generation
            $reportContent = $this->openAiService->generateReportSummary($employeeData);

            // 3. Log the interaction (Using AiLog model directly)
            $log = null;
            try {
                $logData = [
                    'user_id' => $userId,
                    'prompt' => 'Action: Generate Team Performance Report', // Log the action type
                    'response' => mb_substr($reportContent, 0, 65535), // Truncate if needed
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'persona' => 'report_generation', // Specific persona for this log type
                    // Add token/cost estimation here if generateReportSummary returns it
                ];
                $log = AiLog::create($logData);
                 if ($log && $log->exists) {
                     Log::info('AiLog created for report generation.', ['id' => $log->id]);
                 } else {
                      Log::warning('AiLog::create failed for report generation.');
                 }
            } catch (Exception $e) {
                 Log::error('Failed to create AiLog entry for report.', ['error' => $e->getMessage()]);
                 // Continue even if logging fails
            }

            // // log $reportContent for debugging
            // Log::info('Generated report content:', [
            //     'content' => $reportContent,
            //     'user_id' => $userId, // Log user ID for context
            //     'log_id' => $log?->id // Include log ID if created
            // ]);

            // 4. Return the standard success response format { id, reply, timestamp }
            return response()->json([
                'id' => $log?->id, // Include log ID if created
                'reply' => $reportContent, // Use 'reply' key
                'timestamp' => $timestamp->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Report generation failed overall: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'error' => 'An error occurred while generating the report.',
                 'reply' => null,
                 'id' => null,
                'message' => config('app.debug') ? $e->getMessage() : 'Please contact support.',
                'timestamp' => $timestamp->toISOString(),
            ], 500);
        }
    }
    /**
     * Submit feedback for an AI response
     * Consider using AiFeedbackRequest for validation
     *
     * @param Request $request // Could be AiFeedbackRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitFeedback(Request $request): JsonResponse // Added return typehint
    {
        // Consider replacing with AiFeedbackRequest validation
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
             // Use response_id matching the service/frontend if log ID isn't directly sent
            'response_id' => 'required|integer|exists:ai_logs,id',
            'rating' => 'required|string|in:helpful,not_helpful'
        ]);

        if ($validator->fails()) {
            return response()->json([ /* ... validation error response ... */ ], 422);
        }

        try {
            // Use 'response_id' from request
            $log = AiLog::findOrFail($request->input('response_id'));
            $log->feedback = $request->input('rating');
            $log->feedback_at = now();
            $log->save();

            return response()->json([
                'success' => true,
                'message' => 'Feedback submitted successfully'
            ]);
        } catch (\Exception $e) {
             Log::error('Feedback submission error: ' . $e->getMessage(), ['request' => $request->all(), 'exception' => $e]);
            return response()->json([ /* ... error response ... */ ], 500);
        }
    }

  /**
     * Get usage statistics for the authenticated user.
     * Retrieves current month's usage and recent history from the user_ai_usage table.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserUsage(Request $request): JsonResponse
    {
        // The 'auth:sanctum' middleware applied in routes/api.php handles authentication.
        // If the request reaches here, Auth::user() should be available.
        $user = Auth::user();

        // Double check for safety, though middleware should prevent unauthenticated access
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $userId = $user->id;
        Log::info('Fetching usage stats for user:', ['user_id' => $userId]);

        try {
            // --- Get Current Month Usage ---
            $currentMonthYear = now()->format('Y-m'); // Format like "2025-04"
            $currentUsageRecord = UserAiUsage::where('user_id', $userId)
                                             ->where('month', $currentMonthYear)
                                             ->first();

            $currentTokensUsed = $currentUsageRecord->tokens_used ?? 0;
            // $currentCost = $currentUsageRecord->estimated_cost ?? 0.0; // Use if needed

            // --- Get Limit & Calculate Stats ---
            $monthlyTokenLimit = config('services.openai.monthly_token_limit', 1000000); // Default fallback limit

            if ($monthlyTokenLimit <= 0) {
                $usagePercentage = 0;
                $remainingTokens = PHP_INT_MAX; // Unlimited
                $limitExceeded = false;
            } else {
                 $remainingTokens = max(0, $monthlyTokenLimit - $currentTokensUsed);
                 $usagePercentage = round(($currentTokensUsed / $monthlyTokenLimit) * 100, 2);
                 $limitExceeded = $currentTokensUsed >= $monthlyTokenLimit;
            }

            $usageStats = [
                'usage' => $currentTokensUsed,
                'limit' => $monthlyTokenLimit,
                'remaining' => $remainingTokens,
                'percentage' => $usagePercentage,
                'limit_exceeded' => $limitExceeded
            ];

            // --- Get Usage History (e.g., last 6 months including current) ---
            $historyMonths = 6;
            $usageHistoryRecords = UserAiUsage::where('user_id', $userId)
                                              ->orderBy('month', 'desc') // Get latest months first
                                              ->limit($historyMonths)
                                              ->get(['month', 'tokens_used']);

            // Format history for frontend chart (e.g., 'M Y' format, sorted chronologically)
            $formattedHistory = $usageHistoryRecords
                ->map(function ($record) {
                    try {
                        $carbonDate = Carbon::createFromFormat('Y-m', $record->month)->startOfMonth();
                        return [
                            'month' => $carbonDate->format('M Y'), // e.g., "Apr 2025"
                            'tokens_used' => (int) ($record->tokens_used ?? 0)
                        ];
                    } catch (\Exception $e) {
                        Log::error("Failed to parse month format from user_ai_usage", ['record' => $record, 'error' => $e->getMessage()]);
                        return null;
                    }
                })
                ->filter() // Remove nulls from parsing errors
                ->sortBy(function ($item) {
                     return Carbon::createFromFormat('M Y', $item['month'])->timestamp; // Sort chronologically
                 })
                ->values(); // Reset array keys


            // Return combined data (match structure expected by Vue component)
            return response()->json([
                // 'success' => true, // Optional: Keep if frontend expects it
                'usage' => $usageStats,
                'history' => $formattedHistory
            ]);

        } catch (\Exception $e) {
            Log::error('Usage stats retrieval error: ' . $e->getMessage(), [
                'user_id' => $userId,
                'exception' => $e
            ]);
            return response()->json([
                // 'success' => false, // Optional
                'message' => 'Failed to retrieve usage statistics'
            ], 500);
        }
    }
    // --- Helper methods remain as provided ---

    /**
     * Get system message based on persona
     *
     * @param string $persona
     * @return string
     */
    private function getPersonaSystemMessage(string $persona): string
    {
        // Keep the switch statement as you provided
         switch ($persona) {
             case 'sales': return "You are a sales analyst...";
             case 'hr': return "You are an HR advisor...";
             case 'technical': return "You are a technical advisor...";
             default: return "You are an AI assistant for modzee...";
         }
    }

    /**
     * Log the interaction to database
     * Consider moving token estimation logic elsewhere or getting actual count from API response if available
     *
     * @param string $prompt
     * @param string $response
     * @param string $persona
     * @return int ID of the log entry
     */
    private function logInteraction(string $prompt, string $response, string $persona): int
    {
        // Rough token estimation - Note: OpenAI response often includes actual token usage
        $promptTokens = (int)(str_word_count($prompt) * 1.3);
        $responseTokens = (int)(str_word_count($response) * 1.3);
        $totalTokens = $promptTokens + $responseTokens;

        $log = new AiLog();
        $log->user_id = auth()->id(); // Ensure auth() is available or handle guests
        $log->prompt = $prompt; // Consider truncating if prompts/responses can be very long
        $log->response = $response; // Consider truncating
        $log->persona = $persona;
        $log->token_count = $totalTokens; // Store estimated or actual tokens
        $log->save();

        return $log->id;
    }
}