<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiFeedbackRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'response_id' => 'required|exists:ai_logs,id',
            'rating' => 'required|string|in:helpful,not_helpful',
            // 'comment' => 'sometimes|string|max:1000',
        ];
    }
}
