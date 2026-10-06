# Inventory extra security/health fields

Added to `scanner_core_v4.ps1` as CSV columns appended at the end. Stored only in `hardware_snapshots.snapshot_json` (no schema change). Old CSVs without these columns ingest unchanged. Shown on the device page under "Security & health"; blank shows "not reported".

| CSV column | snapshot key | Meaning | Blank when |
|---|---|---|---|
| Battery_Present | battery_present | `true`/`false` from Win32_Battery | CIM query failed |
| Battery_Health_Percent | battery_health_percent | full-charge / design capacity x 100 | no battery, or root\wmi classes unavailable |
| Hotfix_Count | hotfix_count | number of Win32_QuickFix entries | query failed |
| Last_Hotfix_Date | last_hotfix_date | newest InstalledOn, `yyyy-MM-dd` | no dated hotfix |
| BitLocker_System_Drive | bitlocker_system_drive | `on` / `off` / `unknown` for `%SystemDrive%` | not admin, feature missing |
| TPM_Enabled / TPM_Activated | tpm_enabled / tpm_activated | `true`/`false` | no TPM or not admin |
| TPM_Spec_Version | tpm_spec_version | e.g. `2.0, 0, 1.38` | no TPM or not admin |
| Installed_Software_Count | installed_software_count | distinct DisplayName in HKLM Uninstall (64+32 bit), excluding system components | registry unreadable |

Missing data is always an empty string, never `0`.

## Why the software list is excluded

Only a count is collected. A full list is large (hundreds of entries per PC on every scan), churns constantly (noisy change detection), and can expose personal or licensed-software details. If needed later, it should be a separate on-demand command with its own storage, not part of every scan.
