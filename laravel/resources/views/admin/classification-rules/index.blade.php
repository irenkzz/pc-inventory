@extends('admin.layout')

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Inventory classification</p>
            <h2 class="dashboard-title">Rules</h2>
            <p class="dashboard-copy">
                Manage how scanner evidence is translated into portal departments and site names.
            </p>
        </div>
        <form method="post" action="{{ route('admin.classification-rules.apply') }}" class="dashboard-toolbar">
            @csrf
            <button type="submit" class="secondary">Apply to existing devices</button>
        </form>
    </section>

    <h3>Add Rule</h3>
    <form method="post" action="{{ route('admin.classification-rules.store') }}" class="form-grid">
        @csrf
        <label>
            Rule type
            <select name="rule_type" required>
                @foreach($ruleTypes as $type => $label)
                    <option value="{{ $type }}" @selected(old('rule_type') === $type)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Match value
            <input type="text" name="match_value" value="{{ old('match_value') }}" placeholder="BRT or HEAD OFFICE" required>
        </label>
        <label>
            Output value
            <input type="text" name="output_value" value="{{ old('output_value') }}" placeholder="Pemberitaan or Bhayangkara" required>
        </label>
        <label>
            Priority
            <input type="text" name="priority" value="{{ old('priority', '100') }}">
        </label>
        <label>
            Notes
            <input type="text" name="notes" value="{{ old('notes') }}">
        </label>
        <label>
            Active
            <select name="is_active">
                <option value="1" @selected(old('is_active', '1') === '1')>Yes</option>
                <option value="0" @selected(old('is_active') === '0')>No</option>
            </select>
        </label>
        <div class="form-actions">
            <button type="submit">Save rule</button>
        </div>
    </form>

    @foreach($ruleTypes as $type => $label)
        <div class="panel dashboard-section">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">{{ $label }}</h3>
                    <p class="panel-subtitle">
                        @if($type === \App\Models\ClassificationRule::TYPE_ASSET_PREFIX_DEPARTMENT)
                            Matches the first asset-name segment before characters like dash, underscore, dot, or space.
                        @else
                            Matches scanner site values exactly after whitespace and case normalization.
                        @endif
                    </p>
                </div>
                <span class="badge info">{{ ($rules[$type] ?? collect())->count() }} rules</span>
            </div>

            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Match</th>
                        <th>Output</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                    @forelse($rules[$type] ?? [] as $rule)
                        <tr>
                            <td colspan="6">
                                <form method="post" action="{{ route('admin.classification-rules.update', $rule) }}" class="form-grid" style="margin:0; box-shadow:none; border:0; padding:0; grid-template-columns: 1fr 1fr 90px 100px 1fr auto;">
                                    @csrf
                                    @method('put')
                                    <input type="hidden" name="rule_type" value="{{ $rule->rule_type }}">
                                    <label>
                                        <span class="muted">Match</span>
                                        <input type="text" name="match_value" value="{{ $rule->match_value }}" required>
                                    </label>
                                    <label>
                                        <span class="muted">Output</span>
                                        <input type="text" name="output_value" value="{{ $rule->output_value }}" required>
                                    </label>
                                    <label>
                                        <span class="muted">Priority</span>
                                        <input type="text" name="priority" value="{{ $rule->priority }}">
                                    </label>
                                    <label>
                                        <span class="muted">Active</span>
                                        <select name="is_active">
                                            <option value="1" @selected($rule->is_active)>Yes</option>
                                            <option value="0" @selected(! $rule->is_active)>No</option>
                                        </select>
                                    </label>
                                    <label>
                                        <span class="muted">Notes</span>
                                        <input type="text" name="notes" value="{{ $rule->notes }}">
                                    </label>
                                    <div class="actions" style="align-self:end">
                                        <button type="submit">Save</button>
                                    </div>
                                </form>
                                <form method="post" action="{{ route('admin.classification-rules.destroy', $rule) }}" class="actions" style="margin-top:8px">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="secondary">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No rules configured for this type.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    @endforeach
@endsection
