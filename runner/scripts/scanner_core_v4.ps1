# ============================================
# Inventory Collector v4
# Enhanced hardware, peripherals, network site estimation, and optional remote upload
# ============================================

[CmdletBinding()]
param(
    [string]$Location = "",
    [string]$Room = "",
    [switch]$AppendDateToFileName,
    [switch]$SkipRemoteUpload
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "SilentlyContinue"

function Get-TrimmedString {
    param([object]$Value)
    if ($null -eq $Value) { return "" }
    return ([string]$Value).Trim()
}

function Normalize-Whitespace {
    param([string]$Text)
    if ([string]::IsNullOrWhiteSpace($Text)) { return "" }
    return (($Text -replace '\s+', ' ').Trim())
}

function Safe-Upper {
    param([string]$Text)
    return (Normalize-Whitespace $Text).ToUpperInvariant()
}

function Get-PciSubsystemVendorId {
    param([string]$PnpDeviceId)

    if ([string]::IsNullOrWhiteSpace($PnpDeviceId)) { return "" }
    $match = [regex]::Match($PnpDeviceId, 'SUBSYS_([0-9A-Fa-f]{8})')
    if (-not $match.Success) { return "" }

    return $match.Groups[1].Value.Substring(4, 4).ToUpperInvariant()
}

function Get-GpuBoardManufacturer {
    param([string]$SubsystemVendorId)

    $id = (Normalize-Whitespace $SubsystemVendorId).ToUpperInvariant()
    if (-not $id) { return "" }

    $vendors = @{
        '1025' = 'Acer'
        '1028' = 'Dell'
        '103C' = 'HP'
        '1043' = 'ASUS'
        '10B0' = 'Inno3D'
        '1458' = 'Gigabyte'
        '1462' = 'MSI'
        '148C' = 'PowerColor'
        '1565' = 'Biostar'
        '1569' = 'Palit/Gainward'
        '1682' = 'XFX'
        '17AA' = 'Lenovo'
        '1849' = 'ASRock'
        '196E' = 'PNY'
        '19DA' = 'Zotac'
        '1B4C' = 'GALAX/KFA2'
        '1DA2' = 'Sapphire'
        '3842' = 'EVGA'
        '7377' = 'Colorful'
    }

    if ($vendors.ContainsKey($id)) { return $vendors[$id] }
    return ""
}

function Get-NestedPropertyValue {
    param(
        [object]$Object,
        [string]$Path
    )

    if ($null -eq $Object -or [string]::IsNullOrWhiteSpace($Path)) { return $null }

    $current = $Object
    foreach ($segment in $Path.Split('.')) {
        if ($null -eq $current) { return $null }

        if ($current -is [System.Collections.IDictionary]) {
            if (-not $current.Contains($segment)) { return $null }
            $current = $current[$segment]
            continue
        }

        $prop = $current.PSObject.Properties[$segment]
        if (-not $prop) { return $null }
        $current = $prop.Value
    }

    return $current
}

function Convert-ToUInt64Value {
    param([object]$Value)

    if ($null -eq $Value) { return $null }

    $text = Normalize-Whitespace ([string]$Value)
    if (-not $text) { return $null }

    $digits = ($text -replace '[^0-9]', '')
    if (-not $digits) { return $null }

    try {
        return [UInt64]::Parse($digits, [Globalization.CultureInfo]::InvariantCulture)
    }
    catch {
        return $null
    }
}

function Invoke-SmartCtlBounded {
    # Runs smartctl with a 30s cap; returns stdout+stderr text (like `2>&1 | Out-String`), '' on timeout.
    param([string]$Path, [string[]]$Arguments)

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $Path
    $psi.Arguments = ($Arguments | ForEach-Object { if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ } }) -join ' '
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $proc = [System.Diagnostics.Process]::Start($psi)
    try {
        $outTask = $proc.StandardOutput.ReadToEndAsync()
        $errTask = $proc.StandardError.ReadToEndAsync()
        if (-not $proc.WaitForExit(30000)) {
            try { $proc.Kill() } catch { }
            return ''
        }
        return ($outTask.Result + $errTask.Result)
    }
    finally {
        $proc.Dispose()
    }
}

function Get-SmartCtlPath {
    param([string]$ScriptRoot)

    $runnerRoot = Split-Path -Parent $ScriptRoot
    $candidates = @(
        (Join-Path $ScriptRoot 'tools\smartctl.exe'),
        (Join-Path $ScriptRoot 'tools\smartctl64.exe'),
        (Join-Path $ScriptRoot 'smartctl.exe'),
        (Join-Path $ScriptRoot 'smartctl64.exe'),
        (Join-Path $runnerRoot 'tools\smartctl.exe'),
        (Join-Path $runnerRoot 'tools\smartctl64.exe'),
        (Join-Path $runnerRoot 'smartctl.exe'),
        (Join-Path $runnerRoot 'smartctl64.exe')
    )

    foreach ($candidate in $candidates) {
        if (Test-Path $candidate) {
            return $candidate
        }
    }

    try {
        foreach ($commandName in @('smartctl.exe', 'smartctl64.exe')) {
            $command = Get-Command $commandName -ErrorAction Stop | Select-Object -First 1
            if ($command -and $command.Source) {
                return [string]$command.Source
            }
        }
    }
    catch {
    }

    return ""
}

function Get-SmartAtaBytesWritten {
    param([object[]]$AttributeTable)

    foreach ($attribute in @($AttributeTable)) {
        $name = Safe-Upper (Get-TrimmedString (Get-NestedPropertyValue -Object $attribute -Path 'name'))
        if (-not $name) { continue }
        $rawValue = Convert-ToUInt64Value (Get-NestedPropertyValue -Object $attribute -Path 'raw.value')
        if ($null -eq $rawValue) { continue }

        switch ($name) {
            'HOST_WRITES_32MIB' {
                return [PSCustomObject]@{
                    Bytes = [UInt64]($rawValue * 32MB)
                    Source = 'smartctl_ata_host_writes_32mib'
                }
            }
            'TOTAL_LBAS_WRITTEN' {
                return [PSCustomObject]@{
                    Bytes = [UInt64]($rawValue * 512)
                    Source = 'smartctl_ata_total_lbas_written'
                }
            }
            'TOTAL_HOST_WRITES_GIB' {
                return [PSCustomObject]@{
                    Bytes = [UInt64]($rawValue * 1GB)
                    Source = 'smartctl_ata_total_host_writes_gib'
                }
            }
        }
    }

    return $null
}

function Get-SmartAttributeRaw {
    param(
        [object[]]$AttributeTable,
        [string[]]$Names
    )

    $nameSet = @{}
    foreach ($item in @($Names)) {
        $nameSet[(Safe-Upper $item)] = $true
    }

    foreach ($attribute in @($AttributeTable)) {
        $name = Safe-Upper (Get-TrimmedString (Get-NestedPropertyValue -Object $attribute -Path 'name'))
        if (-not $name) { continue }
        if (-not $nameSet.ContainsKey($name)) { continue }

        $rawValue = Convert-ToUInt64Value (Get-NestedPropertyValue -Object $attribute -Path 'raw.value')
        if ($null -ne $rawValue) {
            return $rawValue
        }
    }

    return $null
}

function ConvertTo-CompactJson {
    param([object]$Value)

    if ($null -eq $Value) { return "" }

    try {
        return ($Value | ConvertTo-Json -Depth 24 -Compress)
    }
    catch {
        return ""
    }
}

function Get-DiskWriteTelemetry {
    param(
        [string]$SmartCtlPath,
        [string]$DiskIndex,
        [string]$Model
    )

    $empty = [PSCustomObject]@{
        BytesWritten = ''
        TbwGB = ''
        PercentageUsed = ''
        PowerOnHours = ''
        SmartAvailable = ''
        SmartHealthStatus = ''
        TemperatureC = ''
        AvailableSpare = ''
        MediaErrors = ''
        ReallocatedSectorCount = ''
        CurrentPendingSector = ''
        OfflineUncorrectable = ''
        HealthSource = ''
        HealthDetail = ''
    }

    if ([string]::IsNullOrWhiteSpace($SmartCtlPath) -or [string]::IsNullOrWhiteSpace($DiskIndex)) {
        $empty.HealthDetail = 'smartctl.exe not found or disk index unavailable'
        return $empty
    }

    $devicePath = "/dev/pd$DiskIndex"
    $probeVariants = @(
        @($devicePath),
        @('-d', 'auto', $devicePath),
        @('-d', 'sat', $devicePath),
        @('-d', 'scsi', $devicePath),
        @('-d', 'nvme', $devicePath)
    )
    try {
        $jsonText = ''
        $sourceMethod = ''
        $lastProbeDetail = ''
        foreach ($variant in $probeVariants) {
            $candidate = Invoke-SmartCtlBounded -Path $SmartCtlPath -Arguments (@('-a', '-j') + @($variant))
            if (-not [string]::IsNullOrWhiteSpace($candidate)) {
                $lastProbeDetail = Normalize-Whitespace $candidate
                try {
                    $null = $candidate | ConvertFrom-Json -ErrorAction Stop
                    $jsonText = $candidate
                    $sourceMethod = if ($variant.Count -gt 1) { 'smartctl_' + $variant[1] } else { 'smartctl_auto' }
                    break
                }
                catch {
                }
            }
        }

        if ([string]::IsNullOrWhiteSpace($jsonText)) {
            $empty.HealthDetail = $(if ($lastProbeDetail) { "smartctl could not read JSON: $lastProbeDetail" } else { 'smartctl returned no readable SMART JSON' })
            return $empty
        }

        $smart = $jsonText | ConvertFrom-Json -ErrorAction Stop
        $attributeTable = @(Get-NestedPropertyValue -Object $smart -Path 'ata_smart_attributes.table')
        $detailParts = @()
        $source = ''
        $bytesWritten = $null
        $smartAvailable = Get-NestedPropertyValue -Object $smart -Path 'smart_support.available'
        $smartPassed = Get-NestedPropertyValue -Object $smart -Path 'smart_status.passed'
        $smartHealthStatus = ''
        if ($null -ne $smartPassed) {
            $smartHealthStatus = if ($smartPassed -eq $true) { 'passed' } else { 'failed' }
        }

        $nvmeDataUnits = Convert-ToUInt64Value (Get-NestedPropertyValue -Object $smart -Path 'nvme_smart_health_information_log.data_units_written')
        if ($null -ne $nvmeDataUnits) {
            $bytesWritten = [UInt64]($nvmeDataUnits * 512000)
            $source = 'smartctl_nvme_data_units_written'
        }
        else {
            $ataBytes = Get-SmartAtaBytesWritten -AttributeTable $attributeTable
            if ($ataBytes) {
                $bytesWritten = $ataBytes.Bytes
                $source = $ataBytes.Source
            }
        }

        $percentageUsed = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'nvme_smart_health_information_log.percentage_used')
        if (-not $percentageUsed) {
            foreach ($attribute in $attributeTable) {
                $name = Safe-Upper (Get-TrimmedString (Get-NestedPropertyValue -Object $attribute -Path 'name'))
                if (-not $name) { continue }
                $rawValue = Convert-ToUInt64Value (Get-NestedPropertyValue -Object $attribute -Path 'raw.value')
                if ($null -eq $rawValue) { continue }

                switch ($name) {
                    'PERCENT_LIFETIME_REMAIN' {
                        $percentageUsed = [string][Math]::Max(0, (100 - [int]$rawValue))
                        break
                    }
                    'MEDIA_WEAROUT_INDICATOR' {
                        $percentageUsed = [string][Math]::Max(0, (100 - [int]$rawValue))
                        break
                    }
                }
            }
        }

        $powerOnHours = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'power_on_time.hours')
        if (-not $powerOnHours) {
            $powerOnHours = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'power_on_hours')
        }
        if (-not $powerOnHours) {
            foreach ($attribute in $attributeTable) {
                $name = Safe-Upper (Get-TrimmedString (Get-NestedPropertyValue -Object $attribute -Path 'name'))
                if (-not $name) { continue }
                if ($name -eq 'POWER_ON_HOURS' -or $name -eq 'POH') {
                    $rawHours = Convert-ToUInt64Value (Get-NestedPropertyValue -Object $attribute -Path 'raw.value')
                    if ($null -ne $rawHours) {
                        $powerOnHours = [string]$rawHours
                        break
                    }
                }
            }
        }

        $temperatureC = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'temperature.current')
        if (-not $temperatureC) {
            $temperatureC = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'nvme_smart_health_information_log.temperature')
        }
        if (-not $temperatureC) {
            $temperatureRaw = Get-SmartAttributeRaw -AttributeTable $attributeTable -Names @('TEMPERATURE_CELSIUS', 'TEMPERATURE_INTERNAL', 'AIRFLOW_TEMPERATURE_CEL', 'TEMPERATURE')
            if ($null -ne $temperatureRaw) { $temperatureC = [string]$temperatureRaw }
        }

        $availableSpare = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'nvme_smart_health_information_log.available_spare')
        $mediaErrors = Get-TrimmedString (Get-NestedPropertyValue -Object $smart -Path 'nvme_smart_health_information_log.media_errors')
        $reallocated = Get-SmartAttributeRaw -AttributeTable $attributeTable -Names @('REALLOCATED_SECTOR_CT', 'REALLOCATED_EVENT_COUNT')
        $pending = Get-SmartAttributeRaw -AttributeTable $attributeTable -Names @('CURRENT_PENDING_SECTOR')
        $offlineUncorrectable = Get-SmartAttributeRaw -AttributeTable $attributeTable -Names @('OFFLINE_UNCORRECTABLE')

        $tbwGb = ''
        if ($null -ne $bytesWritten) {
            $tbwGb = [math]::Round(($bytesWritten / 1000000000), 1).ToString([Globalization.CultureInfo]::InvariantCulture)
            $detailParts += "TBW $tbwGb GB"
        }
        if ($percentageUsed) {
            $detailParts += "Used $percentageUsed%"
        }
        if ($powerOnHours) {
            $detailParts += "Power-On $powerOnHours h"
        }
        if ($temperatureC) {
            $detailParts += "Temp $temperatureC C"
        }
        if ($smartHealthStatus) {
            $detailParts += "SMART $smartHealthStatus"
        }
        if ($source) {
            $detailParts += "Source $source"
        }
        if ($sourceMethod) {
            $detailParts += "Method $sourceMethod"
        }

        return [PSCustomObject]@{
            BytesWritten = $(if ($null -ne $bytesWritten) { [string]$bytesWritten } else { '' })
            TbwGB = $tbwGb
            PercentageUsed = $percentageUsed
            PowerOnHours = $powerOnHours
            SmartAvailable = $(if ($null -ne $smartAvailable) { [string]([bool]$smartAvailable) } else { '' })
            SmartHealthStatus = $smartHealthStatus
            TemperatureC = $temperatureC
            AvailableSpare = $availableSpare
            MediaErrors = $mediaErrors
            ReallocatedSectorCount = $(if ($null -ne $reallocated) { [string]$reallocated } else { '' })
            CurrentPendingSector = $(if ($null -ne $pending) { [string]$pending } else { '' })
            OfflineUncorrectable = $(if ($null -ne $offlineUncorrectable) { [string]$offlineUncorrectable } else { '' })
            HealthSource = $(if ($source) { $source } else { $sourceMethod })
            HealthDetail = ($detailParts -join ' | ')
        }
    }
    catch {
        $empty.HealthDetail = "smartctl parse failed: $($_.Exception.Message)"
        return $empty
    }
}

function Format-DiskMetricList {
    param(
        [object[]]$DiskRows,
        [string]$ValueProperty
    )

    $items = @()
    foreach ($row in @($DiskRows)) {
        $property = $row.PSObject.Properties[$ValueProperty]
        if (-not $property) { continue }
        $value = Get-TrimmedString $property.Value
        if (-not $value) { continue }

        $label = "Disk{0}" -f (Get-TrimmedString $row.Index)
        $model = Get-TrimmedString $row.Model
        if ($model) {
            $label = "$label $model"
        }

        $items += ("{0}={1}" -f $label.Trim(), $value)
    }

    return ($items -join '; ')
}

function Test-GenericIdentityValue {
    param([string]$Text)
    $v = Safe-Upper $Text
    if ([string]::IsNullOrWhiteSpace($v)) { return $true }

    $badValues = @(
        'DEFAULT STRING',
        'TO BE FILLED BY O.E.M.',
        'TO BE FILLED BY OEM',
        'SYSTEM SERIAL NUMBER',
        'SERIALNUMBER',
        'SERIAL NUMBER',
        'UNKNOWN',
        'NONE',
        'NOT APPLICABLE',
        'NOT AVAILABLE',
        'NO SERIAL',
        'N/A',
        'INVALID',
        'OEM',
        'DEFAULT',
        '123456789',
        '1234567890',
        '0123456789',
        '01234567890',
        'FFFFFFFF',
        '0000000000',
        '00000000',
        'DEFAULTSTRING'
    )

    if ($badValues -contains $v) { return $true }
    if ($v -match '^(DEFAULT|UNKNOWN|OEM|NONE|SERIAL)[\s\-_]*') { return $true }
    if ($v -match '^0+$') { return $true }
    if ($v -match '^F+$') { return $true }
    if ($v -match '^\s*$') { return $true }
    return $false
}

function Normalize-IdentityValue {
    param([string]$Text)
    $v = Normalize-Whitespace $Text
    if (Test-GenericIdentityValue $v) { return "" }
    return $v
}

function Get-BytesSha256Hex {
    param([string]$Text)
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Text)
    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        return [System.BitConverter]::ToString($sha256.ComputeHash($bytes)).Replace('-', '')
    }
    finally {
        $sha256.Dispose()
    }
}

function Resolve-ProjectRoot {
    param([string]$StartDir)

    $candidates = @(
        $StartDir,
        (Split-Path -Parent $StartDir)
    ) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -Unique

    foreach ($dir in $candidates) {
        if ((Test-Path (Join-Path $dir 'config')) -or (Test-Path (Join-Path $dir 'data')) -or (Test-Path (Join-Path $dir 'app'))) {
            return $dir
        }
    }

    return $StartDir
}

function Resolve-ConfigFile {
    param(
        [string]$RootDir,
        [string[]]$RelativeCandidates
    )

    foreach ($candidate in $RelativeCandidates) {
        $full = Join-Path $RootDir $candidate
        if (Test-Path $full) { return $full }
    }
    return $null
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Write-InventoryLog {
    param(
        [string]$LogPath,
        [string]$Level,
        [string]$Message
    )
    $line = "{0}`t{1}`t{2}" -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Level.ToUpperInvariant(), $Message
    Add-Content -Path $LogPath -Value $line -Encoding UTF8
}

function Import-CsvSafe {
    param([string]$Path)
    if (-not $Path) { return @() }
    if (-not (Test-Path $Path)) { return @() }
    try { return @(Import-Csv $Path) } catch { return @() }
}

function Get-CleanUser {
    param([string]$RawUser)

    $value = Normalize-Whitespace $RawUser
    if ([string]::IsNullOrWhiteSpace($value)) { return "" }

    if ($value -match '\\') {
        $value = $value.Split('\\')[-1]
    }

    if ($value -match '@') {
        $value = $value.Split('@')[0]
    }

    return $value.Trim()
}

function Get-DepartmentFromAssetCode {
    param([string]$AssetCode)

    switch -Regex ($AssetCode) {
        '^EDT' { return 'Teknik' }
        '^BRT' { return 'Berita' }
        '^PGM' { return 'Program' }
        '^ADM' { return 'Administrasi' }
        '^FIN' { return 'Keuangan' }
        '^HRD' { return 'HR' }
        '^IT'  { return 'IT' }
        default { return 'Unknown' }
    }
}

function Convert-SubnetMaskToPrefixLength {
    param([string]$SubnetMask)

    if ([string]::IsNullOrWhiteSpace($SubnetMask)) { return "" }

    $bits = 0
    foreach ($part in $SubnetMask.Split('.')) {
        $number = 0
        if (-not [int]::TryParse($part, [ref]$number)) { return "" }
        $binary = [Convert]::ToString($number, 2).PadLeft(8, '0')
        $bits += @($binary.ToCharArray() | Where-Object { $_ -eq '1' }).Count
    }

    return [string]$bits
}

function Get-NetworkFromIpconfig {
    try {
        $lines = @(ipconfig /all 2>$null)
        $blocks = @()
        $current = $null

        foreach ($line in $lines) {
            if ($line -match '^[^\s].* adapter (.+):\s*$') {
                if ($current) { $blocks += $current }
                $current = [PSCustomObject]@{
                    InterfaceAlias = (Normalize-Whitespace $matches[1])
                    Description = ''
                    MacAddress = ''
                    IPAddress = ''
                    SubnetMask = ''
                    PrefixLength = ''
                    DefaultGateway = ''
                    DnsSuffix = ''
                    Disconnected = $false
                }
                continue
            }

            if (-not $current) { continue }

            if ($line -match '^\s*Media State[.\s]*:\s*Media disconnected') {
                $current.Disconnected = $true
                continue
            }

            if ($line -match '^\s*Connection-specific DNS Suffix[.\s]*:\s*(.*)$') {
                $current.DnsSuffix = Normalize-Whitespace $matches[1]
                continue
            }

            if ($line -match '^\s*Description[.\s]*:\s*(.*)$') {
                $current.Description = Normalize-Whitespace $matches[1]
                continue
            }

            if ($line -match '^\s*Physical Address[.\s]*:\s*(.*)$') {
                $current.MacAddress = Normalize-Whitespace $matches[1]
                continue
            }

            if ($line -match '^\s*IPv4 Address[.\s]*:\s*([0-9.]+)') {
                $current.IPAddress = Normalize-Whitespace $matches[1]
                continue
            }

            if ($line -match '^\s*Subnet Mask[.\s]*:\s*([0-9.]+)') {
                $current.SubnetMask = Normalize-Whitespace $matches[1]
                $current.PrefixLength = Convert-SubnetMaskToPrefixLength -SubnetMask $current.SubnetMask
                continue
            }

            if ($line -match '^\s*Default Gateway[.\s]*:\s*([0-9.]+)') {
                $current.DefaultGateway = Normalize-Whitespace $matches[1]
                continue
            }
        }

        if ($current) { $blocks += $current }

        $chosen = $blocks | Where-Object {
            -not $_.Disconnected `
                -and $_.IPAddress `
                -and $_.IPAddress -notlike '169.254.*' `
                -and $_.IPAddress -ne '127.0.0.1'
        } | Sort-Object @{ Expression = { if ($_.DefaultGateway) { 0 } else { 1 } } } | Select-Object -First 1

        if (-not $chosen) { return $null }

        return [PSCustomObject]@{
            IPAddress      = [string]$chosen.IPAddress
            PrefixLength   = [string]$chosen.PrefixLength
            DefaultGateway = [string]$chosen.DefaultGateway
            DnsSuffix      = [string]$chosen.DnsSuffix
            InterfaceAlias = [string]$chosen.InterfaceAlias
            InterfaceIndex = ''
            MacAddress     = [string]$chosen.MacAddress
        }
    }
    catch {
        return $null
    }
}

function Get-FirstActiveNetwork {
    try {
        $configs = Get-NetIPConfiguration | Where-Object {
            $_.IPv4Address -and $_.NetAdapter.Status -eq 'Up'
        }

        $chosen = $configs | Select-Object -First 1
        if (-not $chosen) { return Get-NetworkFromIpconfig }

        $ip = Get-TrimmedString ($chosen.IPv4Address.IPAddress | Select-Object -First 1)
        $prefix = Get-TrimmedString ($chosen.IPv4Address.PrefixLength | Select-Object -First 1)
        $gw = Get-TrimmedString ($chosen.IPv4DefaultGateway.NextHop | Select-Object -First 1)
        $dns = Get-TrimmedString $chosen.DnsSuffix
        $alias = Get-TrimmedString $chosen.InterfaceAlias
        $ifIndex = Get-TrimmedString $chosen.InterfaceIndex
        $mac = ""
        try {
            $adapter = Get-NetAdapter -InterfaceIndex $chosen.InterfaceIndex
            $mac = Get-TrimmedString $adapter.MacAddress
        } catch {}

        if ($ip) {
            return [PSCustomObject]@{
            IPAddress      = $ip
            PrefixLength   = $prefix
            DefaultGateway = $gw
            DnsSuffix      = $dns
            InterfaceAlias = $alias
            InterfaceIndex = $ifIndex
            MacAddress     = $mac
            }
        }
    }
    catch {
    }

    return Get-NetworkFromIpconfig
}

function Get-IPv4PrefixText {
    param([string]$IPAddress)
    if ([string]::IsNullOrWhiteSpace($IPAddress)) { return "" }
    $parts = $IPAddress.Split('.')
    if ($parts.Count -ge 3) {
        return "{0}.{1}.{2}." -f $parts[0], $parts[1], $parts[2]
    }
    return $IPAddress
}

function Get-WifiSsid {
    try {
        $text = netsh wlan show interfaces 2>$null | Out-String
        $match = [regex]::Match($text, '^\s*SSID\s*:\s*(.+)$', [System.Text.RegularExpressions.RegexOptions]::Multiline)
        if ($match.Success) {
            return (Normalize-Whitespace $match.Groups[1].Value)
        }
        return ""
    }
    catch {
        return ""
    }
}

function Get-InstalledPrinters {
    $exclude = @(
        'MICROSOFT PRINT TO PDF',
        'MICROSOFT XPS DOCUMENT WRITER',
        'FAX',
        'SEND TO ONENOTE'
    )

    try {
        $items = Get-CimInstance Win32_Printer | Where-Object {
            $_.Name -and ($exclude -notcontains (Safe-Upper $_.Name))
        } | ForEach-Object {
            $port = Get-TrimmedString $_.PortName
            $type = if ($_.Network) { 'Network' } elseif ($_.Local) { 'Local' } else { 'Other' }
            "{0} [{1}{2}]" -f (Normalize-Whitespace $_.Name), $type, $(if($port){" | $port"}else{""})
        }
        return @($items | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -Unique)
    }
    catch {
        return @()
    }
}

function Get-PresentPeripheralSummary {
    try {
        $items = Get-PnpDevice -PresentOnly | Where-Object {
            $_.FriendlyName -and $_.Class -in @('Printer','Image','Camera','Bluetooth','Monitor','MEDIA')
        } | ForEach-Object {
            "{0}: {1}" -f $_.Class, (Normalize-Whitespace $_.FriendlyName)
        }
        return @($items | Select-Object -Unique | Select-Object -First 20)
    }
    catch {
        return @()
    }
}

function Get-MemoryTypeLabel {
    param([int]$SmbiosMemoryType, [int]$MemoryType)

    switch ($SmbiosMemoryType) {
        20 { return 'DDR' }
        21 { return 'DDR2' }
        22 { return 'DDR2 FB-DIMM' }
        24 { return 'DDR3' }
        26 { return 'DDR4' }
        27 { return 'LPDDR' }
        28 { return 'LPDDR2' }
        29 { return 'LPDDR3' }
        30 { return 'LPDDR4' }
        34 { return 'DDR5' }
    }

    switch ($MemoryType) {
        20 { return 'DDR' }
        21 { return 'DDR2' }
        24 { return 'DDR3' }
        26 { return 'DDR4' }
        34 { return 'DDR5' }
        default { return 'Unknown' }
    }
}

function Get-LocationOverride {
    param(
        [string]$AssetCode,
        [string]$ComputerName,
        [string]$MappingFile
    )

    $rows = @(Import-CsvSafe $MappingFile)
    if (-not $rows.Count) { return $null }

    foreach ($row in $rows) {
        $asset = Get-TrimmedString $row.Asset_Code
        $name  = Get-TrimmedString $row.ComputerName

        if ($asset -and $asset -eq $AssetCode) { return $row }
        if ($name -and $name -eq $ComputerName) { return $row }
    }

    return $null
}

function Get-NetworkLocationEstimate {
    param(
        [string]$MappingFile,
        [string]$IPAddress,
        [string]$DefaultGateway,
        [string]$DnsSuffix,
        [string]$SSID,
        [string]$ComputerName
    )

    $rows = @(Import-CsvSafe $MappingFile)
    if (-not $rows.Count) {
        return [PSCustomObject]@{
            Site = ''
            Location = ''
            Room = ''
            Department = ''
            Method = ''
            Confidence = ''
        }
    }

    $prefix = Get-IPv4PrefixText $IPAddress
    $ordered = $rows | Sort-Object { [int]($_.Priority | ForEach-Object { if($_){$_} else {'100'} }) }

    foreach ($row in $ordered) {
        $type = Safe-Upper $row.MatchType
        $pattern = Normalize-Whitespace $row.Pattern
        if ([string]::IsNullOrWhiteSpace($type) -or [string]::IsNullOrWhiteSpace($pattern)) { continue }

        $matched = $false
        switch ($type) {
            'IPV4STARTSWITH' { if ($IPAddress -like "$pattern*") { $matched = $true } }
            'PREFIX'         { if ($prefix -like "$pattern*") { $matched = $true } }
            'GATEWAYEQUALS'  { if ($DefaultGateway -eq $pattern) { $matched = $true } }
            'DNSSUFFIXCONTAINS' { if ((Safe-Upper $DnsSuffix) -like ("*" + (Safe-Upper $pattern) + "*")) { $matched = $true } }
            'SSIDCONTAINS'   { if ((Safe-Upper $SSID) -like ("*" + (Safe-Upper $pattern) + "*")) { $matched = $true } }
            'COMPUTERNAMEREGEX' { if ($ComputerName -match $pattern) { $matched = $true } }
        }

        if ($matched) {
            return [PSCustomObject]@{
                Site       = Normalize-Whitespace $row.Site
                Location   = Normalize-Whitespace $row.Location
                Room       = Normalize-Whitespace $row.Room
                Department = Normalize-Whitespace $row.Department
                Method     = $type
                Confidence = $(if ($row.Confidence) { Normalize-Whitespace $row.Confidence } else { 'Medium' })
            }
        }
    }

    return [PSCustomObject]@{
        Site = ''
        Location = ''
        Room = ''
        Department = ''
        Method = ''
        Confidence = ''
    }
}

function ConvertTo-Base64Utf8 {
    param([string]$Text)
    return [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($Text))
}

function Test-RemoteConfigured {
    param($Config)
    return (
        (Normalize-Whitespace $Config.sharedRoot) -or
        (Normalize-Whitespace $Config.googleDriveLocalPath) -or
        (Normalize-Whitespace $Config.googleDriveWebhookUrl)
    )
}

function Try-CopyToDirectory {
    param(
        [string]$SourceFile,
        [string]$TargetDirectory,
        [string]$LogPath,
        [string]$Label
    )

    if ([string]::IsNullOrWhiteSpace($TargetDirectory)) { return $false }

    try {
        Ensure-Directory $TargetDirectory
        $destFile = Join-Path $TargetDirectory ([System.IO.Path]::GetFileName($SourceFile))
        Copy-Item -Path $SourceFile -Destination $destFile -Force
        Write-InventoryLog -LogPath $LogPath -Level 'INFO' -Message "$Label upload success -> $destFile"
        return $true
    }
    catch {
        Write-InventoryLog -LogPath $LogPath -Level 'WARN' -Message "$Label upload failed -> $TargetDirectory :: $($_.Exception.Message)"
        return $false
    }
}

function Try-WebhookUpload {
    param(
        [string]$SourceFile,
        $Config,
        [string]$LogPath,
        [string]$ComputerName,
        [string]$Location,
        [string]$Room,
        [string]$SiteEstimated
    )

    $webhookUrl = Normalize-Whitespace $Config.googleDriveWebhookUrl
    if ([string]::IsNullOrWhiteSpace($webhookUrl)) { return $false }

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    } catch {}

    try {
        $raw = Get-Content -Path $SourceFile -Raw -Encoding UTF8
        $payload = [ordered]@{
            token        = (Normalize-Whitespace $Config.uploadToken)
            folderId     = (Normalize-Whitespace $Config.googleDriveFolderId)
            fileName     = [System.IO.Path]::GetFileName($SourceFile)
            contentBase64= (ConvertTo-Base64Utf8 $raw)
            computerName = $ComputerName
            location     = $Location
            room         = $Room
            siteEstimated= $SiteEstimated
            uploadedAt   = (Get-Date).ToString('s')
        } | ConvertTo-Json -Depth 6

        $response = Invoke-RestMethod -Uri $webhookUrl -Method Post -ContentType 'application/json; charset=utf-8' -Body $payload -TimeoutSec 60
        Write-InventoryLog -LogPath $LogPath -Level 'INFO' -Message ("Webhook upload success -> " + ($response | ConvertTo-Json -Compress))
        return $true
    }
    catch {
        Write-InventoryLog -LogPath $LogPath -Level 'WARN' -Message "Webhook upload failed :: $($_.Exception.Message)"
        return $false
    }
}

function Sync-PendingFiles {
    param(
        [string]$PendingDir,
        $Config,
        [string]$LogPath
    )

    if (-not (Test-Path $PendingDir)) { return }

    $files = Get-ChildItem -Path $PendingDir -File -Filter *.csv | Sort-Object LastWriteTime
    foreach ($file in $files) {
        $uploaded = $false

        $sharedRoot = Normalize-Whitespace $Config.sharedRoot
        if ($sharedRoot) {
            $uploaded = (Try-CopyToDirectory -SourceFile $file.FullName -TargetDirectory (Join-Path $sharedRoot 'data\inventory-results') -LogPath $LogPath -Label 'SharedRoot') -or $uploaded
        }

        $drivePath = Normalize-Whitespace $Config.googleDriveLocalPath
        if ($drivePath) {
            $uploaded = (Try-CopyToDirectory -SourceFile $file.FullName -TargetDirectory (Join-Path $drivePath 'inventory-results') -LogPath $LogPath -Label 'GoogleDriveDesktop') -or $uploaded
        }

        $webhookOk = Try-WebhookUpload -SourceFile $file.FullName -Config $Config -LogPath $LogPath -ComputerName '' -Location '' -Room '' -SiteEstimated ''
        $uploaded = $webhookOk -or $uploaded

        if ($uploaded) {
            Remove-Item -Path $file.FullName -Force
            Write-InventoryLog -LogPath $LogPath -Level 'INFO' -Message "Pending file cleared -> $($file.Name)"
        }
    }
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Resolve-ProjectRoot -StartDir $scriptDir

$configDir = Join-Path $rootDir 'config'
$dataDir = Join-Path $rootDir 'data'
$outDir = Join-Path $dataDir 'inventory-results'
$pendingDir = Join-Path $dataDir 'pending-upload'
$logsDir = Join-Path $dataDir 'logs'

Ensure-Directory $configDir
Ensure-Directory $dataDir
Ensure-Directory $outDir
Ensure-Directory $pendingDir
Ensure-Directory $logsDir

$logPath = Join-Path $logsDir ("inventory-" + (Get-Date).ToString('yyyyMMdd') + '.log')

$mappingPath = Resolve-ConfigFile -RootDir $rootDir -RelativeCandidates @(
    'config\device-location-map.csv',
    'device-location-map.csv',
    'config\device-location-map.sample.csv'
)

$networkMappingPath = Resolve-ConfigFile -RootDir $rootDir -RelativeCandidates @(
    'config\network-location-map.csv',
    'network-location-map.csv',
    'config\network-location-map.sample.csv'
)

$settingsPath = Resolve-ConfigFile -RootDir $rootDir -RelativeCandidates @(
    'config\inventory-destinations.json',
    'inventory-destinations.json',
    'config\inventory-network-settings.json'
)

$config = [PSCustomObject]@{
    sharedRoot            = ''
    defaultLocation       = ''
    defaultRoom           = ''
    googleDriveLocalPath  = ''
    googleDriveWebhookUrl = ''
    googleDriveFolderId   = ''
    uploadToken           = ''
}

if ($settingsPath -and (Test-Path $settingsPath)) {
    try {
        $cfgRaw = Get-Content -Path $settingsPath -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($prop in $cfgRaw.PSObject.Properties.Name) {
            $config | Add-Member -NotePropertyName $prop -NotePropertyValue $cfgRaw.$prop -Force
        }
    }
    catch {
        Write-InventoryLog -LogPath $logPath -Level 'WARN' -Message "Could not parse settings file: $settingsPath"
    }
}

$pcName = Normalize-Whitespace $env:COMPUTERNAME
$department = Get-DepartmentFromAssetCode -AssetCode $pcName

$cs   = Get-CimInstance Win32_ComputerSystem
$cpuO = Get-CimInstance Win32_Processor | Select-Object -First 1
$osO  = Get-CimInstance Win32_OperatingSystem
$bios = Get-CimInstance Win32_BIOS
$bb   = Get-CimInstance Win32_BaseBoard
$csp  = Get-CimInstance Win32_ComputerSystemProduct

$network = Get-FirstActiveNetwork
$ip = ""
$gateway = ""
$dnsSuffix = ""
$interfaceAlias = ""
$ssid = ""
$mac = ""
$prefixLength = ""
$ipPrefix = ""
if ($network) {
    $ip = Get-TrimmedString $network.IPAddress
    $gateway = Get-TrimmedString $network.DefaultGateway
    $dnsSuffix = Get-TrimmedString $network.DnsSuffix
    $interfaceAlias = Get-TrimmedString $network.InterfaceAlias
    $mac = Get-TrimmedString $network.MacAddress
    $prefixLength = Get-TrimmedString $network.PrefixLength
    $ipPrefix = Get-IPv4PrefixText -IPAddress $ip
}
$ssid = Get-WifiSsid

$cpu = Normalize-Whitespace (Get-TrimmedString $cpuO.Name)
$ramGB = [math]::Round(($cs.TotalPhysicalMemory / 1GB), 0)

$memoryModules = @(Get-CimInstance Win32_PhysicalMemory | ForEach-Object {
    $cap = [math]::Round(($_.Capacity / 1GB), 0)
    $type = Get-MemoryTypeLabel -SmbiosMemoryType ([int]($_.SMBIOSMemoryType)) -MemoryType ([int]($_.MemoryType))
    $speed = Get-TrimmedString $_.Speed
    $mfg = Normalize-Whitespace (Get-TrimmedString $_.Manufacturer)
    $part = Normalize-Whitespace (Get-TrimmedString $_.PartNumber)
    $serial = Normalize-Whitespace (Get-TrimmedString $_.SerialNumber)
    $bank = Normalize-Whitespace (Get-TrimmedString $_.BankLabel)
    $slot = Normalize-Whitespace (Get-TrimmedString $_.DeviceLocator)
    [PSCustomObject]@{
        CapacityGB = $cap
        Type = $type
        Speed = $speed
        Manufacturer = $mfg
        PartNumber = $part
        SerialNumber = $serial
        BankLabel = $bank
        DeviceLocator = $slot
        Detail = ("{0} {1}GB {2}{3}{4}{5}{6}" -f $mfg, $cap, $type, $(if($speed){" $speed" + 'MHz'}else{''}), $(if($part){" PN:" + $part}else{''}), $(if($serial){" SN:" + $serial}else{''}), $(if($slot){" Slot:" + $slot}else{''})).Trim()
    }
})
$ramSlotsUsed = @($memoryModules).Count
$ramDetail = (@($memoryModules | ForEach-Object { $_.Detail } | Where-Object { $_ }) -join '; ')
$ramManufacturers = (@($memoryModules | ForEach-Object { $_.Manufacturer } | Where-Object { $_ } | Select-Object -Unique) -join '; ')
$ramPartNumbers = (@($memoryModules | ForEach-Object { $_.PartNumber } | Where-Object { $_ } | Select-Object -Unique) -join '; ')
$ramSerialNumbers = (@($memoryModules | ForEach-Object { $_.SerialNumber } | Where-Object { $_ } | Select-Object -Unique) -join '; ')
$ramSpeedsMHz = (@($memoryModules | ForEach-Object { $_.Speed } | Where-Object { $_ } | Select-Object -Unique) -join '; ')
$ramTypes = (@($memoryModules | ForEach-Object { $_.Type } | Where-Object { $_ } | Select-Object -Unique) -join '; ')
$ramSlots = (@($memoryModules | ForEach-Object { $_.DeviceLocator } | Where-Object { $_ } | Select-Object -Unique) -join '; ')

$smartCtlPath = Get-SmartCtlPath -ScriptRoot $PSScriptRoot
$diskRows = @(Get-CimInstance Win32_DiskDrive | ForEach-Object {
    $diskIndex = Get-TrimmedString $_.Index
    $model = Normalize-Whitespace (Get-TrimmedString $_.Model)
    $sizeGb = [math]::Round(($_.Size / 1GB), 0)
    $interfaceType = Normalize-Whitespace (Get-TrimmedString $_.InterfaceType)
    $serialDisk = Normalize-Whitespace (Get-TrimmedString $_.SerialNumber)
    $media = Normalize-Whitespace (Get-TrimmedString $_.MediaType)
    $telemetry = Get-DiskWriteTelemetry -SmartCtlPath $smartCtlPath -DiskIndex $diskIndex -Model $model
    [PSCustomObject]@{
        Summary = ("{0}-{1}GB" -f $(if($model){$model}else{'Disk'}), $sizeGb)
        Detail = ("{0} | {1}GB{2}{3}{4}" -f $(if($model){$model}else{'Disk'}), $sizeGb, $(if($interfaceType){" | " + $interfaceType}else{''}), $(if($serialDisk){" | SN: " + $serialDisk}else{''}), $(if($telemetry.HealthDetail){" | " + $telemetry.HealthDetail}else{''})).Trim()
        Index = $diskIndex
        Model = $model
        SizeGB = $sizeGb
        InterfaceType = $interfaceType
        Serial = $serialDisk
        MediaType = $media
        BytesWritten = $telemetry.BytesWritten
        TbwGB = $telemetry.TbwGB
        PercentageUsed = $telemetry.PercentageUsed
        PowerOnHours = $telemetry.PowerOnHours
        SmartAvailable = $telemetry.SmartAvailable
        SmartHealthStatus = $telemetry.SmartHealthStatus
        TemperatureC = $telemetry.TemperatureC
        AvailableSpare = $telemetry.AvailableSpare
        MediaErrors = $telemetry.MediaErrors
        ReallocatedSectorCount = $telemetry.ReallocatedSectorCount
        CurrentPendingSector = $telemetry.CurrentPendingSector
        OfflineUncorrectable = $telemetry.OfflineUncorrectable
        HealthSource = $telemetry.HealthSource
        HealthDetail = $telemetry.HealthDetail
    }
})
$disk = (@($diskRows | ForEach-Object { $_.Summary } | Where-Object { $_ }) -join '; ')
$diskDetail = (@($diskRows | ForEach-Object { $_.Detail } | Where-Object { $_ }) -join '; ')
$ssdTbwBytes = Format-DiskMetricList -DiskRows $diskRows -ValueProperty 'BytesWritten'
$ssdTbwGb = Format-DiskMetricList -DiskRows $diskRows -ValueProperty 'TbwGB'
$ssdPercentageUsed = Format-DiskMetricList -DiskRows $diskRows -ValueProperty 'PercentageUsed'
$ssdPowerOnHours = Format-DiskMetricList -DiskRows $diskRows -ValueProperty 'PowerOnHours'
$ssdHealthSource = Format-DiskMetricList -DiskRows $diskRows -ValueProperty 'HealthSource'
$ssdHealthDetail = (@($diskRows | ForEach-Object { $_.HealthDetail } | Where-Object { $_ }) -join '; ')
$storageHealthRows = @($diskRows | ForEach-Object {
    $diskKey = ''
    if (-not (Test-GenericIdentityValue $_.Serial)) {
        $diskKey = 'serial:' + $_.Serial
    } else {
        $diskKey = 'fallback:' + (Get-BytesSha256Hex -Text ("{0}|{1}|{2}" -f $_.Model, $_.SizeGB, $_.Index))
    }

    [PSCustomObject]@{
        disk_key = $diskKey
        physical_index = $_.Index
        disk_model = $_.Model
        disk_serial = $_.Serial
        disk_type = $(if ($_.MediaType) { $_.MediaType } elseif ($_.InterfaceType -match 'NVMe') { 'ssd' } else { '' })
        interface_type = $_.InterfaceType
        capacity_gb = $_.SizeGB
        smart_available = $_.SmartAvailable
        smart_health_status = $_.SmartHealthStatus
        tbw_bytes = $_.BytesWritten
        tbw_gb = $_.TbwGB
        percentage_used = $_.PercentageUsed
        power_on_hours = $_.PowerOnHours
        temperature_c = $_.TemperatureC
        available_spare = $_.AvailableSpare
        media_errors = $_.MediaErrors
        reallocated_sector_count = $_.ReallocatedSectorCount
        current_pending_sector = $_.CurrentPendingSector
        offline_uncorrectable = $_.OfflineUncorrectable
        source_method = $_.HealthSource
        storage_health_detail = $_.HealthDetail
    }
})
$storageHealthJson = ConvertTo-CompactJson $storageHealthRows

$gpuRows = @(Get-CimInstance Win32_VideoController | ForEach-Object {
    $name = Normalize-Whitespace (Get-TrimmedString $_.Name)
    if (-not $name) { return }
    $chipVendor = Normalize-Whitespace (Get-TrimmedString $_.AdapterCompatibility)
    if (-not $chipVendor) {
        $upperName = $name.ToUpperInvariant()
        if ($upperName -match 'NVIDIA') {
            $chipVendor = 'NVIDIA'
        } elseif ($upperName -match 'INTEL') {
            $chipVendor = 'Intel'
        } elseif ($upperName -match 'AMD|RADEON') {
            $chipVendor = 'AMD'
        } elseif ($upperName -match 'MICROSOFT') {
            $chipVendor = 'Microsoft'
        }
    }
    $boardVendorId = Get-PciSubsystemVendorId -PnpDeviceId (Get-TrimmedString $_.PNPDeviceID)
    $boardManufacturer = Get-GpuBoardManufacturer -SubsystemVendorId $boardVendorId
    $ram = ""
    try {
        if ($_.AdapterRAM) {
            $ram = ([math]::Round(($_.AdapterRAM / 1GB), 1)).ToString()
        }
    } catch {}
    $driver = Normalize-Whitespace (Get-TrimmedString $_.DriverVersion)
    [PSCustomObject]@{
        Name = $name
        Detail = ("{0}{1}{2}{3}{4}{5}" -f $name, $(if($boardManufacturer){" | Board Manufacturer " + $boardManufacturer}else{''}), $(if((-not $boardManufacturer) -and $boardVendorId){" | Board Vendor ID " + $boardVendorId}else{''}), $(if($chipVendor){" | Chip Vendor " + $chipVendor}else{''}), $(if($ram){" | VRAM " + $ram + " GB"}else{''}), $(if($driver){" | Driver " + $driver}else{''}))
    }
})
$gpu = (@($gpuRows | ForEach-Object { $_.Name } | Where-Object { $_ } | Select-Object -Unique) -join '; ')
$gpuDetail = (@($gpuRows | ForEach-Object { $_.Detail } | Where-Object { $_ } | Select-Object -Unique) -join '; ')

$rawOs = Normalize-Whitespace (Get-TrimmedString $osO.Caption)
$osVersion = Normalize-Whitespace (Get-TrimmedString $osO.Version)
$osBuild = Get-TrimmedString $osO.BuildNumber
$osInstallDate = ""
try {
    $osInstallDate = ([Management.ManagementDateTimeConverter]::ToDateTime($osO.InstallDate)).ToString('yyyy-MM-dd')
} catch {}

$serialRaw = Normalize-Whitespace (Get-TrimmedString $bios.SerialNumber)
$serialNormalized = Normalize-IdentityValue $serialRaw
$userRaw = Get-TrimmedString $cs.UserName
$userClean = Get-CleanUser -RawUser $userRaw

$manufacturer = Normalize-Whitespace (Get-TrimmedString $cs.Manufacturer)
$model = Normalize-Whitespace (Get-TrimmedString $cs.Model)
$systemType = Normalize-Whitespace (Get-TrimmedString $cs.SystemType)
$motherboardManufacturer = Normalize-Whitespace (Get-TrimmedString $bb.Manufacturer)
$motherboardProduct = Normalize-Whitespace (Get-TrimmedString $bb.Product)
$motherboardVersion = Normalize-Whitespace (Get-TrimmedString $bb.Version)
$motherboardSerial = Normalize-IdentityValue (Normalize-Whitespace (Get-TrimmedString $bb.SerialNumber))
$motherboard = Normalize-Whitespace (("{0} {1} {2}" -f $motherboardManufacturer, $motherboardProduct, $motherboardVersion).Trim())
$biosVersion = Normalize-Whitespace ((@($bios.BIOSVersion) -join '; '))
$uuid = Normalize-IdentityValue (Normalize-Whitespace (Get-TrimmedString $csp.UUID))

$printers = Get-InstalledPrinters
$peripherals = Get-PresentPeripheralSummary

$override = Get-LocationOverride -AssetCode $pcName -ComputerName $pcName -MappingFile $mappingPath
$netEstimate = Get-NetworkLocationEstimate -MappingFile $networkMappingPath -IPAddress $ip -DefaultGateway $gateway -DnsSuffix $dnsSuffix -SSID $ssid -ComputerName $pcName

if ($override) {
    if ([string]::IsNullOrWhiteSpace($department) -or $department -eq 'Unknown') {
        $department = Normalize-Whitespace (Get-TrimmedString $override.Department)
    }

    if ([string]::IsNullOrWhiteSpace($Location)) {
        $Location = Normalize-Whitespace (Get-TrimmedString $override.Location)
    }

    if ([string]::IsNullOrWhiteSpace($Room)) {
        $Room = Normalize-Whitespace (Get-TrimmedString $override.Room)
    }

    $mappedUser = Get-CleanUser (Get-TrimmedString $override.User_Alias)
    if (-not [string]::IsNullOrWhiteSpace($mappedUser)) {
        $userClean = $mappedUser
    }
}

if ([string]::IsNullOrWhiteSpace($Location) -and $netEstimate.Location) {
    $Location = $netEstimate.Location
}

if ([string]::IsNullOrWhiteSpace($Room) -and $netEstimate.Room) {
    $Room = $netEstimate.Room
}

if ([string]::IsNullOrWhiteSpace($department) -or $department -eq 'Unknown') {
    if ($override -and $override.Department) {
        $department = Normalize-Whitespace (Get-TrimmedString $override.Department)
    } elseif ($netEstimate.Department) {
        $department = $netEstimate.Department
    }
}

if ([string]::IsNullOrWhiteSpace($Location)) {
    $Location = Normalize-Whitespace $config.defaultLocation
}
if ([string]::IsNullOrWhiteSpace($Room)) {
    $Room = Normalize-Whitespace $config.defaultRoom
}
if ([string]::IsNullOrWhiteSpace($Location)) {
    $Location = 'Unknown'
}
if ([string]::IsNullOrWhiteSpace($Room)) {
    $Room = 'Unassigned'
}
if ([string]::IsNullOrWhiteSpace($department)) {
    $department = 'Unknown'
}

$siteEstimated = Normalize-Whitespace $netEstimate.Site
if ([string]::IsNullOrWhiteSpace($siteEstimated)) {
    $siteEstimated = $Location
}
$siteMethod = Normalize-Whitespace $netEstimate.Method
$siteConfidence = Normalize-Whitespace $netEstimate.Confidence

$identityParts = @()
if ($serialNormalized) { $identityParts += "SERIAL:$serialNormalized" }
if ($motherboardSerial) { $identityParts += "MBSN:$motherboardSerial" }
if ($uuid) { $identityParts += "UUID:$uuid" }
if ($mac) { $identityParts += "MAC:$mac" }
if ($manufacturer) { $identityParts += "MFG:$manufacturer" }
if ($model) { $identityParts += "MODEL:$model" }
if ($motherboard) { $identityParts += "MB:$motherboard" }
if ($cpu) { $identityParts += "CPU:$cpu" }
if ($ramDetail) { $identityParts += "RAM:$ramDetail" }
if ($diskDetail) { $identityParts += "DISK:$diskDetail" }
if ($gpuDetail) { $identityParts += "GPU:$gpuDetail" }

$identitySource = ($identityParts -join '|')
$canonicalDeviceId = Get-BytesSha256Hex -Text $identitySource

$legacyHashSource = @(
    $manufacturer,
    $model,
    $cpu,
    $ramGB,
    $disk,
    $gpu,
    $mac,
    $serialNormalized
) -join '|'
$hardwareHash = Get-BytesSha256Hex -Text $legacyHashSource

$scanTime = Get-Date
$scanTimeIso = $scanTime.ToString('s')

$result = [PSCustomObject]@{
    Asset_Code            = $pcName
    Department            = $department
    Location              = $Location
    Room                  = $Room
    Site_Estimated        = $siteEstimated
    Site_Method           = $siteMethod
    Site_Confidence       = $siteConfidence
    User                  = $userClean
    User_Raw              = $userRaw
    Manufacturer          = $manufacturer
    Model                 = $model
    System_Type           = $systemType
    Motherboard           = $motherboard
    Motherboard_Manufacturer = $motherboardManufacturer
    Motherboard_Product   = $motherboardProduct
    Motherboard_Version   = $motherboardVersion
    Motherboard_Serial    = $motherboardSerial
    CPU                   = $cpu
    GPU                   = $gpu
    GPU_Detail            = $gpuDetail
    RAM_GB                = $ramGB
    RAM_Slots_Used        = $ramSlotsUsed
    RAM_Manufacturers     = $ramManufacturers
    RAM_Part_Numbers      = $ramPartNumbers
    RAM_Serial_Numbers    = $ramSerialNumbers
    RAM_Speeds_MHz        = $ramSpeedsMHz
    RAM_Types             = $ramTypes
    RAM_Slots             = $ramSlots
    RAM_Detail            = $ramDetail
    Disk                  = $disk
    Disk_Detail           = $diskDetail
    SSD_TBW_Bytes         = $ssdTbwBytes
    SSD_TBW_GB            = $ssdTbwGb
    SSD_Percentage_Used   = $ssdPercentageUsed
    SSD_Power_On_Hours    = $ssdPowerOnHours
    SSD_Health_Source     = $ssdHealthSource
    SSD_Health_Detail     = $ssdHealthDetail
    Storage_Health_JSON   = $storageHealthJson
    OS                    = $rawOs
    OS_Version            = $osVersion
    OS_Build              = $osBuild
    OS_Install_Date       = $osInstallDate
    BIOS_Serial_Raw       = $serialRaw
    Serial_No             = $serialNormalized
    BIOS_Version          = $biosVersion
    System_UUID           = $uuid
    MAC_Address           = $mac
    IP_Address            = $ip
    IP_Prefix             = $ipPrefix
    Prefix_Length         = $prefixLength
    Default_Gateway       = $gateway
    DNS_Suffix            = $dnsSuffix
    Network_Interface     = $interfaceAlias
    WiFi_SSID             = $ssid
    Installed_Printers    = ($printers -join '; ')
    Present_Peripherals   = ($peripherals -join '; ')
    Peripheral_Count      = (@($printers).Count + @($peripherals).Count)
    Canonical_Device_ID   = $canonicalDeviceId
    HardwareHash          = $hardwareHash
    Scan_Time             = $scanTimeIso
    Scan_Time_Display     = $scanTime.ToString('yyyy-MM-dd HH:mm:ss')
}

$fileName = if ($AppendDateToFileName) {
    '{0}-{1}.csv' -f $pcName, $scanTime.ToString('yyyyMMdd-HHmmss')
}
else {
    '{0}.csv' -f $pcName
}

$outputPath = Join-Path $outDir $fileName
$result | Export-Csv -Path $outputPath -NoTypeInformation -Encoding UTF8
Write-InventoryLog -LogPath $logPath -Level 'INFO' -Message "Local inventory saved -> $outputPath"

$remoteSuccess = $false
if (-not $SkipRemoteUpload) {
    $sharedRoot = Normalize-Whitespace $config.sharedRoot
    if ($sharedRoot) {
        $remoteSuccess = (Try-CopyToDirectory -SourceFile $outputPath -TargetDirectory (Join-Path $sharedRoot 'data\inventory-results') -LogPath $logPath -Label 'SharedRoot') -or $remoteSuccess
    }

    $driveLocal = Normalize-Whitespace $config.googleDriveLocalPath
    if ($driveLocal) {
        $remoteSuccess = (Try-CopyToDirectory -SourceFile $outputPath -TargetDirectory (Join-Path $driveLocal 'inventory-results') -LogPath $logPath -Label 'GoogleDriveDesktop') -or $remoteSuccess
    }

    $remoteSuccess = (Try-WebhookUpload -SourceFile $outputPath -Config $config -LogPath $logPath -ComputerName $pcName -Location $Location -Room $Room -SiteEstimated $siteEstimated) -or $remoteSuccess

    if ((Test-RemoteConfigured $config) -and (-not $remoteSuccess)) {
        $pendingCopy = Join-Path $pendingDir $fileName
        Copy-Item -Path $outputPath -Destination $pendingCopy -Force
        Write-InventoryLog -LogPath $logPath -Level 'WARN' -Message "No remote target succeeded. Queued pending upload -> $pendingCopy"
    }

    Sync-PendingFiles -PendingDir $pendingDir -Config $config -LogPath $logPath
}

Write-Host "Inventory saved to: $outputPath"
if ($remoteSuccess) {
    Write-Host "Remote upload: success"
} elseif (Test-RemoteConfigured $config) {
    Write-Host "Remote upload: pending / failed for current run"
} else {
    Write-Host "Remote upload: not configured"
}
