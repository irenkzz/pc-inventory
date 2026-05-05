<?php

namespace App\Services\Inventory;

use App\Models\ClassificationRule;
use Illuminate\Support\Collection;

class ClassificationRuleService
{
    private ?Collection $activeRules = null;

    public function departmentForAssetName(mixed $assetName): string
    {
        $prefix = $this->assetPrefix($assetName);
        if ($prefix === '') {
            return '';
        }

        $rule = $this->rulesOfType(ClassificationRule::TYPE_ASSET_PREFIX_DEPARTMENT)
            ->first(fn (ClassificationRule $rule): bool => $this->normalizedKey($rule->match_value) === $prefix);

        return $rule?->output_value ?? '';
    }

    public function siteForScannerValue(mixed $site): string
    {
        $site = $this->normalize($site);
        if ($site === '') {
            return '';
        }

        $siteKey = $this->normalizedKey($site);
        $rule = $this->rulesOfType(ClassificationRule::TYPE_SITE_ALIAS)
            ->first(fn (ClassificationRule $rule): bool => $this->normalizedKey($rule->match_value) === $siteKey);

        return $rule?->output_value ?? $site;
    }

    public function refresh(): void
    {
        $this->activeRules = null;
    }

    public function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    private function activeRules(): Collection
    {
        if ($this->activeRules !== null) {
            return $this->activeRules;
        }

        return $this->activeRules = ClassificationRule::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('match_value')
            ->get();
    }

    private function rulesOfType(string $ruleType): Collection
    {
        return $this->activeRules()
            ->where('rule_type', $ruleType)
            ->values();
    }

    private function assetPrefix(mixed $assetName): string
    {
        $assetName = $this->normalize($assetName);
        if ($assetName === '') {
            return '';
        }

        return $this->normalizedKey(strtok($assetName, '-_ .') ?: $assetName);
    }

    private function normalizedKey(mixed $value): string
    {
        return mb_strtoupper($this->normalize($value));
    }
}
