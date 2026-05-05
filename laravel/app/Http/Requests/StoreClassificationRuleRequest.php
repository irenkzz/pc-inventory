<?php

namespace App\Http\Requests;

use App\Models\ClassificationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $classificationRule = $this->route('classificationRule');

        return [
            'rule_type' => ['required', 'string', Rule::in(array_keys(ClassificationRule::ruleTypes()))],
            'match_value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('classification_rules')
                    ->where(fn ($query) => $query->where('rule_type', $this->input('rule_type')))
                    ->ignore($classificationRule?->id),
            ],
            'output_value' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:-999', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'match_value' => mb_strtoupper($this->normalize($this->input('match_value'))),
            'output_value' => $this->normalize($this->input('output_value')),
            'notes' => $this->nullable($this->input('notes')),
        ]);
    }

    public function normalizedRule(): array
    {
        return [
            'rule_type' => (string) $this->input('rule_type'),
            'match_value' => (string) $this->input('match_value'),
            'output_value' => (string) $this->input('output_value'),
            'priority' => (int) ($this->input('priority') ?? 100),
            'is_active' => $this->boolean('is_active', false),
            'notes' => $this->input('notes'),
        ];
    }

    private function normalize(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    private function nullable(mixed $value): ?string
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? null : $normalized;
    }
}
