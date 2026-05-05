<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function normalizedAssignment(): array
    {
        return [
            'department' => $this->normalize($this->input('department')),
            'site' => $this->normalize($this->input('site')),
            'location' => $this->normalize($this->input('location')),
            'room' => $this->normalize($this->input('room')),
        ];
    }

    private function normalize(mixed $value): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
