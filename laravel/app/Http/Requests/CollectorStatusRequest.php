<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollectorStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['nullable', 'string', 'max:100'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'collector_name' => ['required', 'string', 'max:255'],
            'collector_version' => ['nullable', 'string', 'max:100'],
            'share_root_hint' => ['nullable', 'string', 'max:500'],
            'last_seen_at' => ['nullable', 'date'],
            'last_status' => ['nullable', 'string', 'max:100'],
            'last_error' => ['nullable', 'string'],
            'queue_depth_csv' => ['nullable', 'integer', 'min:0'],
            'queue_depth_heartbeat' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
