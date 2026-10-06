<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectRunnerScanUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'metadata' => ['required', 'string'],
            'file' => ['required', 'file', 'max:' . (int) config('inventory.max_upload_kb', 5120)],
        ];
    }
}
