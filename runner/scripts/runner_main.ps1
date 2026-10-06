[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
}
catch {
    # Best effort for Windows PowerShell 5.1.
}

function Read-JsonFile {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    return (Get-Content -Path $Path -Raw -Encoding UTF8 | ConvertFrom-Json)
}

function Write-JsonFile {
    param([string]$Path, [object]$Value)
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $tmp = $Path + '.tmp'
    $Value | ConvertTo-Json -Depth 8 | Set-Content -Path $tmp -Encoding UTF8
    Move-Item -Path $tmp -Destination $Path -Force
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Write-RunnerLog {
    param([string]$Message)

    $timestamp = (Get-Date).ToString('s')
    $line = '{0} {1}' -f $timestamp, $Message
    try {
        Add-Content -Path $script:RunnerLogPath -Value $line -Encoding UTF8 -ErrorAction Stop
    }
    catch {
        # Logging must never break runner execution.
    }
}

function Test-IsSystemAccount {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return $identity.User -and $identity.User.Value -eq 'S-1-5-18'
}

function Get-JsonValue {
    param([object]$Object, [string]$Name, [object]$Default)
    if ($null -eq $Object) { return $Default }
    if ($Object -is [System.Collections.IDictionary]) {
        if (-not $Object.Contains($Name)) { return $Default }
        $value = $Object[$Name]
        if ($null -eq $value -or [string]$value -eq '') { return $Default }
        return $value
    }
    if (-not ($Object.PSObject.Properties.Name -contains $Name)) { return $Default }
    $value = $Object.$Name
    if ($null -eq $value -or [string]$value -eq '') { return $Default }
    return $value
}

function Set-ObjectProperty {
    param([object]$Object, [string]$Name, [object]$Value)
    if ($Object -is [System.Collections.IDictionary]) {
        $Object[$Name] = $Value
        return
    }
    if ($Object.PSObject.Properties.Name -contains $Name) {
        $Object.$Name = $Value
    } else {
        $Object | Add-Member -NotePropertyName $Name -NotePropertyValue $Value -Force
    }
}

function ConvertTo-DateTimeOrNull {
    param([object]$Value)
    $text = ([string]$Value).Trim()
    if (-not $text) { return $null }

    try {
        return [DateTime]::Parse($text, [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::AssumeLocal)
    } catch {
        return $null
    }
}

function Test-VersionGreaterThan {
    param([string]$Candidate, [string]$Current)

    $candidateText = ([string]$Candidate).Trim()
    $currentText = ([string]$Current).Trim()
    if (-not $candidateText) { return $false }
    if (-not $currentText) { return $true }

    try {
        return ([version]$candidateText) -gt ([version]$currentText)
    } catch {
        return $candidateText -ne $currentText
    }
}

function Copy-DirectoryMirror {
    param([string]$Source, [string]$Destination)

    if (Test-Path $Destination) {
        Remove-Item -Path $Destination -Recurse -Force -ErrorAction Stop
    }
    Ensure-Directory $Destination
    Copy-Item -Path (Join-Path $Source '*') -Destination $Destination -Recurse -Force -ErrorAction Stop
}

function Sync-StateToBranchShare {
    param(
        [string]$SharedRoot,
        [string]$RunnerId,
        [string]$StatePath,
        [object]$State
    )

    $heartbeatDir = Join-Path $SharedRoot 'data\runner-heartbeats'
    Ensure-Directory $heartbeatDir
    $previousShareError = [string](Get-JsonValue -Object $State -Name 'last_share_sync_error' -Default '')
    Set-ObjectProperty -Object $State -Name 'last_share_sync_at' -Value (Get-Date).ToString('s')
    Set-ObjectProperty -Object $State -Name 'last_share_sync_error' -Value ''
    if ([string](Get-JsonValue -Object $State -Name 'last_upload_status' -Default '') -eq 'branch_share_unreachable') {
        Set-ObjectProperty -Object $State -Name 'last_upload_status' -Value 'heartbeat_synced'
        if ($previousShareError -and [string](Get-JsonValue -Object $State -Name 'last_error' -Default '') -eq $previousShareError) {
            $State.last_error = ''
        }
    }
    Write-JsonFile -Path (Join-Path $heartbeatDir ($RunnerId + '.json')) -Value $State
    Write-RunnerLog "Heartbeat synced to branch share: $heartbeatDir"
}

function Use-CurrentUserTaskMode {
    param([object]$Config)

    if ($null -eq $Config) { return $false }
    if ($Config.PSObject.Properties.Name -contains 'runAsCurrentUser' -and $Config.runAsCurrentUser) {
        return $true
    }
    if (-not ($Config.PSObject.Properties.Name -contains 'runAsCurrentUser') -and -not (Test-IsSystemAccount)) {
        return $true
    }

    return $false
}

function Write-CommandAck {
    param(
        [string]$SharedRoot,
        [object]$State,
        [object]$PendingCommand,
        [string]$Status,
        [string]$Message
    )
    if (-not $PendingCommand) { return }

    $ackDir = Join-Path $SharedRoot 'control\command-acks'
    Ensure-Directory $ackDir
    $ack = [ordered]@{
        command_id = $PendingCommand.id
        runner_id = $State.runner_id
        site_id = $State.site_id
        status = $Status
        message = $Message
        runner_state = $State
        acknowledged_at = (Get-Date).ToString('s')
    }
    $ackPath = Join-Path $ackDir ('{0}__{1}.json' -f $State.runner_id, $PendingCommand.id)
    Write-JsonFile -Path $ackPath -Value $ack
}

function New-DirectRunnerHeaders {
    param(
        [string]$SiteId,
        [string]$SiteToken
    )

    return @{
        'X-Site-Id' = $SiteId
        'X-Site-Token' = $SiteToken
    }
}

function Invoke-DirectRunnerJson {
    param(
        [string]$ServerBaseUrl,
        [string]$Path,
        [hashtable]$Headers,
        [object]$Payload
    )

    $baseUrl = ([string]$ServerBaseUrl).TrimEnd('/')
    $uri = $baseUrl + $Path
    $body = $Payload | ConvertTo-Json -Depth 8

    return Invoke-RestMethod -Uri $uri -Method Post -Headers $Headers -ContentType 'application/json; charset=utf-8' -Body $body -TimeoutSec 60
}

function Invoke-DirectRunnerMultipart {
    param(
        [string]$ServerBaseUrl,
        [string]$Path,
        [hashtable]$Headers,
        [string]$MetadataJson,
        [string]$FilePath
    )

    $baseUrl = ([string]$ServerBaseUrl).TrimEnd('/')
    $uri = $baseUrl + $Path
    $boundary = '----InternalInventoryRunner' + ([guid]::NewGuid().ToString('N'))
    $lineBreak = "`r`n"
    $encoding = [System.Text.Encoding]::UTF8
    $fileName = [System.IO.Path]::GetFileName($FilePath)

    $prefix =
        "--$boundary$lineBreak" +
        "Content-Disposition: form-data; name=`"metadata`"$lineBreak$lineBreak" +
        $MetadataJson + $lineBreak +
        "--$boundary$lineBreak" +
        "Content-Disposition: form-data; name=`"file`"; filename=`"$fileName`"$lineBreak" +
        "Content-Type: text/csv$lineBreak$lineBreak"
    $suffix = "$lineBreak--$boundary--$lineBreak"

    $prefixBytes = $encoding.GetBytes($prefix)
    $fileBytes = [System.IO.File]::ReadAllBytes($FilePath)
    $suffixBytes = $encoding.GetBytes($suffix)
    $body = New-Object byte[] ($prefixBytes.Length + $fileBytes.Length + $suffixBytes.Length)
    [System.Buffer]::BlockCopy($prefixBytes, 0, $body, 0, $prefixBytes.Length)
    [System.Buffer]::BlockCopy($fileBytes, 0, $body, $prefixBytes.Length, $fileBytes.Length)
    [System.Buffer]::BlockCopy($suffixBytes, 0, $body, $prefixBytes.Length + $fileBytes.Length, $suffixBytes.Length)

    $request = [System.Net.HttpWebRequest]::Create($uri)
    $request.Method = 'POST'
    $request.Timeout = 120000
    $request.ContentType = "multipart/form-data; boundary=$boundary"
    $request.ContentLength = $body.Length
    foreach ($key in $Headers.Keys) {
        $request.Headers[$key] = [string]$Headers[$key]
    }

    $stream = $request.GetRequestStream()
    try {
        $stream.Write($body, 0, $body.Length)
    }
    finally {
        $stream.Close()
    }

    $response = $request.GetResponse()
    try {
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        $text = $reader.ReadToEnd()
        if ($text) {
            return $text | ConvertFrom-Json
        }

        return [PSCustomObject]@{ status = 'ok' }
    }
    finally {
        $response.Close()
    }
}

function Ensure-DirectRunnerOutbox {
    param([string]$RunnerRoot)

    $outboxRoot = Join-Path $RunnerRoot 'outbox'
    foreach ($name in @('pending', 'sent', 'failed')) {
        Ensure-Directory (Join-Path $outboxRoot $name)
    }

    return $outboxRoot
}

function Test-PathUnderRoot {
    param(
        [string]$Path,
        [string]$Root
    )

    try {
        $resolvedPath = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).ProviderPath
        $resolvedRoot = (Resolve-Path -LiteralPath $Root -ErrorAction Stop).ProviderPath.TrimEnd('\')
    }
    catch {
        return $false
    }

    return $resolvedPath.Equals($resolvedRoot, [StringComparison]::OrdinalIgnoreCase) `
        -or $resolvedPath.StartsWith($resolvedRoot + '\', [StringComparison]::OrdinalIgnoreCase)
}

function Invoke-DirectOutboxFolderCleanup {
    param(
        [string]$OutboxRoot,
        [string]$FolderName,
        [int]$RetentionDays,
        [int]$RetainNewestCount,
        [datetime]$Now,
        [string[]]$Extensions = @('.csv', '.json')
    )

    $folder = Join-Path $OutboxRoot $FolderName
    if (-not (Test-PathUnderRoot -Path $folder -Root $OutboxRoot)) {
        Write-RunnerLog "Direct HTTPS outbox cleanup warning: skipped folder outside outbox root folder=$FolderName"
        return [PSCustomObject]@{
            Folder = $FolderName
            Deleted = 0
            Retained = 0
            Skipped = 0
        }
    }

    $cutoff = $Now.AddDays(-1 * $RetentionDays)
    $knownFiles = @()
    $skipped = 0

    foreach ($file in @(Get-ChildItem -Path $folder -File -ErrorAction Stop)) {
        $extension = ([string]$file.Extension).ToLowerInvariant()
        if ($extension -in $Extensions) {
            $knownFiles += $file
        } else {
            $skipped++
        }
    }

    $orderedKnownFiles = @($knownFiles | Sort-Object LastWriteTime -Descending)
    $newestRetained = @{}
    for ($index = 0; $index -lt $orderedKnownFiles.Count -and $index -lt $RetainNewestCount; $index++) {
        $newestRetained[$orderedKnownFiles[$index].FullName] = $true
    }

    $deleted = 0
    $retained = 0
    foreach ($file in $orderedKnownFiles) {
        if ($newestRetained.ContainsKey($file.FullName)) {
            $retained++
            continue
        }

        if ($file.LastWriteTime -ge $cutoff) {
            $retained++
            continue
        }

        if (-not (Test-PathUnderRoot -Path $file.FullName -Root $OutboxRoot)) {
            $skipped++
            continue
        }

        Remove-Item -LiteralPath $file.FullName -Force -ErrorAction Stop
        $deleted++
    }

    return [PSCustomObject]@{
        Folder = $FolderName
        Deleted = $deleted
        Retained = $retained
        Skipped = $skipped
    }
}

function Invoke-DirectOutboxCleanup {
    param([string]$OutboxRoot)

    Ensure-Directory $OutboxRoot
    $pendingDir = Join-Path $OutboxRoot 'pending'
    $sentDir = Join-Path $OutboxRoot 'sent'
    $failedDir = Join-Path $OutboxRoot 'failed'
    Ensure-Directory $pendingDir
    Ensure-Directory $sentDir
    Ensure-Directory $failedDir

    if (-not (Test-PathUnderRoot -Path $sentDir -Root $OutboxRoot)) {
        Write-RunnerLog 'Direct HTTPS outbox cleanup warning: sent folder is outside outbox root; cleanup skipped.'
        return
    }
    if (-not (Test-PathUnderRoot -Path $failedDir -Root $OutboxRoot)) {
        Write-RunnerLog 'Direct HTTPS outbox cleanup warning: failed folder is outside outbox root; cleanup skipped.'
        return
    }

    $now = Get-Date
    $sent = Invoke-DirectOutboxFolderCleanup -OutboxRoot $OutboxRoot -FolderName 'sent' -RetentionDays 14 -RetainNewestCount 100 -Now $now
    $failed = Invoke-DirectOutboxFolderCleanup -OutboxRoot $OutboxRoot -FolderName 'failed' -RetentionDays 30 -RetainNewestCount 100 -Now $now

    Write-RunnerLog ("Direct HTTPS outbox cleanup summary sent_deleted={0} sent_retained={1} sent_skipped={2} failed_deleted={3} failed_retained={4} failed_skipped={5} pending_deleted=0" -f `
        $sent.Deleted, $sent.Retained, $sent.Skipped, $failed.Deleted, $failed.Retained, $failed.Skipped)
}

# Keeps runner-main.log from growing without bound: rotate to .1/.2 once it passes MaxBytes.
function Invoke-RunnerLogRotation {
    param([string]$LogPath, [long]$MaxBytes = 5242880)

    if (-not (Test-Path -LiteralPath $LogPath)) { return }
    if ((Get-Item -LiteralPath $LogPath).Length -lt $MaxBytes) { return }
    $old1 = $LogPath + '.1'
    $old2 = $LogPath + '.2'
    if (Test-Path -LiteralPath $old2) { Remove-Item -LiteralPath $old2 -Force }
    if (Test-Path -LiteralPath $old1) { Move-Item -LiteralPath $old1 -Destination $old2 -Force }
    Move-Item -LiteralPath $LogPath -Destination $old1 -Force
}

# Local history is evidence kept on the server; the runner keeps only recent copies.
# Rule per folder: keep files newer than RetentionDays OR among the newest N. pending-upload and outbox are never touched.
function Invoke-RunnerLocalCleanup {
    param([string]$RunnerRoot)

    $now = Get-Date
    $plans = @(
        @{ Root = (Join-Path $RunnerRoot 'data'); Folder = 'inventory-results'; Days = 30; Newest = 50; Ext = @('.csv') },
        @{ Root = (Join-Path $RunnerRoot 'data'); Folder = 'logs'; Days = 30; Newest = 30; Ext = @('.log') },
        @{ Root = $RunnerRoot; Folder = 'logs'; Days = 90; Newest = 10; Ext = @('.log') }
    )
    foreach ($plan in $plans) {
        try {
            if (-not (Test-Path -LiteralPath (Join-Path $plan.Root $plan.Folder))) { continue }
            $r = Invoke-DirectOutboxFolderCleanup -OutboxRoot $plan.Root -FolderName $plan.Folder -RetentionDays $plan.Days -RetainNewestCount $plan.Newest -Now $now -Extensions $plan.Ext
            Write-RunnerLog ("Local cleanup folder={0} deleted={1} retained={2} skipped={3}" -f $plan.Folder, $r.Deleted, $r.Retained, $r.Skipped)
        }
        catch {
            Write-RunnerLog ("Local cleanup warning folder={0}: {1}" -f $plan.Folder, $_.Exception.Message)
        }
    }
}

function Get-FileSha256 {
    param([string]$Path)

    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Move-OutboxPair {
    param(
        [string]$CsvPath,
        [string]$MetadataPath,
        [string]$TargetDir
    )

    Ensure-Directory $TargetDir
    if ($CsvPath -and (Test-Path $CsvPath)) {
        $csvTarget = Join-Path $TargetDir ([System.IO.Path]::GetFileName($CsvPath))
        Move-Item -Path $CsvPath -Destination $csvTarget -Force
    }
    if ($MetadataPath -and (Test-Path $MetadataPath)) {
        $metadataTarget = Join-Path $TargetDir ([System.IO.Path]::GetFileName($MetadataPath))
        Move-Item -Path $MetadataPath -Destination $metadataTarget -Force
    }
}

function Write-ScannerConfig {
    param(
        [string]$RunnerRoot,
        [string]$Location,
        [string]$Room
    )

    $scannerConfigPath = Join-Path $RunnerRoot 'config\inventory-destinations.json'
    $scannerConfig = [ordered]@{
        sharedRoot = ''
        defaultLocation = $Location
        defaultRoom = $Room
        googleDriveLocalPath = ''
        googleDriveWebhookUrl = ''
        googleDriveFolderId = ''
        uploadToken = ''
    }
    $scannerConfig | ConvertTo-Json -Depth 8 | Set-Content -Path $scannerConfigPath -Encoding UTF8
}

function Add-DirectPendingScan {
    param(
        [string]$SourceCsv,
        [string]$PendingDir,
        [object]$State,
        [string]$RunnerVersion,
        [datetime]$ScanStartedAt,
        [datetime]$ScanFinishedAt
    )

    Ensure-Directory $PendingDir
    $uploadId = [guid]::NewGuid().ToString()
    $csvName = "$uploadId.csv"
    $metadataName = "$uploadId.json"
    $pendingCsv = Join-Path $PendingDir $csvName
    $pendingMetadata = Join-Path $PendingDir $metadataName
    Copy-Item -Path $SourceCsv -Destination $pendingCsv -Force

    $metadata = [ordered]@{
        site_code = [string]$State.site_id
        site_id = [string]$State.site_id
        runner_id = [string]$State.runner_id
        runner_guid = [string](Get-JsonValue -Object $State -Name 'runner_guid' -Default '')
        hostname = [string]$State.hostname
        runner_version = $RunnerVersion
        transport_mode = 'direct_https'
        upload_id = $uploadId
        file_sha256 = Get-FileSha256 -Path $pendingCsv
        retry_count = 0
        scan_started_at = $ScanStartedAt.ToString('s')
        scan_finished_at = $ScanFinishedAt.ToString('s')
    }
    Write-JsonFile -Path $pendingMetadata -Value $metadata
    Write-RunnerLog "Direct HTTPS staged scan upload_id=$uploadId csv=$pendingCsv"

    return [PSCustomObject]@{
        CsvPath = $pendingCsv
        MetadataPath = $pendingMetadata
        UploadId = $uploadId
    }
}

function Start-DirectScanAndStage {
    param(
        [string]$RunnerRoot,
        [string]$ScriptRoot,
        [string]$Location,
        [string]$Room,
        [string]$OutboxRoot,
        [object]$State,
        [string]$RunnerVersion,
        [string]$Reason
    )

    $outDir = Join-Path $RunnerRoot 'data\inventory-results'
    Ensure-Directory $outDir
    Write-ScannerConfig -RunnerRoot $RunnerRoot -Location $Location -Room $Room

    $before = @{}
    @(Get-ChildItem -Path $outDir -Filter '*.csv' -File -ErrorAction SilentlyContinue) | ForEach-Object {
        $before[$_.FullName] = $true
    }

    $scanStartedAt = Get-Date
    Write-RunnerLog "Direct HTTPS scan start reason=$Reason"
    & (Join-Path $ScriptRoot 'scanner_core_v4.ps1') -Location $Location -Room $Room -AppendDateToFileName -SkipRemoteUpload
    $scanFinishedAt = Get-Date
    Write-RunnerLog "Direct HTTPS scan finished reason=$Reason"

    $candidates = @(Get-ChildItem -Path $outDir -Filter '*.csv' -File | Where-Object {
        (-not $before.ContainsKey($_.FullName)) -or $_.LastWriteTime -ge $scanStartedAt.AddSeconds(-2)
    } | Sort-Object LastWriteTime -Descending)

    if ($candidates.Count -gt 0) {
        $selected = $candidates[0]
        Write-RunnerLog "Direct HTTPS selected scan CSV: $($selected.FullName)"
        $pending = Add-DirectPendingScan -SourceCsv $selected.FullName -PendingDir (Join-Path $OutboxRoot 'pending') -State $State -RunnerVersion $RunnerVersion -ScanStartedAt $scanStartedAt -ScanFinishedAt $scanFinishedAt
        $State.last_successful_inventory_at = $scanFinishedAt.ToString('s')
        $State.last_inventory_status = 'success'
        $State.last_upload_status = 'direct_upload_pending'
        $State.last_error = ''

        return [PSCustomObject]@{
            Success = $true
            UploadId = [string]$pending.UploadId
            StartedAt = $scanStartedAt
            FinishedAt = $scanFinishedAt
            Message = 'Scan executed and staged for direct upload.'
        }
    }

    $message = 'Direct HTTPS scan completed but no new CSV was found.'
    $State.last_inventory_status = 'failed'
    $State.last_upload_status = 'direct_scan_no_csv'
    $State.last_error = $message
    Write-RunnerLog $message

    return [PSCustomObject]@{
        Success = $false
        UploadId = ''
        StartedAt = $scanStartedAt
        FinishedAt = $scanFinishedAt
        Message = $message
    }
}

$script:DirectOutboxMaxPending = 200
$script:DirectBackoffBaseMinutes = 5
$script:DirectBackoffCapMinutes = 360

function Get-DirectBackoffDelayMinutes {
    param([int]$Attempts, [bool]$Longest = $false)

    $delay = $script:DirectBackoffCapMinutes
    if (-not $Longest) {
        $exp = [Math]::Min([Math]::Max($Attempts - 1, 0), 20)
        $delay = [Math]::Min($script:DirectBackoffBaseMinutes * [Math]::Pow(2, $exp), $script:DirectBackoffCapMinutes)
    }
    $jitter = 0.8 + ((Get-Random -Minimum 0 -Maximum 4001) / 10000.0)
    return ($delay * $jitter)
}

function Remove-DirectPendingOverflow {
    param([string]$PendingDir)

    $items = @(Get-ChildItem -Path $PendingDir -Filter '*.json' -File | ForEach-Object {
        $csv = Join-Path $PendingDir ($_.BaseName + '.csv')
        $created = if (Test-Path $csv) { (Get-Item $csv).CreationTime } else { [datetime]::MinValue }
        [PSCustomObject]@{ Meta = $_.FullName; Csv = $csv; Created = $created }
    } | Sort-Object Created)
    $excess = $items.Count - $script:DirectOutboxMaxPending
    if ($excess -le 0) { return }
    foreach ($item in ($items | Select-Object -First $excess)) {
        Write-RunnerLog "Direct HTTPS outbox pending cap exceeded ($($script:DirectOutboxMaxPending)); dropping oldest upload $($item.Meta)"
        foreach ($f in @($item.Meta, $item.Csv)) {
            if (Test-Path $f) { [System.IO.File]::Delete($f) }
        }
    }
}

function Sync-DirectPendingScans {
    param(
        [string]$OutboxRoot,
        [string]$ServerBaseUrl,
        [string]$SiteToken,
        [object]$State
    )

    $pendingDir = Join-Path $OutboxRoot 'pending'
    $sentDir = Join-Path $OutboxRoot 'sent'
    $failedDir = Join-Path $OutboxRoot 'failed'
    Ensure-Directory $pendingDir
    Ensure-Directory $sentDir
    Ensure-Directory $failedDir

    Remove-DirectPendingOverflow -PendingDir $pendingDir
    $headers = New-DirectRunnerHeaders -SiteId ([string]$State.site_id) -SiteToken $SiteToken
    $metadataFiles = @(Get-ChildItem -Path $pendingDir -Filter '*.json' -File | Sort-Object LastWriteTime)
    foreach ($metadataFile in $metadataFiles) {
        $metadata = Read-JsonFile -Path $metadataFile.FullName
        if (-not $metadata) {
            Write-RunnerLog "Direct HTTPS upload metadata unreadable; moving to failed: $($metadataFile.FullName)"
            $failedMetadata = Join-Path $failedDir $metadataFile.Name
            Move-Item -Path $metadataFile.FullName -Destination $failedMetadata -Force
            continue
        }

        $metadataStem = [System.IO.Path]::GetFileNameWithoutExtension($metadataFile.Name)
        $uploadId = [string](Get-JsonValue -Object $metadata -Name 'upload_id' -Default $metadataStem)
        $csvPath = Join-Path $pendingDir "$metadataStem.csv"
        if (-not (Test-Path $csvPath)) {
            Write-RunnerLog "Direct HTTPS phase=upload CSV missing upload_id=$uploadId metadata=$($metadataFile.FullName); moving available pair to failed."
            Move-OutboxPair -CsvPath $csvPath -MetadataPath $metadataFile.FullName -TargetDir $failedDir
            continue
        }

        $nextAttemptRaw = [string](Get-JsonValue -Object $metadata -Name 'next_attempt_at' -Default '')
        $nextAttemptAt = [datetime]::MinValue
        if ($nextAttemptRaw -and [datetime]::TryParse($nextAttemptRaw, [ref]$nextAttemptAt) -and (Get-Date) -lt $nextAttemptAt) {
            Write-RunnerLog "Direct HTTPS phase=upload deferred upload_id=$uploadId next_attempt_at=$nextAttemptRaw"
            continue
        }

        $retryCount = [int](Get-JsonValue -Object $metadata -Name 'retry_count' -Default 0)
        Set-ObjectProperty -Object $metadata -Name 'retry_count' -Value $retryCount
        Set-ObjectProperty -Object $metadata -Name 'file_sha256' -Value (Get-FileSha256 -Path $csvPath)
        Write-JsonFile -Path $metadataFile.FullName -Value $metadata
        $metadataJson = Get-Content -Path $metadataFile.FullName -Raw -Encoding UTF8

        Write-RunnerLog "Direct HTTPS phase=upload attempt upload_id=$uploadId retry_count=$retryCount file=$csvPath"
        try {
            $response = Invoke-DirectRunnerMultipart -ServerBaseUrl $ServerBaseUrl -Path '/api/direct-runner/scans' -Headers $headers -MetadataJson $metadataJson -FilePath $csvPath
            Write-RunnerLog ("Direct HTTPS phase=upload success upload_id=$uploadId retry_count=$retryCount response=" + ($response | ConvertTo-Json -Compress))
            Move-OutboxPair -CsvPath $csvPath -MetadataPath $metadataFile.FullName -TargetDir $sentDir
            $State.last_upload_status = 'direct_upload_uploaded'
            $State.last_error = ''
            Set-ObjectProperty -Object $State -Name 'last_direct_upload_at' -Value (Get-Date).ToString('s')
        }
        catch {
            $statusCode = ''
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            }
            $message = $_.Exception.Message
            Write-RunnerLog "Direct HTTPS phase=upload failed upload_id=$uploadId retry_count=$retryCount status=$statusCode error=$message"
            Set-ObjectProperty -Object $metadata -Name 'retry_count' -Value ($retryCount + 1)
            Set-ObjectProperty -Object $metadata -Name 'last_error' -Value $message
            Set-ObjectProperty -Object $metadata -Name 'last_attempt_at' -Value (Get-Date).ToString('s')
            $delayMinutes = Get-DirectBackoffDelayMinutes -Attempts ($retryCount + 1) -Longest ($statusCode -in @(401, 403))
            Set-ObjectProperty -Object $metadata -Name 'attempt_count' -Value ($retryCount + 1)
            Set-ObjectProperty -Object $metadata -Name 'next_attempt_at' -Value (Get-Date).AddMinutes($delayMinutes).ToString('s')
            Write-JsonFile -Path $metadataFile.FullName -Value $metadata
            $State.last_upload_status = 'direct_upload_pending'
            $State.last_error = "Direct HTTPS upload failed: $message"

            if ($statusCode -in @(400, 404, 422)) {
                Write-RunnerLog "Direct HTTPS phase=upload unrecoverable; moving pair to failed upload_id=$uploadId retry_count=$($retryCount + 1) status=$statusCode"
                Move-OutboxPair -CsvPath $csvPath -MetadataPath $metadataFile.FullName -TargetDir $failedDir
                $State.last_upload_status = 'direct_upload_failed'
            }
        }
    }
}

function Write-DirectCommandAckFile {
    param(
        [string]$RunnerRoot,
        [object]$Ack
    )

    $ackDir = Join-Path $RunnerRoot 'state\direct-acks'
    Ensure-Directory $ackDir
    $commandId = [string](Get-JsonValue -Object $Ack -Name 'command_id' -Default '')
    if (-not $commandId) {
        Write-RunnerLog 'Direct HTTPS ACK queue skipped because command_id is missing.'
        return
    }

    $ackPath = Join-Path $ackDir "$commandId.json"
    Write-JsonFile -Path $ackPath -Value $Ack
    $status = [string](Get-JsonValue -Object $Ack -Name 'status' -Default '')
    Write-RunnerLog "Direct HTTPS ACK queued command_id=$commandId status=$status"
}

function Sync-DirectCommandAcks {
    param(
        [string]$RunnerRoot,
        [string]$ServerBaseUrl,
        [string]$SiteToken,
        [object]$State
    )

    $ackDir = Join-Path $RunnerRoot 'state\direct-acks'
    $commandDir = Join-Path $RunnerRoot 'state\direct-commands'
    Ensure-Directory $ackDir
    $headers = New-DirectRunnerHeaders -SiteId ([string]$State.site_id) -SiteToken $SiteToken
    $ackFiles = @(Get-ChildItem -Path $ackDir -Filter '*.json' -File | Sort-Object LastWriteTime)
    foreach ($ackFile in $ackFiles) {
        $ack = Read-JsonFile -Path $ackFile.FullName
        if (-not $ack) {
            Write-RunnerLog "Direct HTTPS ACK unreadable, leaving for inspection: $($ackFile.FullName)"
            continue
        }

        $commandId = [string](Get-JsonValue -Object $ack -Name 'command_id' -Default ([System.IO.Path]::GetFileNameWithoutExtension($ackFile.Name)))
        $ackStatus = [string](Get-JsonValue -Object $ack -Name 'status' -Default '')
        $retryCount = [int](Get-JsonValue -Object $ack -Name 'retry_count' -Default 0)
        Write-RunnerLog "Direct HTTPS phase=ack attempt command_id=$commandId status=$ackStatus retry_count=$retryCount"
        try {
            $response = Invoke-DirectRunnerJson -ServerBaseUrl $ServerBaseUrl -Path '/api/direct-runner/commands/ack' -Headers $headers -Payload $ack
            Write-RunnerLog ("Direct HTTPS phase=ack success command_id=$commandId retry_count=$retryCount response=" + ($response | ConvertTo-Json -Compress))
            Remove-Item -Path $ackFile.FullName -Force -ErrorAction SilentlyContinue
            $commandPath = Join-Path $commandDir "$commandId.json"
            if (Test-Path $commandPath) {
                Remove-Item -Path $commandPath -Force -ErrorAction SilentlyContinue
            }
        }
        catch {
            $statusCode = ''
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            }
            Write-RunnerLog "Direct HTTPS phase=ack failure command_id=$commandId retry_count=$retryCount status=$statusCode error=$($_.Exception.Message)"
            Set-ObjectProperty -Object $ack -Name 'retry_count' -Value ($retryCount + 1)
            Set-ObjectProperty -Object $ack -Name 'last_error' -Value $_.Exception.Message
            Set-ObjectProperty -Object $ack -Name 'last_attempt_at' -Value (Get-Date).ToString('s')
            Write-JsonFile -Path $ackFile.FullName -Value $ack
        }
    }
}

function Invoke-DirectStagedCommands {
    param(
        [string]$RunnerRoot,
        [string]$ScriptRoot,
        [string]$Location,
        [string]$Room,
        [string]$OutboxRoot,
        [string]$ServerBaseUrl,
        [string]$SiteToken,
        [object]$State,
        [string]$RunnerVersion
    )

    $commandDir = Join-Path $RunnerRoot 'state\direct-commands'
    $ackDir = Join-Path $RunnerRoot 'state\direct-acks'
    Ensure-Directory $commandDir
    Ensure-Directory $ackDir

    $commandFiles = @(Get-ChildItem -Path $commandDir -Filter '*.json' -File | Sort-Object LastWriteTime)
    foreach ($commandFile in $commandFiles) {
        $command = Read-JsonFile -Path $commandFile.FullName
        if (-not $command) {
            Write-RunnerLog "Direct HTTPS staged command unreadable: $($commandFile.FullName)"
            continue
        }

        $commandId = [string](Get-JsonValue -Object $command -Name 'id' -Default ([System.IO.Path]::GetFileNameWithoutExtension($commandFile.Name)))
        $commandType = ([string](Get-JsonValue -Object $command -Name 'command_type' -Default '')).Trim().ToLowerInvariant()
        $ackPath = Join-Path $ackDir "$commandId.json"
        if (Test-Path $ackPath) {
            Write-RunnerLog "Direct HTTPS command already executed; pending ACK command_id=$commandId type=$commandType"
            continue
        }

        $startedAt = Get-Date
        Write-RunnerLog "Direct HTTPS command execution start command_id=$commandId type=$commandType"
        $status = 'failed'
        $message = ''
        $resultUploadId = ''

        try {
            if ($commandType -in @('scan_now', 'manual_scan')) {
                $scanResult = Start-DirectScanAndStage -RunnerRoot $RunnerRoot -ScriptRoot $ScriptRoot -Location $Location -Room $Room -OutboxRoot $OutboxRoot -State $State -RunnerVersion $RunnerVersion -Reason "command_$commandType"
                Sync-DirectPendingScans -OutboxRoot $OutboxRoot -ServerBaseUrl $ServerBaseUrl -SiteToken $SiteToken -State $State
                $status = if ($scanResult.Success) { 'succeeded' } else { 'failed' }
                $message = [string]$scanResult.Message
                $resultUploadId = [string]$scanResult.UploadId
                if ($resultUploadId) {
                    Write-RunnerLog "Direct HTTPS command scan result command_id=$commandId upload_id=$resultUploadId"
                }
            } else {
                $message = "Unsupported direct runner command: $commandType"
                Write-RunnerLog $message
            }
        }
        catch {
            $status = 'failed'
            $message = $_.Exception.Message
            Write-RunnerLog "Direct HTTPS command execution failed command_id=$commandId type=$commandType error=$message"
        }

        $finishedAt = Get-Date
        Write-RunnerLog "Direct HTTPS command execution end command_id=$commandId type=$commandType status=$status"
        $commandIdValue = $commandId
        $parsedCommandId = 0
        if ([int]::TryParse($commandId, [ref]$parsedCommandId)) {
            $commandIdValue = $parsedCommandId
        }

        $ack = [ordered]@{
            site_code = [string]$State.site_id
            site_id = [string]$State.site_id
            runner_id = [string]$State.runner_id
            runner_guid = [string](Get-JsonValue -Object $State -Name 'runner_guid' -Default '')
            hostname = [string]$State.hostname
            command_id = $commandIdValue
            status = $status
            started_at = $startedAt.ToString('s')
            finished_at = $finishedAt.ToString('s')
            message = $message
            retry_count = 0
        }
        if ($resultUploadId) {
            $ack['result_upload_id'] = $resultUploadId
        }
        Write-DirectCommandAckFile -RunnerRoot $RunnerRoot -Ack $ack
        Sync-DirectCommandAcks -RunnerRoot $RunnerRoot -ServerBaseUrl $ServerBaseUrl -SiteToken $SiteToken -State $State
    }
}

function Send-DirectRunnerHeartbeat {
    param(
        [object]$Config,
        [object]$State,
        [string]$ServerBaseUrl,
        [string]$SiteToken
    )

    $siteId = [string]$State.site_id
    $headers = New-DirectRunnerHeaders -SiteId $siteId -SiteToken $SiteToken
    $payload = [ordered]@{
        site_code = $siteId
        site_id = $siteId
        runner_id = [string]$State.runner_id
        runner_guid = [string](Get-JsonValue -Object $State -Name 'runner_guid' -Default '')
        hostname = [string]$State.hostname
        runner_version = [string]$State.runner_version
        transport_mode = 'direct_https'
        local_time = (Get-Date).ToString('s')
        last_scan_at = [string](Get-JsonValue -Object $State -Name 'last_successful_inventory_at' -Default '')
        last_upload_status = [string](Get-JsonValue -Object $State -Name 'last_upload_status' -Default '')
        last_error = [string](Get-JsonValue -Object $State -Name 'last_error' -Default '')
    }

    Write-RunnerLog "Direct HTTPS phase=heartbeat attempt -> $ServerBaseUrl/api/direct-runner/heartbeat"
    $response = Invoke-DirectRunnerJson -ServerBaseUrl $ServerBaseUrl -Path '/api/direct-runner/heartbeat' -Headers $headers -Payload $payload
    Write-RunnerLog ("Direct HTTPS phase=heartbeat success -> " + ($response | ConvertTo-Json -Compress))
}

function Poll-DirectRunnerCommands {
    param(
        [object]$State,
        [string]$ServerBaseUrl,
        [string]$SiteToken,
        [string]$RunnerVersion,
        [string]$RunnerRoot
    )

    $siteId = [string]$State.site_id
    $headers = New-DirectRunnerHeaders -SiteId $siteId -SiteToken $SiteToken
    $payload = [ordered]@{
        site_code = $siteId
        site_id = $siteId
        runner_id = [string]$State.runner_id
        runner_guid = [string](Get-JsonValue -Object $State -Name 'runner_guid' -Default '')
        hostname = [string]$State.hostname
        runner_version = $RunnerVersion
        transport_mode = 'direct_https'
    }

    Write-RunnerLog "Direct HTTPS phase=poll attempt -> $ServerBaseUrl/api/direct-runner/commands/poll"
    $response = Invoke-DirectRunnerJson -ServerBaseUrl $ServerBaseUrl -Path '/api/direct-runner/commands/poll' -Headers $headers -Payload $payload
    $commands = @()
    if ($response -and ($response.PSObject.Properties.Name -contains 'commands') -and $response.commands) {
        $commands = @($response.commands)
    }

    Write-RunnerLog "Direct HTTPS phase=poll success commands_returned=$($commands.Count)"
    $commandDir = Join-Path $RunnerRoot 'state\direct-commands'
    Ensure-Directory $commandDir

    foreach ($command in $commands) {
        $commandId = [string](Get-JsonValue -Object $command -Name 'id' -Default '')
        $commandType = [string](Get-JsonValue -Object $command -Name 'command_type' -Default '')
        Write-RunnerLog "Direct HTTPS phase=poll command received command_id=$commandId type=$commandType"

        if ($commandId) {
            Write-JsonFile -Path (Join-Path $commandDir "$commandId.json") -Value $command
        }
    }

    return $commands
}

function Start-LocalApplyUpdate {
    param(
        [string]$RunnerRoot,
        [string]$SharedRoot,
        [string]$ExpectedVersion,
        [object]$State,
        [object]$PendingCommand
    )

    $packageRoot = Join-Path $SharedRoot 'packages\runner\current'
    $packageBootstrapUpdater = Join-Path $packageRoot 'scripts\bootstrap_update_runner.ps1'
    if (-not (Test-Path $packageBootstrapUpdater)) {
        throw "Package bootstrap updater not found: $packageBootstrapUpdater"
    }

    # Staging lives under the ACL-protected install root (not world-writable PUBLIC).
    $baseStagingRoot = Join-Path $RunnerRoot 'update-staging'
    Ensure-Directory $baseStagingRoot
    $stagingRoot = Join-Path $baseStagingRoot 'current'
    Copy-DirectoryMirror -Source $packageRoot -Destination $stagingRoot   # removes then recreates clean

    $localBootstrapUpdater = Join-Path $stagingRoot 'scripts\bootstrap_update_runner.ps1'
    if (-not (Test-Path $localBootstrapUpdater)) {
        throw "Local staged bootstrap updater not found: $localBootstrapUpdater"
    }

    # Integrity gate: verify the staged copy (the share can no longer change it) BEFORE anything runs as SYSTEM.
    # Policy: key present -> must verify; requireSignedUpdates=true -> key + valid signature mandatory;
    # no key and not required -> allow with warning (already-deployed runners, backward compat).
    $publicKeyPath = Join-Path $RunnerRoot 'trust\update-public-key.xml'
    $requireSigned = [bool](Get-JsonValue -Object $config -Name 'requireSignedUpdates' -Default $false)
    $keyPresent = Test-Path -LiteralPath $publicKeyPath -PathType Leaf
    $verifierPath = Join-Path $PSScriptRoot 'verify_update_package.ps1'
    $rejectReason = ''
    if ($keyPresent -or $requireSigned) {
        if (-not $keyPresent) {
            $rejectReason = 'public key not installed'
        } elseif (-not (Test-Path -LiteralPath $verifierPath)) {
            $rejectReason = 'verifier script missing'
        } else {
            . $verifierPath
            $verdict = Test-RunnerUpdatePackage -PackageRoot $stagingRoot -PublicKeyXmlPath $publicKeyPath
            if (-not $verdict.Ok) { $rejectReason = [string]$verdict.Reason }
        }
    } else {
        Write-RunnerLog 'WARNING: no update public key installed and requireSignedUpdates is not set; applying UNVERIFIED update package.'
    }
    if ($rejectReason) {
        Write-RunnerLog "Update package rejected: $rejectReason"
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force -ErrorAction SilentlyContinue
        $State.last_inventory_status = 'update_rejected'
        $State.last_upload_status = 'update_rejected'
        $State.last_error = 'update package signature invalid'
        $State.last_command_type = if ($PendingCommand) { [string]$PendingCommand.command_type } else { 'manifest_update' }
        Write-CommandAck -SharedRoot $SharedRoot -State $State -PendingCommand $PendingCommand -Status 'failed' -Message 'update package signature invalid'
        return
    }

    $taskName = 'InternalInventoryRunner-ApplyUpdate'
    # Launcher also lives in the protected staging dir (SYSTEM runs it), not in user-writable state\.
    $launcherPath = Join-Path $baseStagingRoot 'apply-update-runner.ps1'
    $qBootstrap = $localBootstrapUpdater -replace "'", "''"
    $qRoot = $RunnerRoot -replace "'", "''"
    $qStaging = $stagingRoot -replace "'", "''"
    $qVersion = $ExpectedVersion -replace "'", "''"
    $launcherScript = @"
Start-Sleep -Seconds 10
& '$qBootstrap' -RunnerRoot '$qRoot' -PackageRoot '$qStaging' -ExpectedVersion '$qVersion' -RunAfterUpdate -NoElevate
"@
    Set-Content -Path $launcherPath -Value $launcherScript -Encoding UTF8

    $taskRun = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' + $launcherPath + '"'
    $startTime = (Get-Date).AddMinutes(1).ToString('HH:mm')

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $createOutput = & schtasks.exe /Create /TN $taskName /SC ONCE /ST $startTime /TR $taskRun /RU SYSTEM /RL HIGHEST /F 2>&1
        $createExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($createExitCode -ne 0) {
        throw "Could not create local apply-update task: $createOutput"
    }

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $runOutput = & schtasks.exe /Run /TN $taskName 2>&1
        $runExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($runExitCode -ne 0) {
        throw "Could not start local apply-update task: $runOutput"
    }

    $State.last_inventory_status = 'update_deferred'
    $State.last_upload_status = 'update_deferred'
    $State.last_error = ''
    $State.last_command_type = if ($PendingCommand) { [string]$PendingCommand.command_type } else { 'manifest_update' }
    Set-ObjectProperty -Object $State -Name 'target_runner_version' -Value $ExpectedVersion
    Set-ObjectProperty -Object $State -Name 'update_staged_at' -Value (Get-Date).ToString('s')
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$runnerRoot = Split-Path -Parent $scriptRoot
$configPath = Join-Path $runnerRoot 'config\runner-config.json'
$config = Read-JsonFile -Path $configPath
if (-not $config) {
    throw "runner-config.json not found."
}

$transportMode = ([string](Get-JsonValue -Object $config -Name 'transport_mode' -Default 'collector_share')).Trim().ToLowerInvariant()
$runnerId = [string](Get-JsonValue -Object $config -Name 'runnerId' -Default '')
$runnerGuid = [string](Get-JsonValue -Object $config -Name 'runnerGuid' -Default '')
$siteId = [string](Get-JsonValue -Object $config -Name 'siteId' -Default '')
$siteName = [string](Get-JsonValue -Object $config -Name 'siteName' -Default '')
$sharedRoot = ''
$collectorName = ''
if ($transportMode -ne 'direct_https') {
    $sharedRoot = [string](Get-JsonValue -Object $config -Name 'sharedRoot' -Default '')
    $collectorName = [string](Get-JsonValue -Object $config -Name 'collectorName' -Default '')
}
$installRoot = [string](Get-JsonValue -Object $config -Name 'installRoot' -Default '')
$runnerVersion = [string](Get-JsonValue -Object $config -Name 'runnerVersion' -Default '')
$location = [string](Get-JsonValue -Object $config -Name 'location' -Default '')
$room = [string](Get-JsonValue -Object $config -Name 'room' -Default '')
$serverBaseUrl = [string](Get-JsonValue -Object $config -Name 'serverBaseUrl' -Default '')
$siteToken = [string](Get-JsonValue -Object $config -Name 'siteToken' -Default '')
$scanIntervalMinutes = [int](Get-JsonValue -Object $config -Name 'scanIntervalMinutes' -Default 240)
$runnerPollIntervalMinutes = [int](Get-JsonValue -Object $config -Name 'runnerPollIntervalMinutes' -Default 5)
$taskRandomDelayMinutes = [int](Get-JsonValue -Object $config -Name 'taskRandomDelayMinutes' -Default 15)

$stateDir = Join-Path $runnerRoot 'state'
$dataDir = Join-Path $runnerRoot 'data'
$logsDir = Join-Path $runnerRoot 'logs'
Ensure-Directory $stateDir
Ensure-Directory $dataDir
Ensure-Directory $logsDir
$script:RunnerLogPath = Join-Path $logsDir 'runner-main.log'

# Overlap protection: only one runner process at a time (scheduled + startup + manual runs).
$runMutex = New-Object System.Threading.Mutex($false, 'Global\InternalInventoryRunner')
$mutexAcquired = $false
try {
    $mutexAcquired = $runMutex.WaitOne(0)
}
catch [System.Threading.AbandonedMutexException] {
    $mutexAcquired = $true   # previous owner died; we now own it
}
catch [System.UnauthorizedAccessException] {
    $mutexAcquired = $false  # held by another account's instance
}
if (-not $mutexAcquired) {
    Write-RunnerLog 'Another runner instance is already running; exiting.'
    $runMutex.Dispose()
    exit 0
}
try {

try {
    Invoke-RunnerLogRotation -LogPath $script:RunnerLogPath
    Invoke-RunnerLocalCleanup -RunnerRoot $runnerRoot
}
catch {
    Write-RunnerLog ('Local cleanup warning: ' + $_.Exception.Message)
}

$statePath = Join-Path $stateDir 'runner-state.json'
$state = Read-JsonFile -Path $statePath
if (-not $state) {
    $state = [ordered]@{
        runner_id = $runnerId
        runner_guid = $runnerGuid
        hostname = $env:COMPUTERNAME
        site_id = $siteId
        site_name = $siteName
        runner_version = $runnerVersion
        install_mode = 'scheduled_task'
        scan_interval_minutes = $scanIntervalMinutes
        runner_poll_interval_minutes = $runnerPollIntervalMinutes
        task_random_delay_minutes = $taskRandomDelayMinutes
        last_seen_at = ''
        last_successful_inventory_at = ''
        last_inventory_status = ''
        last_upload_status = ''
        last_error = ''
        last_command_seen_at = ''
        last_command_type = ''
        last_run_reason = 'scheduled'
    }
    if ($transportMode -ne 'direct_https') {
        Set-ObjectProperty -Object $state -Name 'collector_name' -Value $collectorName
        Set-ObjectProperty -Object $state -Name 'shared_root' -Value $sharedRoot
    }
}

if (-not $runnerGuid) {
    $runnerGuid = [string](Get-JsonValue -Object $state -Name 'runner_guid' -Default '')
}
if (-not $runnerGuid) {
    $runnerGuid = [guid]::NewGuid().ToString()
}
Set-ObjectProperty -Object $config -Name 'runnerGuid' -Value $runnerGuid
Write-JsonFile -Path $configPath -Value $config

if ($transportMode -eq 'direct_https') {
    Write-RunnerLog 'Direct HTTPS transport mode selected.'

    $state.runner_id = $runnerId
    Set-ObjectProperty -Object $state -Name 'runner_guid' -Value $runnerGuid
    $state.hostname = $env:COMPUTERNAME
    $state.site_id = $siteId
    $state.site_name = $siteName
    $state.runner_version = $runnerVersion
    $state.install_mode = 'direct_https'
    Set-ObjectProperty -Object $state -Name 'transport_mode' -Value 'direct_https'
    $state.scan_interval_minutes = $scanIntervalMinutes
    Set-ObjectProperty -Object $state -Name 'runner_poll_interval_minutes' -Value $runnerPollIntervalMinutes
    $state.task_random_delay_minutes = $taskRandomDelayMinutes
    $state.last_seen_at = (Get-Date).ToString('s')
    $state.last_run_reason = 'direct_heartbeat'

    $outboxRoot = Ensure-DirectRunnerOutbox -RunnerRoot $runnerRoot

    $missing = @()
    if (-not $serverBaseUrl) { $missing += 'serverBaseUrl' }
    if (-not $siteToken) { $missing += 'siteToken' }
    if (-not $siteId) { $missing += 'siteId' }
    if (-not $runnerId) { $missing += 'runnerId' }

    if ($missing.Count -gt 0) {
        $message = 'Direct HTTPS config missing required value(s): ' + ($missing -join ', ')
        Write-RunnerLog $message
        $state.last_upload_status = 'direct_config_missing'
        $state.last_error = $message
        Write-JsonFile -Path $statePath -Value $state
        exit 1
    }

    $directHadError = $false

    try {
        Send-DirectRunnerHeartbeat -Config $config -State $state -ServerBaseUrl $serverBaseUrl -SiteToken $siteToken
        Set-ObjectProperty -Object $state -Name 'last_direct_heartbeat_at' -Value (Get-Date).ToString('s')
        if ([string](Get-JsonValue -Object $state -Name 'last_upload_status' -Default '') -eq 'direct_heartbeat_failed') {
            $state.last_upload_status = 'direct_heartbeat_synced'
        }
        if ([string](Get-JsonValue -Object $state -Name 'last_error' -Default '') -like 'Direct HTTPS heartbeat failed*') {
            $state.last_error = ''
        }
    }
    catch {
        $directHadError = $true
        $message = 'Direct HTTPS phase=heartbeat failed: ' + $_.Exception.Message
        Write-RunnerLog $message
        $state.last_upload_status = 'direct_heartbeat_failed'
        $state.last_error = $message
    }

    try {
        $directCommands = @(Poll-DirectRunnerCommands -State $state -ServerBaseUrl $serverBaseUrl -SiteToken $siteToken -RunnerVersion $runnerVersion -RunnerRoot $runnerRoot)
        Set-ObjectProperty -Object $state -Name 'last_direct_poll_at' -Value (Get-Date).ToString('s')
        if ($directCommands.Count -gt 0) {
            $state.last_command_seen_at = (Get-Date).ToString('s')
            $state.last_command_type = [string](Get-JsonValue -Object $directCommands[0] -Name 'command_type' -Default '')
        }
    }
    catch {
        $directHadError = $true
        $pollMessage = 'Direct HTTPS phase=poll failed: ' + $_.Exception.Message
        Write-RunnerLog $pollMessage
        Set-ObjectProperty -Object $state -Name 'last_direct_poll_error' -Value $pollMessage
    }

    try {
        Sync-DirectCommandAcks -RunnerRoot $runnerRoot -ServerBaseUrl $serverBaseUrl -SiteToken $siteToken -State $state
    }
    catch {
        $directHadError = $true
        $ackMessage = 'Direct HTTPS phase=ack failed before retry loop completed: ' + $_.Exception.Message
        Write-RunnerLog $ackMessage
        Set-ObjectProperty -Object $state -Name 'last_direct_ack_error' -Value $ackMessage
    }

    try {
        Invoke-DirectStagedCommands -RunnerRoot $runnerRoot -ScriptRoot $scriptRoot -Location $location -Room $room -OutboxRoot $outboxRoot -ServerBaseUrl $serverBaseUrl -SiteToken $siteToken -State $state -RunnerVersion $runnerVersion
    }
    catch {
        $directHadError = $true
        $ackMessage = 'Direct HTTPS phase=ack command execution failed before ACK could be queued: ' + $_.Exception.Message
        Write-RunnerLog $ackMessage
        Set-ObjectProperty -Object $state -Name 'last_direct_ack_error' -Value $ackMessage
    }

    $lastSuccessfulInventoryAt = ConvertTo-DateTimeOrNull $state.last_successful_inventory_at
    $scheduledScanDue = $false
    if (-not $lastSuccessfulInventoryAt) {
        $scheduledScanDue = $true
    } elseif ($lastSuccessfulInventoryAt.AddMinutes($scanIntervalMinutes) -le (Get-Date)) {
        $scheduledScanDue = $true
    }

    try {
        if ($scheduledScanDue) {
            $state.last_run_reason = 'direct_scheduled_due'
            Start-DirectScanAndStage -RunnerRoot $runnerRoot -ScriptRoot $scriptRoot -Location $location -Room $room -OutboxRoot $outboxRoot -State $state -RunnerVersion $runnerVersion -Reason 'scheduled_due' | Out-Null
        } else {
            Write-RunnerLog 'Direct HTTPS scan not due; heartbeat only plus pending upload retry.'
        }
    }
    catch {
        $directHadError = $true
        $uploadMessage = 'Direct HTTPS phase=upload scan stage failed: ' + $_.Exception.Message
        Write-RunnerLog $uploadMessage
        $state.last_upload_status = 'direct_upload_pending'
        $state.last_error = $uploadMessage
    }

    try {
        Sync-DirectPendingScans -OutboxRoot $outboxRoot -ServerBaseUrl $serverBaseUrl -SiteToken $siteToken -State $state
    }
    catch {
        $directHadError = $true
        $uploadMessage = 'Direct HTTPS phase=upload retry loop failed: ' + $_.Exception.Message
        Write-RunnerLog $uploadMessage
        $state.last_upload_status = 'direct_upload_pending'
        $state.last_error = $uploadMessage
    }

    try {
        Sync-DirectCommandAcks -RunnerRoot $runnerRoot -ServerBaseUrl $serverBaseUrl -SiteToken $siteToken -State $state
    }
    catch {
        $directHadError = $true
        $ackMessage = 'Direct HTTPS phase=ack retry loop failed: ' + $_.Exception.Message
        Write-RunnerLog $ackMessage
        Set-ObjectProperty -Object $state -Name 'last_direct_ack_error' -Value $ackMessage
    }

    try {
        Invoke-DirectOutboxCleanup -OutboxRoot $outboxRoot
    }
    catch {
        Write-RunnerLog ('Direct HTTPS outbox cleanup warning: ' + $_.Exception.Message)
    }

    Write-JsonFile -Path $statePath -Value $state
    if ($directHadError) {
        exit 1
    }
    exit 0
}

if ($transportMode -and $transportMode -ne 'collector_share') {
    Write-RunnerLog "Unknown transport_mode '$transportMode'; falling back to collector_share."
}

$manifest = $null
try {
    $manifestPath = Join-Path $sharedRoot 'packages\runner\runner-manifest.json'
    if (Test-Path $manifestPath) {
        $manifest = Read-JsonFile -Path $manifestPath
    }
}
catch {
    Write-RunnerLog ("Manifest read skipped: " + $_.Exception.Message)
}

$pendingCommand = $null
$commandPath = ''
try {
    $commandPath = Join-Path $sharedRoot ('control\commands\' + $runnerId + '.json')
    if (Test-Path $commandPath) {
        $pendingCommand = Read-JsonFile -Path $commandPath
        if (-not ($pendingCommand -and [string]$pendingCommand.command_type -eq 'repair_update')) {
            Remove-Item -Path $commandPath -Force -ErrorAction SilentlyContinue
        }
    }
}
catch {
    Write-RunnerLog ("Command poll skipped: " + $_.Exception.Message)
}

$state.runner_id = $runnerId
Set-ObjectProperty -Object $state -Name 'runner_guid' -Value $runnerGuid
$state.hostname = $env:COMPUTERNAME
$state.site_id = $siteId
$state.site_name = $siteName
Set-ObjectProperty -Object $state -Name 'collector_name' -Value $collectorName
$state.runner_version = $runnerVersion
$state.install_mode = if (Use-CurrentUserTaskMode -Config $config) { 'scheduled_task_current_user' } else { 'scheduled_task_system' }
Set-ObjectProperty -Object $state -Name 'shared_root' -Value $sharedRoot
$state.scan_interval_minutes = $scanIntervalMinutes
Set-ObjectProperty -Object $state -Name 'runner_poll_interval_minutes' -Value $runnerPollIntervalMinutes
$state.task_random_delay_minutes = $taskRandomDelayMinutes
$state.last_seen_at = (Get-Date).ToString('s')
$state.last_command_seen_at = if ($pendingCommand) { (Get-Date).ToString('s') } else { $state.last_command_seen_at }
$state.last_command_type = if ($pendingCommand) { [string]$pendingCommand.command_type } else { $state.last_command_type }
$commandType = if ($pendingCommand) { [string]$pendingCommand.command_type } else { '' }
$lastSuccessfulInventoryAt = ConvertTo-DateTimeOrNull $state.last_successful_inventory_at
$scheduledScanDue = $false
if (-not $lastSuccessfulInventoryAt) {
    $scheduledScanDue = $true
} elseif ($lastSuccessfulInventoryAt.AddMinutes($scanIntervalMinutes) -le (Get-Date)) {
    $scheduledScanDue = $true
}
$shouldRunScan = ($commandType -eq 'scan_now') -or ((-not $pendingCommand) -and $scheduledScanDue)
$state.last_run_reason = if ($commandType -eq 'scan_now') { 'manual_command' } elseif ($scheduledScanDue -and -not $pendingCommand) { 'scheduled_due' } else { 'command_poll' }

$manifestVersion = ''
if ($manifest -and $manifest.runner_version) {
    $manifestVersion = [string]$manifest.runner_version
}
$manifestUpdateDue = (-not $pendingCommand) -and (Test-VersionGreaterThan -Candidate $manifestVersion -Current $runnerVersion)
$repairUpdateAlreadyApplied = $pendingCommand `
    -and $commandType -eq 'repair_update' `
    -and $manifestVersion `
    -and (-not (Test-VersionGreaterThan -Candidate $manifestVersion -Current $runnerVersion))
if ((-not $pendingCommand) -and $manifestVersion -and (-not (Test-VersionGreaterThan -Candidate $manifestVersion -Current $runnerVersion))) {
    if ([string](Get-JsonValue -Object $state -Name 'last_inventory_status' -Default '') -eq 'update_deferred') {
        $state.last_inventory_status = 'bootstrap_update_applied'
    }
}

try {
    if ($repairUpdateAlreadyApplied) {
        $state.last_inventory_status = 'update_already_applied'
        $state.last_upload_status = 'update_already_applied'
        $state.last_error = ''
        Set-ObjectProperty -Object $state -Name 'target_runner_version' -Value $manifestVersion
        Write-CommandAck -SharedRoot $sharedRoot -State $state -PendingCommand $pendingCommand -Status 'completed' -Message "Runner already at target version $runnerVersion."
        if ($commandPath -and (Test-Path $commandPath)) {
            Remove-Item -Path $commandPath -Force -ErrorAction SilentlyContinue
        }
    } elseif (($pendingCommand -and $commandType -eq 'repair_update') -or $manifestUpdateDue) {
        $expectedVersion = $runnerVersion
        if ($manifestVersion) {
            $expectedVersion = $manifestVersion
        }

        Start-LocalApplyUpdate -RunnerRoot $runnerRoot -SharedRoot $sharedRoot -ExpectedVersion $expectedVersion -State $state -PendingCommand $pendingCommand
        if ([string]$state.last_inventory_status -eq 'update_rejected' -and $commandPath -and (Test-Path $commandPath)) {
            Remove-Item -Path $commandPath -Force -ErrorAction SilentlyContinue   # failed ACK written; do not redeliver
        }

        Write-JsonFile -Path $statePath -Value $state
        Sync-StateToBranchShare -SharedRoot $sharedRoot -RunnerId $runnerId -StatePath $statePath -State $state
        exit 0
    } else {
        if ($shouldRunScan) {
            $scannerConfigPath = Join-Path $runnerRoot 'config\inventory-destinations.json'
            $scannerConfig = [ordered]@{
                sharedRoot = $sharedRoot
                defaultLocation = $location
                defaultRoom = $room
                googleDriveLocalPath = ''
                googleDriveWebhookUrl = ''
                googleDriveFolderId = ''
                uploadToken = ''
            }
            $scannerConfig | ConvertTo-Json -Depth 8 | Set-Content -Path $scannerConfigPath -Encoding UTF8

            $outDir = Join-Path $runnerRoot 'data\inventory-results'
            Ensure-Directory $outDir

            & (Join-Path $scriptRoot 'scanner_core_v4.ps1') -Location $location -Room $room -AppendDateToFileName -SkipRemoteUpload

            $after = @(Get-ChildItem -Path $outDir -Filter '*.csv' | Sort-Object LastWriteTime -Descending)
            if ($after.Count -gt 0) {
                $latest = $after[0]
                $newName = '{0}__{1}.csv' -f $runnerId, (Get-Date).ToString('yyyyMMdd-HHmmss')
                $renamed = Join-Path $outDir $newName
                Copy-Item -Path $latest.FullName -Destination $renamed -Force
                $branchInventoryDir = Join-Path $sharedRoot 'data\inventory-results'
                Ensure-Directory $branchInventoryDir
                Copy-Item -Path $renamed -Destination (Join-Path $branchInventoryDir $newName) -Force
                $state.last_successful_inventory_at = (Get-Date).ToString('s')
                $state.last_inventory_status = 'success'
                $state.last_upload_status = 'copied_to_branch_share'
                $state.last_error = ''
                Write-RunnerLog "Inventory copied to branch share: $newName"
            }

            if ($pendingCommand -and $commandType -eq 'scan_now') {
                Write-CommandAck -SharedRoot $sharedRoot -State $state -PendingCommand $pendingCommand -Status 'completed' -Message 'Manual scan executed on runner poll cycle.'
            }
        } else {
            if ($pendingCommand) {
                Write-CommandAck -SharedRoot $sharedRoot -State $state -PendingCommand $pendingCommand -Status 'failed' -Message "Unsupported runner command: $commandType"
            }
            $state.last_error = ''
        }
    }
}
catch {
    $state.last_inventory_status = 'failed'
    $state.last_error = $_.Exception.Message
    Write-RunnerLog ("Runner cycle failed: " + $_.Exception.Message)
    if ($pendingCommand) {
        Write-CommandAck -SharedRoot $sharedRoot -State $state -PendingCommand $pendingCommand -Status 'failed' -Message $_.Exception.Message
    }
}

Write-JsonFile -Path $statePath -Value $state
try {
    Sync-StateToBranchShare -SharedRoot $sharedRoot -RunnerId $runnerId -StatePath $statePath -State $state
    Write-JsonFile -Path $statePath -Value $state
}
catch {
    $shareError = $_.Exception.Message
    Set-ObjectProperty -Object $state -Name 'last_share_sync_error' -Value $shareError
    $state.last_upload_status = 'branch_share_unreachable'
    if (-not $state.last_error) {
        $state.last_error = $shareError
    } else {
        $state.last_error = $state.last_error + ' | Share sync: ' + $shareError
    }
    Write-RunnerLog ("Branch share sync failed: " + $shareError)
    Write-JsonFile -Path $statePath -Value $state
    exit 1
}

exit 0
}
finally {
    $runMutex.ReleaseMutex()
    $runMutex.Dispose()
}
