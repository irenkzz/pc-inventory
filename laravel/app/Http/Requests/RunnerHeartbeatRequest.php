<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunnerHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'runner_id' => ['required', 'string', 'max:255'],
            'runner_guid' => ['nullable', 'string', 'max:100'],
            'runnerGuid' => ['nullable', 'string', 'max:100'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'string', 'max:100'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'collector_name' => ['nullable', 'string', 'max:255'],
            'runner_version' => ['nullable', 'string', 'max:100'],
            'install_mode' => ['nullable', 'string', 'max:100'],
            'last_seen_at' => ['nullable', 'date'],
            'last_successful_inventory_at' => ['nullable', 'date'],
            'last_inventory_status' => ['nullable', 'string', 'max:100'],
            'last_upload_status' => ['nullable', 'string', 'max:100'],
            'last_error' => ['nullable', 'string'],
            'last_command_seen_at' => ['nullable', 'date'],
            'last_command_type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
