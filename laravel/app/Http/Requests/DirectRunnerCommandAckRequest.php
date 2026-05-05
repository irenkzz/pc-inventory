<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectRunnerCommandAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_code' => ['nullable', 'string', 'max:100'],
            'site_id' => ['nullable', 'string', 'max:100'],
            'runner_id' => ['required', 'string', 'max:255'],
            'runner_guid' => ['nullable', 'string', 'max:100'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'command_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:succeeded,failed'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string'],
            'result_upload_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
