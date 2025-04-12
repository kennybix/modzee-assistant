<?php

namespace App\Services\OpenAi;

// Import necessary classes
use Illuminate\Http\Client\Factory as HttpFactory; // Use Http Facade alias if preferred: use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Exception;
use JsonException; // Import JsonException

class Client
{
    protected string $apiKey;
    protected string $baseUrl;
    protected PendingRequest $httpClient; // Store configured HTTP client instance

    /**
     * Constructor for the OpenAI Client wrapper.
     *
     * @param string|null $apiKey OpenAI API Key. Reads from config if null.
     * @param string|null $baseUrl OpenAI API Base URL. Reads from config if null.
     * @throws Exception If API key is not configured.
     */
    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        // Use 'services.openai.*' config keys for consistency
        $this->apiKey = $apiKey ?? config('services.openai.api_key');
        $this->baseUrl = $baseUrl ?? config('services.openai.base_url', 'https://api.openai.com/v1'); // Default base URL

        if (empty($this->apiKey)) {
            // Log error and throw exception if key is missing
            Log::error('OpenAI API Key is missing. Please configure services.openai.api_key in config/services.php or .env');
            throw new Exception('OpenAI API Key is not configured.');
        }

        // Create a pre-configured HTTP client instance using the Http factory or facade
        // Get the factory from the service container for better testability if possible
        $httpFactory = app(HttpFactory::class);
        $this->httpClient = $httpFactory->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->timeout(config('services.openai.request_timeout', 60)) // Increased default timeout slightly
            ->retry(config('services.openai.retry_times', 2), config('services.openai.retry_delay', 150)); // Optional: Retry config
    }

    /**
     * Make a call to the OpenAI Chat Completions API.
     *
     * @param array $messages The array of messages (system, user, assistant).
     * @param array $options Additional options (model, temperature, max_tokens etc.).
     * @return array The JSON decoded response from OpenAI.
     * @throws Exception If the API call fails.
     */
    public function chatCompletion(array $messages, array $options = []): array
    {
        $endpoint = '/chat/completions'; // Relative to base URL

        $payload = array_merge([
            'model'       => config('services.openai.model', 'gpt-4o-mini'), // Use service config
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 1500, // Increased default slightly
        ], $options); // Provided options override defaults

        Log::debug('Sending Chat Completion Request', ['url' => $this->baseUrl . $endpoint, 'model' => $payload['model']]);

        try {
            // Use the pre-configured httpClient instance
            $response = $this->httpClient->post($this->baseUrl . $endpoint, $payload);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Unknown OpenAI API error';
                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'endpoint' => $endpoint,
                    'error_body' => $errorData,
                ]);
                // Throw exception with specific message
                throw new Exception('OpenAI API Error (' . $response->status() . '): ' . $errorMessage);
            }

            Log::debug('Received Chat Completion Response', ['status' => $response->status()]);
            return $response->json(); // Return the decoded JSON body

        } catch (Exception $e) {
            Log::error('OpenAI Client Exception (chatCompletion)', [
                'message' => $e->getMessage(),
            ]);
            // Re-throw to be handled by the calling Service
            throw $e;
        }
    }

    /**
     * Make a streaming call to the OpenAI Chat Completions API.
     *
     * @param array $messages
     * @param array $options
     * @param callable|null $callback Function to call with each received content chunk (chunk, isDone, logId=null, error=null)
     * @return void
     * @throws Exception
     */
    public function streamChatCompletion(array $messages, array $options = [], callable $callback = null): void
    {
        $endpoint = '/chat/completions'; // Relative to base URL

        $payload = array_merge([
            'model'       => config('services.openai.model', 'gpt-4o-mini'),
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 1500,
            'stream'      => true, // Ensure stream is always true
        ], $options);

        Log::debug('Sending Streaming Chat Completion Request', ['url' => $this->baseUrl . $endpoint, 'model' => $payload['model']]);

        try {
            $response = $this->httpClient
                // IMPORTANT: Remove 'verify' => false now that SSL works
                ->withOptions(['stream' => true])
                // ->timeout(120) // Streams might need longer timeouts
                ->post($this->baseUrl . $endpoint, $payload);

            if ($response->failed()) {
                 $errorData = $response->json();
                 $errorMessage = $errorData['error']['message'] ?? 'Unknown OpenAI API stream error';
                 Log::error('OpenAI Stream API Connection Error', [ /* ... logging ... */ ]);
                 throw new Exception('OpenAI Stream API Connection Error: ' . $errorMessage);
            }

            // Process stream iterator
            $buffer = '';
            foreach ($response->body() as $chunk) {
                $buffer .= $chunk;
                 // Process buffer for complete SSE lines (\n\n separator)
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    if (str_starts_with($event, 'data: ')) {
                        $jsonData = substr($event, 6);

                        if ($jsonData === '[DONE]') {
                            Log::debug('Stream finished with [DONE] signal.');
                            if ($callback) { $callback(null, true, null, null); } // Signal completion
                            return; // Exit after DONE
                        }

                        try {
                            $data = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
                            $contentChunk = $data['choices'][0]['delta']['content'] ?? null;
                            if ($callback && $contentChunk !== null) {
                                $callback($contentChunk, false, null, null); // Pass content chunk
                            }
                            // Check for finish reason if needed: $data['choices'][0]['finish_reason']
                        } catch (JsonException $e) {
                            Log::warning('Error parsing stream JSON chunk', ['json' => $jsonData, 'error' => $e->getMessage()]);
                        }
                    }
                } // end while position found
            } // end foreach chunk

             // If loop finishes without [DONE], signal completion (might happen on connection close)
             Log::warning('Stream ended without explicit [DONE] signal.');
             if ($callback) { $callback(null, true, null, null); }


        } catch (Exception $e) {
            Log::error('OpenAI Stream Client Exception', ['message' => $e->getMessage()]);
             if ($callback) { $callback(null, true, null, $e); } // Signal completion with error
            throw $e; // Re-throw
        }
    }

    /**
     * Make a call to the OpenAI Moderations API.
     *
     * @param string|array $input The input text(s) to moderate.
     * @return array The JSON decoded response from OpenAI.
     * @throws Exception If the API call fails.
     */
    public function moderation(string|array $input): array
    {
        $endpoint = '/moderations'; // Relative to base URL
        Log::debug('Sending Moderation Request', ['url' => $this->baseUrl . $endpoint]);

        try {
            // Use the pre-configured httpClient instance
            $response = $this->httpClient->post($this->baseUrl . $endpoint, ['input' => $input]);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Unknown OpenAI Moderation API error';
                Log::error('OpenAI Moderation API Error', [/* ... logging ... */]);
                throw new Exception('OpenAI Moderation API Error: ' . $errorMessage);
            }

            Log::debug('Received Moderation Response', ['status' => $response->status()]);
            return $response->json();

        } catch (Exception $e) {
            Log::error('OpenAI Moderation Client Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}