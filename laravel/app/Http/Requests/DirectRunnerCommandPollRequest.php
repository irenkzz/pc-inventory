<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectRunnerCommandPollRequest extends FormRequest
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
            'runner_version' => ['nullable', 'string', 'max:100'],
            'transport_mode' => ['required', 'in:direct_https'],
        ];
    }
}
