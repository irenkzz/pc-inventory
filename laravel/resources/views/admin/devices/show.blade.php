@extends('admin.layout')

@php
    $latestScan = $device->scans->sortByDesc('scan_time')->first();
    $snapshot = $latestScan?->snapshot?->snapshot_json ?? [];
    unset($snapshot['storage_health_json']);
    $latestNetwork = $device->networkObservations->first();

    $value = fn (string $key, string $fallback = ''): string => trim((string) data_get($snapshot, $key, $fallback));
    $display = fn (mixed $item): string => trim((string) $item) !== '' ? (string) $item : '-';
    $assignmentFormValue = function (string $input, mixed $manualValue, mixed $currentValue): string {
        if (old($input) !== null) {
            return (string) old($input);
        }

        $manual = trim((string) $manualValue);
        if ($manual !== '') {
            return $manual;
        }

        return trim((string) $currentValue);
    };
    $quantity = function (mixed $item, string $unit = '', int $precision = 0): string {
        if ($item === null || trim((string) $item) === '') {
            return '';
        }

        $formatted = $precision > 0
            ? \App\Support\InventoryFormat::decimal($item, $precision)
            : \App\Support\InventoryFormat::number($item);

        return $unit === '' ? $formatted : "{$formatted} {$unit}";
    };

    $listValue = function (string $raw): array {
        return array_values(array_filter(array_map('trim', explode(';', $raw))));
    };
    $specValue = function (mixed $raw): array {
        $value = trim((string) $raw);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(';', $value))));
    };

    $severityClass = function (mixed $severity): string {
        return match (strtolower((string) $severity)) {
            'critical' => 'danger',
            'medium' => 'warn',
            'minor' => 'info',
            default => '',
        };
    };
    $riskClass = fn (mixed $level): string => match (strtolower((string) $level)) {
        'healthy' => 'good',
        'watch' => 'info',
        'warning' => 'warn',
        'critical' => 'danger',
        default => '',
    };

    $identityMaxWeight = (int) ($device->identities->max('weight') ?? 0);
    $identityClass = $identityMaxWeight >= 70 ? 'good' : ($identityMaxWeight > 0 ? 'warn' : 'danger');
    $identityLabel = $identityMaxWeight >= 70 ? 'Strong identity' : ($identityMaxWeight > 0 ? 'Weak identity' : 'No identity evidence');
    $assignmentSource = $device->hasManualAssignmentOverride() ? 'Portal override' : 'Classification rules';

    $systemItems = [
        'Manufacturer' => $device->manufacturer,
        'Model' => $device->model,
        'System type' => $device->system_type,
        'Serial number' => $device->serial_no,
        'BIOS serial raw' => $value('bios_serial_raw'),
        'BIOS version' => $value('bios_version'),
        'System UUID' => $device->system_uuid,
    ];

    $boardItems = [
        'Motherboard' => $value('motherboard'),
        'Manufacturer' => $value('motherboard_manufacturer'),
        'Product' => $value('motherboard_product'),
        'Version' => $value('motherboard_version'),
        'Serial' => $device->motherboard_serial,
    ];

    $hardwareItems = [
        'CPU' => $value('cpu'),
        'GPU' => $value('gpu'),
        'GPU detail' => $value('gpu_detail'),
        'RAM GB' => $quantity($value('ram_gb'), 'GB'),
        'RAM slots used' => $quantity($value('ram_slots_used'), 'slot(s)'),
        'RAM manufacturers' => $value('ram_manufacturers'),
        'RAM part numbers' => $value('ram_part_numbers'),
        'RAM serial numbers' => $value('ram_serial_numbers'),
        'RAM speeds MHz' => $value('ram_speeds_mhz'),
        'RAM types' => $value('ram_types'),
        'RAM slots' => $value('ram_slots'),
        'RAM detail' => $value('ram_detail'),
        'Disk summary' => $value('disk'),
        'Disk detail' => $value('disk_detail'),
        'SSD TBW bytes' => $quantity($value('ssd_tbw_bytes'), 'bytes'),
        'SSD TBW GB' => $quantity($value('ssd_tbw_gb'), 'GB', 1),
        'SSD used %' => $value('ssd_percentage_used') !== '' ? \App\Support\InventoryFormat::percent($value('ssd_percentage_used')) : '',
        'SSD power-on hours' => $quantity($value('ssd_power_on_hours'), 'hour(s)'),
        'SSD health source' => $value('ssd_health_source'),
        'SSD health detail' => $value('ssd_health_detail'),
    ];

    $osItems = [
        'OS' => $value('os'),
        'OS version' => $value('os_version'),
        'OS build' => $value('os_build'),
        'OS install date' => $value('os_install_date'),
    ];

    $networkItems = [
        'MAC address' => $device->mac_address,
        'IP address' => $value('ip_address', (string) $latestNetwork?->ip_address),
        'IP prefix' => $value('ip_prefix', (string) $latestNetwork?->ip_prefix),
        'Prefix length' => $value('prefix_length', (string) $latestNetwork?->prefix_length),
        'Default gateway' => $value('default_gateway', (string) $latestNetwork?->default_gateway),
        'DNS suffix' => $value('dns_suffix', (string) $latestNetwork?->dns_suffix),
        'Interface' => $value('network_interface', (string) $latestNetwork?->network_interface),
        'Wi-Fi SSID' => $value('wifi_ssid', (string) $latestNetwork?->wifi_ssid),
    ];

    $hashItems = [
        'Canonical device ID' => $value('canonical_device_id'),
        'Hardware hash' => $device->hardware_hash,
    ];

    $printers = $listValue($value('installed_printers'));
    $latestStorageHealth = ($device->relationLoaded('storageHealthObservations') ? $device->storageHealthObservations : collect())
        ->sortByDesc('observed_at')
        ->unique('disk_key')
        ->values();
@endphp

@section('content')
    <section class="detail-hero">
        <div>
            <p class="dashboard-kicker">Device inventory</p>
            <div class="detail-title-row">
                <h2>{{ $device->current_asset_code ?: 'Device #' . $device->id }}</h2>
                <span class="badge {{ $device->status === 'active' ? 'good' : 'warn' }}">{{ $device->status ?: 'active' }}</span>
                <span class="badge {{ $identityClass }}">{{ $identityLabel }}</span>
                <span class="badge {{ $device->hasManualAssignmentOverride() ? 'info' : '' }}">{{ $assignmentSource }}</span>
            </div>
            <div class="detail-meta">
                <span>Latest scan: {{ $latestScan ? \App\Support\InventoryTime::format($latestScan->scan_time) : '-' }}</span>
                <span>Source: {{ $latestScan?->scan_source ?: '-' }}</span>
                <span>Raw file: {{ $latestScan?->raw_filename ?: '-' }}</span>
            </div>
        </div>
        <div class="quick-nav">
            <a class="btn secondary" href="{{ route('admin.inventory-review.index') }}">Review queue</a>
            <a class="btn secondary" href="#hardware">Hardware</a>
            <a class="btn secondary" href="#storage-health">Storage</a>
            <a class="btn secondary" href="#audit">Audit</a>
            <a class="btn" href="#assignment">Assignment</a>
        </div>
    </section>

    @if($latestScan === null)
        <div class="notice">No scan snapshot has been stored for this device yet.</div>
    @endif

    <section class="grid" aria-label="Device summary">
        <div class="info-tile">
            <span class="info-label">Department</span>
            <span class="info-value">{{ $display($device->current_department) }}</span>
            <span class="info-note">{{ $assignmentSource }}</span>
        </div>
        <div class="info-tile">
            <span class="info-label">Site / Room</span>
            <span class="info-value">{{ $display($device->current_site) }}</span>
            <span class="info-note">{{ trim(($device->current_location ?? '') . ' ' . ($device->current_room ?? '')) ?: 'No location detail' }}</span>
        </div>
        <div class="info-tile">
            <span class="info-label">Assigned user</span>
            <span class="info-value">{{ $display($device->current_user_name) }}</span>
            <span class="info-note">Raw: {{ $display($value('user_raw')) }}</span>
        </div>
        <div class="info-tile">
            <span class="info-label">Network</span>
            <span class="info-value">{{ $display($value('ip_address', (string) $latestNetwork?->ip_address)) }}</span>
            <span class="info-note">{{ $display($device->mac_address) }}</span>
        </div>
        <div class="info-tile">
            <span class="info-label">Hardware</span>
            <span class="info-value">{{ $display(trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? ''))) }}</span>
            <span class="info-note">{{ $display($value('cpu')) }}</span>
        </div>
        <div class="info-tile">
            <span class="info-label">Last seen</span>
            <span class="info-value">@inventoryTime($device->last_seen_at)</span>
            <span class="info-note">{{ $device->changes->count() }} recorded change(s)</span>
        </div>
    </section>

    <section id="assignment" class="detail-grid dashboard-section section-anchor">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Assignment Override</h3>
                    <p class="panel-subtitle">Manual values take precedence over scanner evidence and classification rules.</p>
                </div>
                @if($device->manual_assignment_updated_at)
                    <span class="badge info">Updated {{ \App\Support\InventoryTime::format($device->manual_assignment_updated_at) }}</span>
                @endif
            </div>
            <form method="post" action="{{ route('admin.devices.assignment.update', $device) }}" class="form-grid" style="margin:0; box-shadow:none;">
                @csrf
                <label>
                    Department
                    <input type="text" name="department" value="{{ $assignmentFormValue('department', $device->manual_department, $device->current_department) }}">
                </label>
                <label>
                    Site
                    <input type="text" name="site" value="{{ $assignmentFormValue('site', $device->manual_site, $device->current_site) }}">
                </label>
                <label>
                    Location
                    <input type="text" name="location" value="{{ $assignmentFormValue('location', $device->manual_location, $device->current_location) }}">
                </label>
                <label>
                    Room
                    <input type="text" name="room" value="{{ $assignmentFormValue('room', $device->manual_room, $device->current_room) }}">
                </label>
                <div class="form-actions">
                    <button type="submit">Save assignment</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Current Assignment</h3>
                    <p class="panel-subtitle">Authoritative values used by reports and dashboard summaries.</p>
                </div>
            </div>
            <dl class="compact-dl">
                <dt>Asset code</dt><dd>{{ $display($device->current_asset_code) }}</dd>
                <dt>User</dt><dd>{{ $display($device->current_user_name) }}</dd>
                <dt>Department</dt><dd>{{ $display($device->current_department) }}</dd>
                <dt>Site</dt><dd>{{ $display($device->current_site) }}</dd>
                <dt>Location</dt><dd>{{ $display($device->current_location) }}</dd>
                <dt>Room</dt><dd>{{ $display($device->current_room) }}</dd>
            </dl>
        </div>
    </section>

    <section id="hardware" class="dashboard-section section-anchor">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Hardware and System</h3>
                    <p class="panel-subtitle">Latest normalized snapshot from the client runner.</p>
                </div>
            </div>
            <div class="detail-grid">
                <div>
                    <h3>Hardware</h3>
                    <dl class="compact-dl">
                        @foreach($hardwareItems as $label => $item)
                            <dt>{{ $label }}</dt>
                            <dd>
                                @php($parts = $specValue($item))
                                @if(count($parts) > 1)
                                    <ul class="spec-list">
                                        @foreach($parts as $part)
                                            <li>{{ $part }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    {{ $display($item) }}
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </div>
                <div class="stack">
                    <div>
                        <h3>System</h3>
                        <dl class="compact-dl">
                            @foreach($systemItems as $label => $item)
                                <dt>{{ $label }}</dt>
                                <dd>{{ $display($item) }}</dd>
                            @endforeach
                        </dl>
                    </div>
                    <div>
                        <h3>Motherboard</h3>
                        <dl class="compact-dl">
                            @foreach($boardItems as $label => $item)
                                <dt>{{ $label }}</dt>
                                <dd>{{ $display($item) }}</dd>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="storage-health" class="dashboard-section section-anchor">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Storage Health</h3>
                    <p class="panel-subtitle">Latest per-disk SMART risk observation. TBW may be unavailable when the controller blocks passthrough.</p>
                </div>
                <a class="btn secondary" href="{{ route('admin.storage-health.index') }}">Storage dashboard</a>
            </div>
            <div class="table-scroll">
                <table>
                    <tr><th>Disk</th><th>Risk</th><th>Reasons</th><th>TBW</th><th>Used</th><th>Hours</th><th>Temp</th><th>SMART</th><th>Action</th></tr>
                    @forelse($latestStorageHealth as $observation)
                        <tr>
                            <td>
                                {{ $display($observation->disk_model) }}
                                <div class="muted">{{ $display($observation->disk_serial) }} · {{ $display($observation->disk_type) }} · {{ $display($observation->capacity_gb) }} GB</div>
                            </td>
                            <td><span class="badge {{ $riskClass($observation->risk_level) }}">{{ $observation->risk_level }}</span></td>
                            <td>
                                @php($reasons = array_values(array_filter($observation->risk_reasons ?? [])))
                                @if(count($reasons) > 1)
                                    <ul class="spec-list compact">
                                        @foreach($reasons as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    {{ $reasons[0] ?? '-' }}
                                @endif
                            </td>
                                <td>{{ $observation->tbw_gb === null ? 'Not available' : \App\Support\InventoryFormat::decimal($observation->tbw_gb, 1) . ' GB' }}</td>
                                <td>{{ \App\Support\InventoryFormat::percent($observation->percentage_used) }}</td>
                                <td>{{ \App\Support\InventoryFormat::number($observation->power_on_hours) }}</td>
                            <td>{{ $observation->temperature_c === null ? '-' : $observation->temperature_c . ' C' }}</td>
                            <td>
                                {{ $display($observation->smart_health_status) }}
                                @if($observation->storage_health_detail)
                                    <div class="muted">{{ $observation->storage_health_detail }}</div>
                                @endif
                            </td>
                            <td>{{ $observation->recommended_action }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9">No per-disk storage health observations have been imported yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </section>

    <section class="detail-grid dashboard-section">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Network</h3>
                    <p class="panel-subtitle">Latest network observation and scan-provided hints.</p>
                </div>
            </div>
            <dl class="compact-dl">
                @foreach($networkItems as $label => $item)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $display($item) }}</dd>
                @endforeach
            </dl>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Operating System</h3>
                    <p class="panel-subtitle">OS fields captured by the runner.</p>
                </div>
            </div>
            <dl class="compact-dl">
                @foreach($osItems as $label => $item)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $display($item) }}</dd>
                @endforeach
            </dl>
        </div>
    </section>

    <section class="detail-grid dashboard-section">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Printers</h3>
                    <p class="panel-subtitle">Printer values from the latest scan snapshot.</p>
                </div>
                <span class="badge info">{{ count($printers) }} printer(s)</span>
            </div>
            @if($printers !== [])
                <div class="table-scroll">
                    <table>
                        <tr><th>Name</th></tr>
                        @foreach($printers as $printer)
                            <tr><td>{{ $printer }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @else
                <div class="empty-state">No printers were recorded in the latest scan.</div>
            @endif
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Peripherals</h3>
                    <p class="panel-subtitle">Dynamic attached devices are tracked separately from major hardware changes.</p>
                </div>
                <span class="badge info">{{ $device->peripherals->count() }} item(s)</span>
            </div>
            @if($device->peripherals->isNotEmpty())
                <div class="table-scroll">
                    <table>
                        <tr><th>Type</th><th>Name</th><th>Detail</th><th>Observed</th></tr>
                        @foreach($device->peripherals as $peripheral)
                            <tr>
                                <td>{{ $peripheral->peripheral_type }}</td>
                                <td>{{ $peripheral->name }}</td>
                                <td>{{ $peripheral->detail }}</td>
                                <td>@inventoryTime($peripheral->observed_at)</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @else
                <div class="empty-state">No peripherals were recorded.</div>
            @endif
        </div>
    </section>

    <section id="audit" class="dashboard-section section-anchor">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Change History</h3>
                    <p class="panel-subtitle">Audit trail for hardware, assignment, location, and network changes.</p>
                </div>
                <span class="badge info">{{ $device->changes->count() }} change(s)</span>
            </div>
            <div class="table-scroll">
                <table>
                    <tr><th>Observed</th><th>Severity</th><th>Group</th><th>Field</th><th>Old</th><th>New</th></tr>
                    @forelse($device->changes as $change)
                        <tr>
                            <td>@inventoryTime($change->observed_at)</td>
                            <td><span class="badge {{ $severityClass($change->severity) }}">{{ $change->severity }}</span></td>
                            <td>{{ $change->change_group }}</td>
                            <td>{{ $change->field_name }}</td>
                            <td>{{ $display($change->old_value) }}</td>
                            <td>{{ $display($change->new_value) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No changes recorded.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </section>

    <section class="detail-grid dashboard-section">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Identity Evidence</h3>
                    <p class="panel-subtitle">Weighted evidence used for device matching. Strong evidence is weight 70 or higher.</p>
                </div>
                <span class="badge {{ $identityClass }}">{{ $identityMaxWeight }} max weight</span>
            </div>
            <div class="table-scroll">
                <table>
                    <tr><th>Type</th><th>Value</th><th>Weight</th><th>First seen</th><th>Last seen</th></tr>
                    @forelse($device->identities as $identity)
                        <tr>
                            <td>{{ $identity->identity_type }}</td>
                            <td>{{ $identity->identity_value }}</td>
                            <td>{{ $identity->weight }}</td>
                            <td>@inventoryTime($identity->first_seen_at)</td>
                            <td>@inventoryTime($identity->last_seen_at)</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No identity evidence recorded.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Identity Hashes</h3>
                    <p class="panel-subtitle">Stored hashes from the latest snapshot and current projection.</p>
                </div>
            </div>
            <dl class="compact-dl">
                @foreach($hashItems as $label => $item)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $display($item) }}</dd>
                @endforeach
            </dl>
        </div>
    </section>

    <section class="detail-grid dashboard-section">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Scan Timeline</h3>
                    <p class="panel-subtitle">Raw evidence snapshots retained for traceability.</p>
                </div>
                <span class="badge info">{{ $device->scans->count() }} scan(s)</span>
            </div>
            <div class="table-scroll">
                <table>
                    <tr><th>Scan time</th><th>Source</th><th>Raw file</th><th>Raw hash</th><th>Format</th><th>Hardware hash</th></tr>
                    @forelse($device->scans->sortByDesc('scan_time') as $scan)
                        <tr>
                            <td>@inventoryTime($scan->scan_time)</td>
                            <td>{{ $scan->scan_source }}</td>
                            <td>
                                @if($scan->rawFile)
                                    <a href="{{ route('admin.raw-evidence.show', $scan->rawFile) }}">{{ $scan->raw_filename }}</a>
                                @else
                                    {{ $scan->raw_filename }}
                                @endif
                            </td>
                            <td><code>{{ $scan->raw_hash ? substr($scan->raw_hash, 0, 12) : '-' }}</code></td>
                            <td>{{ $scan->raw_format }}</td>
                            <td>{{ $scan->snapshot?->hardware_hash }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No scans recorded.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Assignment Timeline</h3>
                    <p class="panel-subtitle">Historical user, department, location, room, and site mapping.</p>
                </div>
                <span class="badge info">{{ $device->assignments->count() }} assignment(s)</span>
            </div>
            <div class="table-scroll">
                <table>
                    <tr><th>Started</th><th>Ended</th><th>User</th><th>Department</th><th>Location</th><th>Room</th><th>Site</th></tr>
                    @forelse($device->assignments as $assignment)
                        <tr>
                            <td>@inventoryTime($assignment->started_at)</td>
                            <td>@inventoryTime($assignment->ended_at)</td>
                            <td>{{ $display($assignment->user_name) }}</td>
                            <td>{{ $display($assignment->department) }}</td>
                            <td>{{ $display($assignment->location) }}</td>
                            <td>{{ $display($assignment->room) }}</td>
                            <td>{{ $display($assignment->site_estimated) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No assignments recorded.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Latest Raw Snapshot</h3>
                <p class="panel-subtitle">Archived scanner evidence shown for audit and troubleshooting.</p>
            </div>
        </div>
        <pre class="raw-snapshot">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>
@endsection
