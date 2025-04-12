<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AiFeedbackController; // Assuming this controller exists for feedback
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Grouping under common API middleware (like throttling)
Route::middleware(['api', 'throttle:60,1'])->group(function () {

    // Prefix all AI-related routes with /ai
    Route::prefix('ai')->name('ai.')->group(function () { // Added route naming for convenience

        // --- Core AI Assistant Endpoint ---
        Route::post('/assistant', [AiAssistantController::class, 'handleAssistantRequest']) // Corrected method name
              ->middleware(['content.filter']) // Keep custom middleware if needed
              ->name('assistant.request'); // Added route name

        // --- Bonus Reporting Endpoint ---
        Route::post('/report', [AiAssistantController::class, 'generateReport'])
              // ->middleware(['auth:sanctum']) // Consider if report generation needs auth
              ->name('assistant.report'); // Added route name

        // --- Feedback Endpoint ---
        // Assumes AiFeedbackController exists with a 'store' method
        Route::post('/feedback', [AiFeedbackController::class, 'store'])
              // ->middleware(['auth:sanctum']) // Consider if feedback needs auth
              ->name('feedback.store'); // Added route name

        // --- Stats/Usage Endpoints (Require Authentication) ---
        Route::middleware(['auth:sanctum'])->group(function () {
            // Corrected method name based on user's previous controller code
            Route::get('/usage', [AiAssistantController::class, 'getUserUsage'])
                  ->name('usage.show'); // Added route name

            // Assumes AiFeedbackController exists with a 'getFeedbackStats' method
            Route::get('/feedback/stats', [AiFeedbackController::class, 'getFeedbackStats'])
                  ->name('feedback.stats'); // Added route name
        });

        /* --- Stream Endpoint (Commented Out) ---
         * We removed the streamPrompt function from aiService.js to match PDF core task.
         * If you implement streaming later, you can uncomment this and ensure
         * the 'stream' method exists in AiAssistantController.
        Route::post('/stream', [AiAssistantController::class, 'stream'])
               ->middleware(['content.filter'])
               ->name('assistant.stream');
        */

    }); // End of /ai prefix group

}); // End of api middleware group

// Example of a fallback route for API if needed
// Route::fallback(function(){
//     return response()->json(['message' => 'Not Found.'], 404);
// });