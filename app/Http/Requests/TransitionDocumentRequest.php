<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(DocumentStatus::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
