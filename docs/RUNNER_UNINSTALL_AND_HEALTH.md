# Runner Uninstall and Health Check

Both scripts live in `runner/scripts/` and ship in site kits (the kit builder copies the whole `runner/` directory).
Windows PowerShell 5.1 compatible.

## check_runner_health.ps1

Read-only, no admin needed.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\check_runner_health.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\check_runner_health.ps1 -Json
```

Checks: scheduled task state/last result/next run, `state\runner-state.json`
(`last_inventory_status`, `last_upload_status`, `last_seen_at`), pending outbox
count and oldest age, free disk, config `transport_mode` and server host,
clock skew (HTTP `Date` header of `<serverBaseUrl>/health`, 10 s timeout;
unreachable is WARN), installed version vs `runner-manifest.json`.

Exit codes: `0` OK, `1` WARN, `2` FAIL. The site token is never printed (shown as `[REDACTED]`).

## uninstall_runner.ps1

Requires an elevated PowerShell.

```powershell
.\uninstall_runner.ps1 -WhatIf            # show what would happen, change nothing
.\uninstall_runner.ps1                    # asks: Type YES to uninstall
.\uninstall_runner.ps1 -Force -KeepData   # no prompt, keep state\, logs\, data\, config backups
```

Parameters: `-InstallRoot` (default `C:\ProgramData\InternalInventoryRunner`),
`-TaskName` (default `InternalInventoryRunner`; the `-Startup` task is removed too),
`-KeepData`, `-WhatIf`, `-Force`.

Exit codes: `0` ok, `1` failure or declined. A log is written to `%TEMP%\runner-uninstall-*.log`.

## Safety notes

- The install root folder name must be `InternalInventoryRunner`; drive roots and empty paths are refused.
  Paths outside ProgramData also need `-Force`.
- Only a PowerShell process running this install's `scripts\runner_main.ps1` is stopped.
- Uninstall does not delete the portal record. The device will appear silent in the portal until removed there.
- With `-KeepData` the live config (contains the site token) is removed; only backups stay.
