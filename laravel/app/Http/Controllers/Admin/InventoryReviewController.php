<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryReviewController extends Controller
{
    public function index(Request $request): View
    {
        $issue = trim((string) $request->query('issue', 'all'));
        if (! array_key_exists($issue, $this->issueLabels())) {
            $issue = 'all';
        }

        $devices = Device::query()
            ->with(['latestNetworkObservation', 'identities'])
            ->when($issue !== 'all', fn (Builder $query) => $this->applyIssueFilter($query, $issue))
            ->orderByDesc('last_seen_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.inventory-review.index', [
            'devices' => $devices,
            'issue' => $issue,
            'issueLabels' => $this->issueLabels(),
            'issueCounts' => $this->issueCounts(),
        ]);
    }

    private function issueLabels(): array
    {
        return [
            'all' => 'All review items',
            'missing_department' => 'Missing department',
            'missing_site' => 'Missing site',
            'missing_user' => 'Missing user',
            'generic_asset' => 'Generic asset name',
            'weak_identity' => 'Weak identity evidence',
        ];
    }

    private function issueCounts(): array
    {
        $counts = ['all' => Device::query()->count()];

        foreach (array_keys($this->issueLabels()) as $issue) {
            if ($issue === 'all') {
                continue;
            }

            $counts[$issue] = Device::query()
                ->where(fn (Builder $query) => $this->applyIssueFilter($query, $issue))
                ->count();
        }

        return $counts;
    }

    private function applyIssueFilter(Builder $query, string $issue): void
    {
        match ($issue) {
            'missing_department' => $query->where(fn (Builder $nested) => $nested
                ->whereNull('current_department')
                ->orWhere('current_department', '')),
            'missing_site' => $query->where(fn (Builder $nested) => $nested
                ->whereNull('current_site')
                ->orWhere('current_site', '')),
            'missing_user' => $query->where(fn (Builder $nested) => $nested
                ->whereNull('current_user_name')
                ->orWhere('current_user_name', '')),
            'generic_asset' => $query->where(fn (Builder $nested) => $nested
                ->whereNull('current_asset_code')
                ->orWhere('current_asset_code', '')
                ->orWhere('current_asset_code', 'like', 'DESKTOP-%')),
            'weak_identity' => $query->whereDoesntHave(
                'identities',
                fn (Builder $identityQuery) => $identityQuery->where('weight', '>=', 70),
            ),
            default => null,
        };
    }
}
