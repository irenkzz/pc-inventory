<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassificationRuleRequest;
use App\Models\ClassificationRule;
use App\Models\Device;
use App\Services\Inventory\DeviceAssignmentOverrideService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ClassificationRuleController extends Controller
{
    public function index(): View
    {
        return view('admin.classification-rules.index', [
            'rules' => ClassificationRule::query()
                ->orderBy('rule_type')
                ->orderByDesc('is_active')
                ->orderByDesc('priority')
                ->orderBy('match_value')
                ->get()
                ->groupBy('rule_type'),
            'ruleTypes' => ClassificationRule::ruleTypes(),
        ]);
    }

    public function store(StoreClassificationRuleRequest $request): RedirectResponse
    {
        $rule = $request->normalizedRule();

        ClassificationRule::query()->updateOrCreate(
            [
                'rule_type' => $rule['rule_type'],
                'match_value' => $rule['match_value'],
            ],
            $rule,
        );

        return redirect()
            ->route('admin.classification-rules.index')
            ->with('status', 'Classification rule saved.');
    }

    public function update(StoreClassificationRuleRequest $request, ClassificationRule $classificationRule): RedirectResponse
    {
        $classificationRule->forceFill($request->normalizedRule())->save();

        return redirect()
            ->route('admin.classification-rules.index')
            ->with('status', 'Classification rule updated.');
    }

    public function destroy(ClassificationRule $classificationRule): RedirectResponse
    {
        $classificationRule->delete();

        return redirect()
            ->route('admin.classification-rules.index')
            ->with('status', 'Classification rule deleted.');
    }

    public function apply(DeviceAssignmentOverrideService $assignmentOverrides): RedirectResponse
    {
        $updated = 0;

        Device::query()
            ->orderBy('id')
            ->chunkById(100, function ($devices) use ($assignmentOverrides, &$updated): void {
                foreach ($devices as $device) {
                    $scannerPayload = $assignmentOverrides->latestScannerPayload($device);
                    if ($scannerPayload === []) {
                        continue;
                    }

                    $currentColumns = $assignmentOverrides->currentColumnsForDevice($device, $scannerPayload);
                    $changes = collect($currentColumns)->contains(
                        fn (mixed $value, string $key): bool => (string) $device->{$key} !== (string) $value,
                    );

                    if (! $changes) {
                        continue;
                    }

                    $device->forceFill($currentColumns)->save();
                    $updated++;
                }
            });

        return redirect()
            ->route('admin.classification-rules.index')
            ->with('status', "Classification rules applied to {$updated} device(s).");
    }
}
