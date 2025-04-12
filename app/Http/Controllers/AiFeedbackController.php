<?php

namespace App\Http\Controllers;

// Remove the specific Form Request for now
// use App\Http\Requests\AiFeedbackRequest;
use Illuminate\Http\Request; // Use generic request for debugging
use App\Models\AiFeedback;
use App\Models\AiLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Make sure Log facade is imported
use Illuminate\Support\Facades\Validator; // Import Validator facade

class AiFeedbackController extends Controller
{
    // public function store(AiFeedbackRequest $request): JsonResponse // Old signature
    public function store(Request $request): JsonResponse // Use generic Request for debugging
    {
        // Log 1: Log all incoming data immediately
        Log::info('Feedback request data received:', $request->all());

        $logId = $request->input('response_id');
        $rating = $request->input('rating');
        $comment = $request->input('comment');

        // Log 2: Manually check if the AiLog exists right now
        $logExists = AiLog::where('id', $logId)->exists();
        Log::info('Manual Check: Does AiLog exist with ID ' . $logId . '?', ['exists' => $logExists]);

        // Log 3: Attempt manual validation (mirroring AiFeedbackRequest)
        $validator = Validator::make($request->all(), [
            'response_id' => 'required|integer|exists:ai_logs,id', // Keep exists rule for test
            'rating' => 'required|string|in:helpful,not_helpful',
            // 'comment' => 'sometimes|string|max:1000',
        ]);

        if ($validator->fails()) {
             Log::error('Manual validation failed:', $validator->errors()->toArray());
             // Return 422 if manual validation fails (this is likely what's happening)
             return response()->json(['errors' => $validator->errors()], 422);
        }

        // If manual validation *passes* (which would be surprising), proceed...
        Log::info('Manual validation PASSED for response_id: ' . $logId);

        try {
            // Use find instead of findOrFail since we manually validated existence
            $log = AiLog::find($logId);
            if (!$log) {
                 // This shouldn't happen if validation passed, but good failsafe
                 Log::error('AiLog not found with ID ' . $logId . ' after passing validation.');
                 throw new \Exception('Log record not found unexpectedly.');
            }

            AiFeedback::create([
                'ai_log_id' => $logId, // Use the validated ID
                'user_id' => Auth::id(),
                'rating' => $rating,
                'comment' => $comment,
            ]);

            return response()->json([
                'message' => 'Feedback recorded successfully',
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            Log::error('Error during feedback storage:', [
                'message' => $e->getMessage(),
                'exception' => $e
            ]);
            return response()->json([
                'error' => 'Failed to record feedback',
                'message' => $e->getMessage() // Provide message from exception
            ], 500);
        }
    }

    // getFeedbackStats method remains the same...
    public function getFeedbackStats(): JsonResponse
    {
        // Placeholder: Implement actual stats fetching if needed
        // $stats = AiFeedback:://... some query ...;
        $stats = ['helpful' => 0, 'not_helpful' => 0]; // Dummy data
        Log::warning('getFeedbackStats method called - implementation needed.');
        return response()->json($stats);
    }
}