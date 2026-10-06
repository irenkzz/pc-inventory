<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommandAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'command_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'in:completed,succeeded,failed'],
            'message' => ['nullable', 'string'],
            'runner_state' => ['nullable', 'array'],
        ];
    }
}
