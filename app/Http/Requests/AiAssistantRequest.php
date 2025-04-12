<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule; // Import Rule for 'in' validation

class AiAssistantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * We'll assume authorization is handled by API middleware (like Sanctum)
     * or that the endpoint is publicly accessible for this test.
     * Set to true to allow the request to proceed to validation.
     * Adjust if specific user authorization logic is needed here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        // Allowed persona values based on your controller's getPersonaSystemMessage method
        $allowedPersonas = ['general', 'sales', 'hr', 'technical'];

        return [
            // 'prompt' is required as per the PDF specification [cite: 7]
            'prompt' => [
                'required', // Must be present
                'string',   // Must be a string
                'max:4096' // Set a reasonable max length for the prompt
            ],

            // 'persona' is optional but if provided, must be one of the allowed values
            'persona' => [
                'nullable', // Allowed to be missing or null
                'string',
                Rule::in($allowedPersonas) // Must be one of the defined personas
            ],

            // 'previousMessages' is optional but if provided, must be an array
            'previousMessages' => [
                'nullable', // Allowed to be missing or null
                'array'     // Must be an array if present
            ],
            // Optional: Validate the structure within the previousMessages array
            // Requires that if previousMessages is present, it contains an array of objects,
            // and each object must have 'role' and 'content' keys which are strings.
            'previousMessages.*' => ['sometimes', 'array'], // Each item must be an array/object
            'previousMessages.*.role' => ['required_with:previousMessages.*', 'string', Rule::in(['user', 'assistant', 'system'])], // Role must be present and valid
            'previousMessages.*.content' => ['required_with:previousMessages.*', 'string'], // Content must be present and a string
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'The prompt field is required.',
            'prompt.string' => 'The prompt must be text.',
            'prompt.max' => 'The prompt is too long.',
            'persona.in' => 'The selected persona is invalid.',
            'previousMessages.array' => 'The previous messages must be provided as an array.',
            'previousMessages.*.role.required_with' => 'Each message in the history must have a role.',
            'previousMessages.*.role.in' => 'Invalid role found in message history.',
            'previousMessages.*.content.required_with' => 'Each message in the history must have content.',
        ];
    }
}