<?php

namespace App\Services\Deployment;

use App\Services\Security\SiteTokenStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class SiteKitBuilder
{
    public function __construct(
        private readonly SiteKitProfileValidator $profileValidator,
        private readonly SiteTokenStore $siteTokens,
        private readonly UpdateSigner $signer,
    )
    {
    }

    public function build(array $options): array
    {
        $profile = $this->loadProfile((string) ($options['profile'] ?? ''));
        $transportMode = $this->value($options, $profile, 'transport_mode', 'collector_share');
        $transportMode = trim((string) $transportMode) === '' ? 'collector_share' : trim((string) $transportMode);
        $collectorRequired = $this->boolValue($this->value($options, $profile, 'collector_required', false));
        $collectorEnabled = $transportMode === 'collector_share' || $collectorRequired;
        $siteId = $this->value($options, $profile, 'site_id', 'SITE-HQ');
        $siteName = $this->value($options, $profile, 'site_name', 'Bhayangkara');
        $collectorName = $this->value($options, $profile, 'collector_name', Str::lower($siteId) . '-collector');
        $shareRoot = $this->value($options, $profile, 'share_root', $collectorEnabled ? "\\\\srv-server02\\Shares\\PUBLIC\\Inventory\\sites\\{$siteId}" : '');
        $serverBaseUrl = $this->value($options, $profile, 'server_base_url', config('app.url'));
        $siteToken = $this->value($options, $profile, 'site_token', $collectorEnabled ? 'change-this-token' : '');
        $directRunnerToken = $this->value($options, $profile, 'direct_runner_token', $this->registeredDirectRunnerToken((string) $siteId));
        $runnerVersion = $this->value($options, $profile, 'runner_version', '1.0.0');
        $scanIntervalMinutes = (int) $this->value($options, $profile, 'scan_interval_minutes', 240);
        $runnerPollIntervalMinutes = (int) $this->value($options, $profile, 'runner_poll_interval_minutes', 5);
        $collectorPollIntervalMinutes = (int) $this->value($options, $profile, 'collector_poll_interval_minutes', 5);
        $taskRandomDelayMinutes = (int) $this->value($options, $profile, 'task_random_delay_minutes', 15);
        $defaultLocation = $this->value($options, $profile, 'default_location', $siteName);
        $defaultRoom = $this->value($options, $profile, 'default_room', 'General');
        $taskName = $this->value($options, $profile, 'task_name', 'InternalInventoryRunner');
        $installRoot = $this->value($options, $profile, 'install_root', 'C:\\ProgramData\\InternalInventoryRunner');
        $profileWarnings = $this->validateEffectiveProfile([
            'transport_mode' => $transportMode,
            'collector_required' => $collectorRequired,
            'site_id' => $siteId,
            'site_name' => $siteName,
            'collector_name' => $collectorName,
            'share_root' => $shareRoot,
            'server_base_url' => $serverBaseUrl,
            'site_token' => $siteToken,
            'direct_runner_token' => $directRunnerToken,
            'scan_interval_minutes' => $scanIntervalMinutes,
            'runner_poll_interval_minutes' => $runnerPollIntervalMinutes,
            'collector_poll_interval_minutes' => $collectorPollIntervalMinutes,
            'task_random_delay_minutes' => $taskRandomDelayMinutes,
        ], (bool) ($options['strict'] ?? false));

        $sourceRoot = realpath(base_path('..'));
        $downloadsRoot = storage_path('app/' . trim((string) config('inventory.downloads_path'), '/'));
        $buildRoot = $downloadsRoot . DIRECTORY_SEPARATOR . "site-kit-{$siteId}";
        $zipPath = $downloadsRoot . DIRECTORY_SEPARATOR . "site-kit-{$siteId}.zip";

        File::deleteDirectory($buildRoot);
        File::ensureDirectoryExists($buildRoot);
        File::ensureDirectoryExists($downloadsRoot);

        File::copyDirectory($sourceRoot . DIRECTORY_SEPARATOR . 'runner', $buildRoot . DIRECTORY_SEPARATOR . 'runner');
        if ($collectorEnabled) {
            File::copyDirectory($sourceRoot . DIRECTORY_SEPARATOR . 'collector', $buildRoot . DIRECTORY_SEPARATOR . 'collector');
        }
        File::deleteDirectory($buildRoot . DIRECTORY_SEPARATOR . 'runner/data');
        if ($transportMode !== 'direct_https') {
            File::delete($buildRoot . DIRECTORY_SEPARATOR . 'runner/scripts/install_direct_https_runner.ps1');
        }
        $this->deleteGeneratedNoise($buildRoot);

        $signed = $this->signer->hasKey();
        $signingWarning = null;
        if ($signed) {
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'runner/config/update-public-key.xml', $this->signer->publicKeyXml());
        } else {
            $signingWarning = 'WARNING: no update signing key found (run inventory:update-keygen on the build host). This kit is UNSIGNED: runners cannot verify self-updates.';
        }

        $runnerConfig = [
            'runnerId' => '<SET-PER-DEVICE>',
            'runnerGuid' => '<GENERATED-ON-INSTALL>',
            'siteId' => $siteId,
            'siteName' => $siteName,
            'collectorName' => $collectorName,
            'sharedRoot' => $shareRoot,
            'location' => $defaultLocation,
            'room' => $defaultRoom,
            'runnerVersion' => $runnerVersion,
            'scheduledTaskName' => $taskName,
            'installRoot' => $installRoot,
            'scanIntervalMinutes' => $scanIntervalMinutes,
            'runnerPollIntervalMinutes' => $runnerPollIntervalMinutes,
            'runAtStartup' => true,
            'taskRandomDelayMinutes' => $taskRandomDelayMinutes,
            'runAsCurrentUser' => true,
        ];
        if ($signed) {
            $runnerConfig['requireSignedUpdates'] = true;
        }

        if ($transportMode === 'direct_https') {
            $runnerConfig['transport_mode'] = 'direct_https';
            $runnerConfig['serverBaseUrl'] = $serverBaseUrl;
            $runnerConfig['siteToken'] = $directRunnerToken;
            $runnerConfig['sharedRoot'] = '';
        }

        $collectorConfig = [
            'site_id' => $siteId,
            'site_name' => $siteName,
            'collector_name' => $collectorName,
            'collector_version' => $runnerVersion,
            'share_root' => $shareRoot,
            'server_base_url' => $serverBaseUrl,
            'site_token' => $siteToken,
            'poll_interval_minutes' => $collectorPollIntervalMinutes,
        ];

        $manifest = [
            'runner_version' => $runnerVersion,
            'package_folder' => 'current',
            'built_for_site' => $siteId,
            'recommended_scan_interval_minutes' => $scanIntervalMinutes,
            'recommended_runner_poll_interval_minutes' => $runnerPollIntervalMinutes,
        ];

        $this->writeJson($buildRoot . DIRECTORY_SEPARATOR . 'runner/config/runner-config.template.json', $runnerConfig);

        $runnerDir = $buildRoot . DIRECTORY_SEPARATOR . 'runner';
        $manifest['files'] = $this->packageFiles($runnerDir);
        $manifest['signed'] = $signed;
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $signature = $signed ? $this->signer->sign($manifestJson) : null;
        $this->writeManifest($runnerDir . DIRECTORY_SEPARATOR . 'manifest', $manifestJson, $signature);

        if ($collectorEnabled) {
            $this->writeJson($buildRoot . DIRECTORY_SEPARATOR . 'collector/collector_config.json', $collectorConfig);

            $currentRunnerDir = $buildRoot . DIRECTORY_SEPARATOR . 'branch-share/packages/runner/current';
            File::ensureDirectoryExists(dirname($currentRunnerDir));
            File::copyDirectory($buildRoot . DIRECTORY_SEPARATOR . 'runner', $currentRunnerDir);
            $this->writeManifest($buildRoot . DIRECTORY_SEPARATOR . 'branch-share/packages/runner', $manifestJson, $signature);
        }

        if ($transportMode !== 'direct_https') {
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_RUNNER_COMMAND.txt', $this->runnerInstallCommand(
                $shareRoot,
                $siteId,
                $siteName,
                $collectorName,
                $defaultLocation,
                $defaultRoom,
                $runnerVersion,
                $taskName,
                $scanIntervalMinutes,
                $runnerPollIntervalMinutes,
                $taskRandomDelayMinutes,
            ) . PHP_EOL);
        }

        if ($collectorEnabled) {
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_COLLECTOR_COMMAND.txt', $this->collectorInstallCommand($collectorPollIntervalMinutes) . PHP_EOL);
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_SITE_KIT.cmd', $this->siteKitCmd($siteId) . PHP_EOL);
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'START_HERE_INSTALL_COLLECTOR_PC.cmd', $this->siteKitModeCmd('CollectorAndRunner', $siteId) . PHP_EOL);
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_COLLECTOR_ONLY.cmd', $this->siteKitModeCmd('CollectorOnly', $siteId) . PHP_EOL);
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_COLLECTOR_SITE.cmd', $this->collectorSiteCmd($siteId) . PHP_EOL);
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'README_COLLECTOR_SITE.txt', $this->collectorSiteReadme($runnerVersion) . PHP_EOL);
        }
        File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_THIS_PC_RUNNER_ONLY.cmd', (
            $transportMode === 'direct_https'
                ? $this->directHttpsRunnerCmd($siteId)
                : $this->siteKitModeCmd('RunnerOnly', $siteId)
        ) . PHP_EOL);
        if ($transportMode === 'direct_https') {
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd', $this->directHttpsRunnerAliasCmd($siteId) . PHP_EOL);
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'README_DIRECT_HTTPS_RUNNER.txt', $this->directHttpsRunnerReadme($runnerVersion) . PHP_EOL);
        }
        File::put($buildRoot . DIRECTORY_SEPARATOR . 'FORCE_UPDATE_THIS_PC_RUNNER.cmd', $this->forceUpdateCmd('runner') . PHP_EOL);
        if ($collectorEnabled) {
            File::put($buildRoot . DIRECTORY_SEPARATOR . 'branch-share/packages/runner/FORCE_UPDATE_THIS_PC_RUNNER.cmd', $this->forceUpdateCmd('current') . PHP_EOL);
        }
        $remoteBootstrapScript = $sourceRoot . DIRECTORY_SEPARATOR . 'deployment/scripts/remote_bootstrap_site_runners.ps1';
        if (File::exists($remoteBootstrapScript)) {
            File::copy($remoteBootstrapScript, $buildRoot . DIRECTORY_SEPARATOR . 'REMOTE_BOOTSTRAP_SITE_RUNNERS.ps1');
            if ($collectorEnabled) {
                File::copy($remoteBootstrapScript, $buildRoot . DIRECTORY_SEPARATOR . 'branch-share/packages/runner/REMOTE_BOOTSTRAP_SITE_RUNNERS.ps1');
            }
        }
        File::put($buildRoot . DIRECTORY_SEPARATOR . 'INSTALL_SITE_KIT.ps1', $this->siteKitInstaller(
            $transportMode,
            $shareRoot,
            $siteId,
            $siteName,
            $collectorName,
            $serverBaseUrl,
            $directRunnerToken,
            $defaultLocation,
            $defaultRoom,
            $runnerVersion,
            $taskName,
            $installRoot,
            $scanIntervalMinutes,
            $runnerPollIntervalMinutes,
            $taskRandomDelayMinutes,
            $collectorPollIntervalMinutes,
        ));
        File::put($buildRoot . DIRECTORY_SEPARATOR . 'README_SITE_KIT.md', $this->readme($siteId, $siteName, $collectorName, $shareRoot, $serverBaseUrl, $scanIntervalMinutes, $collectorPollIntervalMinutes, $transportMode));
        File::put($buildRoot . DIRECTORY_SEPARATOR . 'READ_ME_FIRST_FOR_BRANCH.txt', $this->branchInstructions($siteId, $serverBaseUrl, $shareRoot, $transportMode));

        $this->zipDirectory($buildRoot, $zipPath);

        return [
            'site_id' => $siteId,
            'transport_mode' => $transportMode,
            'build_root' => $buildRoot,
            'zip_path' => $zipPath,
            'share_root' => $shareRoot,
            'collector_name' => $collectorName,
            'scan_interval_minutes' => $scanIntervalMinutes,
            'runner_poll_interval_minutes' => $runnerPollIntervalMinutes,
            'collector_poll_interval_minutes' => $collectorPollIntervalMinutes,
            'profile_warnings' => $profileWarnings,
            'signed' => $signed,
            'signing_warning' => $signingWarning,
        ];
    }

    /** Every file in the package except the manifest and its signature: [{path, sha256, size}]. */
    private function packageFiles(string $runnerDir): array
    {
        $files = [];
        foreach (File::allFiles($runnerDir, true) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($runnerDir) + 1));
            if ($relative === 'manifest/runner-manifest.json' || $relative === 'manifest/runner-manifest.sig') {
                continue;
            }
            $files[] = ['path' => $relative, 'sha256' => hash_file('sha256', $file->getPathname()), 'size' => $file->getSize()];
        }
        usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    private function writeManifest(string $dir, string $manifestJson, ?string $signature): void
    {
        File::ensureDirectoryExists($dir);
        File::put($dir . DIRECTORY_SEPARATOR . 'runner-manifest.json', $manifestJson);
        if ($signature !== null) {
            File::put($dir . DIRECTORY_SEPARATOR . 'runner-manifest.sig', $signature);
        }
    }

    private function loadProfile(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $profile = $this->profileValidator->load($path);
        $result = $this->profileValidator->validate($profile);
        if ($result['errors'] !== []) {
            throw new \InvalidArgumentException('Invalid profile: ' . implode('; ', $result['errors']));
        }

        return $profile;
    }

    private function value(array $options, array $profile, string $key, mixed $default): mixed
    {
        $optionKey = str_replace('_', '-', $key);

        return $options[$key] ?? $options[$optionKey] ?? $profile[$key] ?? $default;
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function registeredDirectRunnerToken(string $siteId): string
    {
        if ($siteId === '') {
            return '';
        }

        return trim((string) ($this->siteTokens->all(SiteTokenStore::TYPE_DIRECT_RUNNER)[$siteId] ?? ''));
    }

    private function validateEffectiveProfile(array $profile, bool $strict): array
    {
        $result = $this->profileValidator->validate($profile);

        if ($result['errors'] !== []) {
            throw new \InvalidArgumentException('Invalid profile: ' . implode('; ', $result['errors']));
        }

        if ($strict && $result['warnings'] !== []) {
            throw new \InvalidArgumentException('Strict profile warnings: ' . implode('; ', $result['warnings']));
        }

        return $result['warnings'];
    }

    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function deleteGeneratedNoise(string $root): void
    {
        foreach (File::directories($root) as $directory) {
            if (basename($directory) === '__pycache__') {
                File::deleteDirectory($directory);
                continue;
            }

            $this->deleteGeneratedNoise($directory);
        }

        foreach (File::files($root) as $file) {
            if (str_ends_with($file->getFilename(), '.pyc')) {
                File::delete($file->getPathname());
            }
        }
    }

    private function runnerInstallCommand(string $shareRoot, string $siteId, string $siteName, string $collectorName, string $defaultLocation, string $defaultRoom, string $runnerVersion, string $taskName, int $scanIntervalMinutes, int $runnerPollIntervalMinutes, int $taskRandomDelayMinutes): string
    {
        return 'powershell -NoProfile -ExecutionPolicy Bypass -File .\runner\scripts\install_runner.ps1 '
            . '-SourceRoot .\runner '
            . '-SharedRoot "' . $shareRoot . '" '
            . '-RunnerId "<SET-PER-DEVICE>" '
            . '-SiteId "' . $siteId . '" '
            . '-SiteName "' . $siteName . '" '
            . '-CollectorName "' . $collectorName . '" '
            . '-Location "' . $defaultLocation . '" '
            . '-Room "' . $defaultRoom . '" '
            . '-RunnerVersion "' . $runnerVersion . '" '
            . '-TaskName "' . $taskName . '" '
            . '-ScanIntervalMinutes ' . $scanIntervalMinutes . ' '
            . '-PollIntervalMinutes ' . $runnerPollIntervalMinutes . ' '
            . '-TaskRandomDelayMinutes ' . $taskRandomDelayMinutes . ' '
            . '-RunAsCurrentUser';
    }

    private function collectorInstallCommand(int $collectorPollIntervalMinutes): string
    {
        return "python collector\\relay.py init-share --config collector\\collector_config.json\r\n"
            . 'powershell -NoProfile -ExecutionPolicy Bypass -File .\collector\install_collector.ps1 '
            . '-CollectorRoot .\collector '
            . '-ConfigPath .\collector\collector_config.json '
            . '-PollIntervalMinutes ' . $collectorPollIntervalMinutes . ' '
            . '-RunAsCurrentUser';
    }

    private function siteKitCmd(string $siteId): string
    {
        return $this->stagedSiteKitCmd($siteId, 'CollectorAndRunner')
            . "echo.\r\n"
            . "echo Installer finished. If there were errors, send the install log to IT.\r\n"
            . "pause\r\n";
    }

    private function siteKitModeCmd(string $mode, string $siteId): string
    {
        return $this->stagedSiteKitCmd($siteId, $mode)
            . "echo.\r\n"
            . "echo Installer finished. If there were errors, send install-site-kit.log to IT.\r\n"
            . "pause\r\n";
    }

    private function directHttpsRunnerCmd(string $siteId): string
    {
        // Stage is per-user (not %PUBLIC%) because it holds the site token; it is deleted before exit.
        return "@echo off\r\n"
            . "setlocal\r\n"
            . "set \"SOURCE=%~dp0\"\r\n"
            . "set \"STAGE=%LOCALAPPDATA%\\Temp\\InternalInventorySiteKit\\site-kit-{$siteId}\"\r\n"
            . "set \"RESULT=%STAGE%\\result.txt\"\r\n"
            . "set \"STATUS=FAIL\"\r\n"
            . "set \"LOGPATH=not written (installer did not start or was cancelled)\"\r\n"
            . "echo Staging Direct HTTPS runner installer locally...\r\n"
            . "pushd \"%SOURCE%\"\r\n"
            . "if errorlevel 1 (\r\n"
            . "  echo Could not access installer source: %SOURCE%\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n"
            . "if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n"
            . "mkdir \"%STAGE%\" >nul 2>nul\r\n"
            . "robocopy \".\" \"%STAGE%\" /MIR /XD data storage vendor node_modules .git /XF install-site-kit.log result.txt /R:2 /W:1 >nul\r\n"
            . "set \"RC=%ERRORLEVEL%\"\r\n"
            . "popd\r\n"
            . "if %RC% GEQ 8 (\r\n"
            . "  echo Could not copy installer locally. Robocopy exit code: %RC%\r\n"
            . "  cd /d \"%TEMP%\"\r\n"
            . "  if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n"
            . "  pause\r\n"
            . "  exit /b %RC%\r\n"
            . ")\r\n"
            . "cd /d \"%STAGE%\"\r\n"
            . "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%STAGE%\\runner\\scripts\\install_direct_https_runner.ps1','-ConfigPath','%STAGE%\\runner\\config\\runner-config.template.json','-UseComputerNameAsRunnerId','-ResultPath','%RESULT%','-PauseOnFail')\"\r\n"
            . "if exist \"%RESULT%\" (\r\n"
            . "  for /f \"usebackq tokens=1,* delims==\" %%A in (\"%RESULT%\") do (\r\n"
            . "    if /i \"%%A\"==\"status\" set \"STATUS=%%B\"\r\n"
            . "    if /i \"%%A\"==\"log\" set \"LOGPATH=%%B\"\r\n"
            . "  )\r\n"
            . ")\r\n"
            . "cd /d \"%TEMP%\"\r\n"
            . "if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n"
            . "echo.\r\n"
            . "echo Installer log: %LOGPATH%\r\n"
            . "if /i \"%STATUS%\"==\"PASS\" (\r\n"
            . "  echo RESULT: PASS - Direct HTTPS runner installed and the first scan succeeded.\r\n"
            . "  pause\r\n"
            . "  exit /b 0\r\n"
            . ")\r\n"
            . "echo RESULT: FAIL - send the installer log above to IT.\r\n"
            . "pause\r\n"
            . "exit /b 1\r\n";
    }

    /**
     * IExpress cannot keep sub-folders, so the SFX carries the kit zip plus this wrapper.
     * The wrapper holds no token; the zip does.
     */
    public function sfxWrapperCmd(string $siteId, string $zipName): string
    {
        return "@echo off\r\n"
            . "setlocal\r\n"
            . "set \"WORK=%TEMP%\\InternalInventorySiteKit-sfx-{$siteId}\"\r\n"
            . "if exist \"%WORK%\" rmdir /s /q \"%WORK%\"\r\n"
            . "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Expand-Archive -LiteralPath '%~dp0{$zipName}' -DestinationPath '%WORK%' -Force\"\r\n"
            . "if errorlevel 1 (\r\n"
            . "  echo Could not extract the installer.\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n"
            . "call \"%WORK%\\site-kit-{$siteId}\\INSTALL_THIS_PC_RUNNER_ONLY.cmd\"\r\n"
            . "set \"RC=%ERRORLEVEL%\"\r\n"
            . "cd /d \"%TEMP%\"\r\n"
            . "rmdir /s /q \"%WORK%\"\r\n"
            . "exit /b %RC%\r\n";
    }

    public function iexpressSed(string $siteId, string $targetExe, string $sourceDir, string $wrapperName, string $zipName): string
    {
        $sourceDir = rtrim($sourceDir, '\\/') . '\\';

        return "[Version]\r\nClass=IEXPRESS\r\nSEDVersion=3\r\n"
            . "[Options]\r\nPackagePurpose=InstallApp\r\nShowInstallProgramWindow=0\r\nHideExtractAnimation=1\r\n"
            . "UseLongFileName=1\r\nInsertReboot=0\r\nRebootMode=N\r\nCheckAdminRights=0\r\n"
            . "FinishMessage=\r\nInstallPrompt=\r\nDisplayLicense=\r\n"
            . "TargetName={$targetExe}\r\nFriendlyName=Inventory runner setup {$siteId}\r\n"
            . "AppLaunched=cmd /c .\\{$wrapperName}\r\nPostInstallCmd=<None>\r\nAdminQuietInstCmd=\r\nUserQuietInstCmd=\r\n"
            . "SourceFiles=SourceFiles\r\n"
            . "[Strings]\r\nFILE0=\"{$wrapperName}\"\r\nFILE1=\"{$zipName}\"\r\n"
            . "[SourceFiles]\r\nSourceFiles0={$sourceDir}\r\n"
            . "[SourceFiles0]\r\n%FILE0%=\r\n%FILE1%=\r\n";
    }

    /** Optional single-file SFX. Returns status + message; never throws for a missing iexpress.exe. */
    public function buildExe(array $result): array
    {
        $iexpress = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\iexpress.exe';
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($iexpress)) {
            return ['status' => 'skipped', 'message' => 'iexpress.exe not found (Windows only); folder kit and zip were built without an exe.'];
        }

        $siteId = $result['site_id'];
        $dir = dirname($result['zip_path']);
        $zipName = basename($result['zip_path']);
        $wrapperName = 'sfx_setup.cmd';
        $exePath = $dir . DIRECTORY_SEPARATOR . "{$siteId}-runner-setup.exe";
        $sedDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inventory-sfx-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($sedDir);

        try {
            File::copy($result['zip_path'], $sedDir . DIRECTORY_SEPARATOR . $zipName);
            File::put($sedDir . DIRECTORY_SEPARATOR . $wrapperName, $this->sfxWrapperCmd($siteId, $zipName));
            $sedPath = $sedDir . DIRECTORY_SEPARATOR . 'kit.sed';
            File::put($sedPath, $this->iexpressSed($siteId, $exePath, $sedDir, $wrapperName, $zipName));
            if (is_file($exePath)) {
                File::delete($exePath);
            }
            // Array command, no shell: exec('"a" /N /Q "b"') goes through cmd /c, which strips the outer quotes and breaks.
            $process = new Process([$iexpress, '/N', '/Q', $sedPath], null, null, null, 300);
            $process->run();
            $code = $process->getExitCode() ?? 1;
        } finally {
            File::deleteDirectory($sedDir);
        }

        return is_file($exePath)
            ? ['status' => 'built', 'path' => $exePath, 'message' => 'Contains the site token. Treat as a secret.']
            : ['status' => 'failed', 'message' => "iexpress exited with code {$code}; folder kit and zip are unaffected."];
    }

    private function directHttpsRunnerAliasCmd(string $siteId): string
    {
        return "@echo off\r\n"
            . "echo This installs this PC as a Direct HTTPS runner for a small/no-IT site.\r\n"
            . "echo.\r\n"
            . "call \"%~dp0INSTALL_THIS_PC_RUNNER_ONLY.cmd\"\r\n"
            . "exit /b %ERRORLEVEL%\r\n";
    }

    private function collectorSiteCmd(string $siteId): string
    {
        return "@echo off\r\n"
            . "setlocal\r\n"
            . "set \"SOURCE=%~dp0\"\r\n"
            . "set \"STAGE=%LOCALAPPDATA%\\Temp\\InternalInventorySiteKit\\site-kit-{$siteId}\"\r\n"
            . "echo This installs the collector site for HQ/branch/multi-PC collector-share mode.\r\n"
            . "echo Staging collector-site installer locally...\r\n"
            . "pushd \"%SOURCE%\"\r\n"
            . "if errorlevel 1 (\r\n"
            . "  echo Could not access installer source: %SOURCE%\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n"
            . "if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n"
            . "mkdir \"%STAGE%\" >nul 2>nul\r\n"
            . "robocopy \".\" \"%STAGE%\" /MIR /XD data storage vendor node_modules .git /XF install-site-kit.log /R:2 /W:1 >nul\r\n"
            . "set \"RC=%ERRORLEVEL%\"\r\n"
            . "popd\r\n"
            . "if %RC% GEQ 8 (\r\n"
            . "  echo Could not copy installer locally. Robocopy exit code: %RC%\r\n"
            . "  pause\r\n"
            . "  exit /b %RC%\r\n"
            . ")\r\n"
            . "cd /d \"%STAGE%\"\r\n"
            . "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%STAGE%\\collector\\install_collector_site.ps1','-CollectorRoot','%STAGE%\\collector','-ConfigPath','%STAGE%\\collector\\collector_config.json')\"\r\n"
            . $this->stageCleanup(false)
            . "echo.\r\n"
            . "echo Collector-site installer finished. If there were errors, send the installer log path shown above to IT.\r\n"
            . "pause\r\n";
    }

    private function stagedSiteKitCmd(string $siteId, string $mode): string
    {
        return "@echo off\r\n"
            . "setlocal\r\n"
            . "set \"SOURCE=%~dp0\"\r\n"
            . "set \"STAGE=%LOCALAPPDATA%\\Temp\\InternalInventorySiteKit\\site-kit-{$siteId}\"\r\n"
            . "echo Staging installer locally...\r\n"
            . "pushd \"%SOURCE%\"\r\n"
            . "if errorlevel 1 (\r\n"
            . "  echo Could not access installer source: %SOURCE%\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n"
            . "if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n"
            . "mkdir \"%STAGE%\" >nul 2>nul\r\n"
            . "robocopy \".\" \"%STAGE%\" /MIR /XD data storage vendor node_modules .git /XF install-site-kit.log /R:2 /W:1 >nul\r\n"
            . "set \"RC=%ERRORLEVEL%\"\r\n"
            . "popd\r\n"
            . "if %RC% GEQ 8 (\r\n"
            . "  echo Could not copy installer locally. Robocopy exit code: %RC%\r\n"
            . "  pause\r\n"
            . "  exit /b %RC%\r\n"
            . ")\r\n"
            . "cd /d \"%STAGE%\"\r\n"
            . "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%STAGE%\\INSTALL_SITE_KIT.ps1','-Mode','{$mode}','-UseComputerNameAsRunnerId')\"\r\n"
            . $this->stageCleanup(true);
    }

    /** Remove the per-user stage (it holds the site token); optionally hand the install log back to the kit folder. */
    private function stageCleanup(bool $keepLog): string
    {
        return ($keepLog ? "if exist \"%STAGE%\\install-site-kit.log\" copy /y \"%STAGE%\\install-site-kit.log\" \"%SOURCE%install-site-kit.log\" >nul 2>nul\r\n" : '')
            . "cd /d \"%TEMP%\"\r\n"
            . "if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n";
    }

    private function forceUpdateCmd(string $packageRelativePath): string
    {
        return "@echo off\r\n"
            . "setlocal\r\n"
            . "set \"PACKAGE=%~dp0{$packageRelativePath}\"\r\n"
            . "set \"STAGE=%LOCALAPPDATA%\\Temp\\InternalInventoryRunnerUpdate\\current\"\r\n"
            . "pushd \"%PACKAGE%\"\r\n"
            . "if errorlevel 1 (\r\n"
            . "  echo Could not access updater package: %PACKAGE%\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n"
            . "if not exist \".\\scripts\\bootstrap_update_runner.ps1\" (\r\n"
            . "  echo Updater package not found: %PACKAGE%\r\n"
            . "  popd\r\n"
            . "  pause\r\n"
            . "  exit /b 1\r\n"
            . ")\r\n"
            . "echo Staging updater locally...\r\n"
            . "if exist \"%STAGE%\" rmdir /s /q \"%STAGE%\"\r\n"
            . "mkdir \"%STAGE%\" >nul 2>nul\r\n"
            . "robocopy \".\" \"%STAGE%\" /MIR /XD data logs state /XF runner-config.json inventory-destinations.json /R:2 /W:1 >nul\r\n"
            . "set \"RC=%ERRORLEVEL%\"\r\n"
            . "popd\r\n"
            . "if %RC% GEQ 8 (\r\n"
            . "  echo Could not stage updater locally. Robocopy exit code: %RC%\r\n"
            . "  pause\r\n"
            . "  exit /b %RC%\r\n"
            . ")\r\n"
            . "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%STAGE%\\scripts\\bootstrap_update_runner.ps1','-PackageRoot','%STAGE%','-RunAfterUpdate','-NoElevate')\"\r\n"
            . $this->stageCleanup(false)
            . "echo.\r\n"
            . "echo Force update finished. If there were errors, send the bootstrap-update log from C:\\ProgramData\\InternalInventoryRunner\\logs to IT.\r\n"
            . "pause\r\n";
    }

    private function siteKitInstaller(string $transportMode, string $shareRoot, string $siteId, string $siteName, string $collectorName, string $serverBaseUrl, string $directRunnerToken, string $defaultLocation, string $defaultRoom, string $runnerVersion, string $taskName, string $installRoot, int $scanIntervalMinutes, int $runnerPollIntervalMinutes, int $taskRandomDelayMinutes, int $collectorPollIntervalMinutes): string
    {
        $installerShareRoot = $transportMode === 'direct_https'
            ? $installRoot . '\\direct-share-unused'
            : $shareRoot;
        $directConfigBlock = $transportMode === 'direct_https'
            ? <<<PS1

        \$runnerConfigPath = Join-Path "{$installRoot}" 'config\\runner-config.json'
        Require-Path \$runnerConfigPath 'Installed runner config'
        \$runnerConfig = Get-Content -Path \$runnerConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
        \$runnerConfig | Add-Member -NotePropertyName 'transport_mode' -NotePropertyValue 'direct_https' -Force
        \$runnerConfig | Add-Member -NotePropertyName 'serverBaseUrl' -NotePropertyValue "{$serverBaseUrl}" -Force
        \$runnerConfig | Add-Member -NotePropertyName 'siteToken' -NotePropertyValue "{$directRunnerToken}" -Force
        \$runnerConfig.sharedRoot = ''
        \$runnerConfig | ConvertTo-Json -Depth 8 | Set-Content -Path \$runnerConfigPath -Encoding UTF8
        Write-Step 'Applied direct HTTPS runner configuration.'
PS1
            : '';
        return <<<PS1
[CmdletBinding()]
param(
    [ValidateSet('CollectorAndRunner', 'CollectorOnly', 'RunnerOnly')]
    [string]\$Mode = 'CollectorAndRunner',
    [string]\$RunnerId = '',
    [switch]\$UseComputerNameAsRunnerId,
    [switch]\$Quiet
)

Set-StrictMode -Version Latest
\$ErrorActionPreference = 'Stop'

\$KitRoot = Split-Path -Parent \$MyInvocation.MyCommand.Path
\$LogPath = Join-Path \$KitRoot 'install-site-kit.log'

function Write-Step {
    param([string]\$Message)
    \$line = '[' + (Get-Date).ToString('yyyy-MM-dd HH:mm:ss') + '] ' + \$Message
    Write-Host \$line
    Add-Content -Path \$LogPath -Value \$line
}

function Test-Admin {
    \$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    \$principal = New-Object Security.Principal.WindowsPrincipal(\$identity)
    return \$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Require-Path {
    param([string]\$Path, [string]\$Name)
    if (-not (Test-Path \$Path)) {
        throw "\$Name not found: \$Path"
    }
}

function Invoke-LoggedCommand {
    param(
        [Parameter(Mandatory=\$true)][string]\$FilePath,
        [Parameter(Mandatory=\$true)][string[]]\$Arguments,
        [Parameter(Mandatory=\$true)][string]\$Description
    )

    Write-Step \$Description
    Write-Step ('Command: ' + \$FilePath + ' ' + (\$Arguments -join ' '))

    \$output = & \$FilePath @Arguments 2>&1
    foreach (\$line in \$output) {
        Write-Step ('  ' + [string]\$line)
    }

    if (\$LASTEXITCODE -ne 0) {
        throw "\$Description failed with exit code \$LASTEXITCODE"
    }
}

function Require-ScheduledTask {
    param([string]\$TaskName)

    \$task = Get-ScheduledTask -TaskName \$TaskName -ErrorAction SilentlyContinue
    if (-not \$task) {
        throw "Scheduled task was not created: \$TaskName"
    }

    Write-Step "Verified scheduled task: \$TaskName ($(\$task.State))"
}

function Require-RunnerStateSuccess {
    param([string]\$StatePath)

    Require-Path \$StatePath 'Runner state file'
    \$state = Get-Content -Path \$StatePath -Raw -Encoding UTF8 | ConvertFrom-Json

    if ([string]\$state.last_inventory_status -ne 'success') {
        \$errorText = [string]\$state.last_error
        if ([string]::IsNullOrWhiteSpace(\$errorText)) {
            \$errorText = 'No detailed runner error was recorded.'
        }

        throw "Initial runner scan failed: \$errorText"
    }

    Write-Step "Verified initial runner scan success: \$([string]\$state.runner_id)"
}

function Read-RunnerState {
    param([string]\$StatePath)

    Require-Path \$StatePath 'Runner state file'
    return Get-Content -Path \$StatePath -Raw -Encoding UTF8 | ConvertFrom-Json
}

function Invoke-CollectorRelayOnce {
    Require-Path (Join-Path \$KitRoot 'collector\\relay.py') 'Collector relay'
    Require-Path (Join-Path \$KitRoot 'collector\\collector_config.json') 'Collector config'

    Invoke-LoggedCommand `
        -FilePath 'python' `
        -Arguments @((Join-Path \$KitRoot 'collector\\relay.py'), 'run-once', '--config', (Join-Path \$KitRoot 'collector\\collector_config.json')) `
        -Description 'Running collector relay once for immediate portal update.'
}

function Resolve-RunnerId {
    if (\$script:RunnerId -ne '') {
        return \$script:RunnerId
    }

    if (\$UseComputerNameAsRunnerId -or \$Quiet) {
        return \$env:COMPUTERNAME
    }

    \$entered = Read-Host 'Runner ID for this PC. Press Enter to use computer name'
    if ([string]::IsNullOrWhiteSpace(\$entered)) {
        return \$env:COMPUTERNAME
    }

    return \$entered.Trim()
}

try {
    '' | Set-Content -Path \$LogPath -Encoding UTF8
    Write-Step 'Internal Windows Inventory site-kit installer started.'
    Write-Step "Mode: \$Mode"
    Write-Step "Kit root: \$KitRoot"

    if (-not (Test-Admin)) {
        throw 'Administrator approval is required. Run one of the .cmd launchers so it can stage locally first and then request UAC.'
    }

    if (\$Mode -eq 'CollectorAndRunner' -or \$Mode -eq 'CollectorOnly') {
        Write-Step 'Checking Python for collector relay.'
        \$python = Get-Command python -ErrorAction SilentlyContinue
        if (-not \$python) {
            throw 'Python was not found in PATH. Install Python 3 on the collector PC or run only the runner installer on branch PCs.'
        }

        Require-Path (Join-Path \$KitRoot 'collector\\relay.py') 'Collector relay'
        Require-Path (Join-Path \$KitRoot 'collector\\collector_config.json') 'Collector config'

        Invoke-LoggedCommand `
            -FilePath 'python' `
            -Arguments @((Join-Path \$KitRoot 'collector\\relay.py'), 'init-share', '--config', (Join-Path \$KitRoot 'collector\\collector_config.json')) `
            -Description 'Initializing branch share through collector relay.'

        Invoke-LoggedCommand `
            -FilePath 'powershell' `
            -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path \$KitRoot 'collector\\install_collector.ps1'), '-CollectorRoot', (Join-Path \$KitRoot 'collector'), '-ConfigPath', (Join-Path \$KitRoot 'collector\\collector_config.json'), '-PollIntervalMinutes', '{$collectorPollIntervalMinutes}', '-RunAsCurrentUser') `
            -Description 'Installing collector scheduled task.'

        Require-ScheduledTask 'InternalInventoryCollectorRelay'

        if (\$Mode -eq 'CollectorOnly') {
            Invoke-CollectorRelayOnce
        }
    }

    if (\$Mode -eq 'CollectorAndRunner' -or \$Mode -eq 'RunnerOnly') {
        \$resolvedRunnerId = Resolve-RunnerId
        Write-Step "Installing runner scheduled task for runner ID: \$resolvedRunnerId"
        Require-Path (Join-Path \$KitRoot 'runner\\scripts\\install_runner.ps1') 'Runner installer'

        Invoke-LoggedCommand `
            -FilePath 'powershell' `
            -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path \$KitRoot 'runner\\scripts\\install_runner.ps1'), '-SourceRoot', (Join-Path \$KitRoot 'runner'), '-SharedRoot', "{$installerShareRoot}", '-RunnerId', \$resolvedRunnerId, '-SiteId', "{$siteId}", '-SiteName', "{$siteName}", '-CollectorName', "{$collectorName}", '-Location', "{$defaultLocation}", '-Room', "{$defaultRoom}", '-RunnerVersion', "{$runnerVersion}", '-TaskName', "{$taskName}", '-ScanIntervalMinutes', '{$scanIntervalMinutes}', '-PollIntervalMinutes', '{$runnerPollIntervalMinutes}', '-TaskRandomDelayMinutes', '{$taskRandomDelayMinutes}', '-RunAsCurrentUser') `
            -Description 'Installing runner scheduled task.'
{$directConfigBlock}

        Require-ScheduledTask "{$taskName}"

        \$installedRunnerScript = Join-Path "{$installRoot}" 'scripts\\runner_main.ps1'
        Require-Path \$installedRunnerScript 'Installed runner script'

        Invoke-LoggedCommand `
            -FilePath 'powershell' `
            -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', \$installedRunnerScript) `
            -Description 'Running initial runner scan.'

        \$runnerStatePath = Join-Path "{$installRoot}" 'state\\runner-state.json'
        \$runnerState = Read-RunnerState \$runnerStatePath
        if ([string]\$runnerState.last_command_type -eq 'repair_update' -and [string]\$runnerState.last_inventory_status -ne 'success') {
            Write-Step 'Initial runner execution applied repair/update; running scan once more.'
            Invoke-LoggedCommand `
                -FilePath 'powershell' `
                -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', \$installedRunnerScript) `
                -Description 'Running post-update runner scan.'
        }

        Require-RunnerStateSuccess \$runnerStatePath

        if (\$Mode -eq 'CollectorAndRunner') {
            Invoke-CollectorRelayOnce
        }
    }

    Write-Step 'Installation completed successfully.'
    Write-Host ''
    Write-Host 'Installation completed successfully.'
    Write-Host "Log: \$LogPath"
    exit 0
} catch {
    Write-Step ('ERROR: ' + \$_.Exception.Message)
    Write-Host ''
    Write-Host 'Installation failed.'
    Write-Host \$_.Exception.Message
    Write-Host "Log: \$LogPath"
    exit 1
}
PS1;
    }

    private function readme(string $siteId, string $siteName, string $collectorName, string $shareRoot, string $serverBaseUrl, int $scanIntervalMinutes, int $collectorPollIntervalMinutes, string $transportMode): string
    {
        if ($transportMode === 'direct_https') {
            return "# Site kit for {$siteId}\n\n"
                . "- Site: {$siteName}\n"
                . "- Transport mode: direct_https\n"
                . "- Central server: `{$serverBaseUrl}`\n"
                . "- Runner scan interval: {$scanIntervalMinutes} minutes\n\n"
                . "## Simple install\n\n"
                . "On each runner PC, run `INSTALL_THIS_PC_RUNNER_ONLY.cmd` normally from the extracted kit. It stages locally, then asks for administrator approval.\n\n"
                . "The launcher ends with a clear PASS or FAIL and prints the installer log path. The log is `C:\\ProgramData\\InternalInventoryRunner\\logs\\installer-direct-https-<timestamp>.log` (or a file under `%TEMP%` if that folder does not exist). Send it to IT if the result is FAIL.\n";
        }

        return "# Site kit for {$siteId}\n\n"
            . "- Site: {$siteName}\n"
            . "- Collector: {$collectorName}\n"
            . "- Share root: `{$shareRoot}`\n"
            . "- Central server: `{$serverBaseUrl}`\n"
            . "- Runner scan interval: {$scanIntervalMinutes} minutes\n"
            . "- Collector poll interval: {$collectorPollIntervalMinutes} minutes\n\n"
            . "## Simple install\n\n"
            . "On the collector PC, run `START_HERE_INSTALL_COLLECTOR_PC.cmd` normally from the share. It stages locally, then asks for administrator approval.\n"
            . "On normal branch PCs, run `INSTALL_THIS_PC_RUNNER_ONLY.cmd` normally from the share. It stages locally, then asks for administrator approval.\n\n"
            . "`INSTALL_SITE_KIT.cmd` is kept as a compatibility shortcut and installs both collector and runner.\n\n"
            . "The installer writes `install-site-kit.log` in this folder.\n";
    }

    private function branchInstructions(string $siteId, string $serverBaseUrl, string $shareRoot, string $transportMode): string
    {
        if ($transportMode === 'direct_https') {
            return "Internal Windows Inventory direct HTTPS runner installer for {$siteId}\r\n"
                . "\r\n"
                . "1. Extract this ZIP file first. Do not run it from inside the ZIP viewer.\r\n"
                . "2. On runner PCs, run INSTALL_THIS_PC_RUNNER_ONLY.cmd normally. It copies itself locally, then asks for administrator approval.\r\n"
                . "3. The window ends with PASS or FAIL and prints the installer log path. If it FAILS, send that log to IT.\r\n"
                . "   Log folder: C:\\ProgramData\\InternalInventoryRunner\\logs\\installer-direct-https-*.log (or %TEMP% fallback)\r\n"
                . "\r\n"
                . "Runner PC requirements:\r\n"
                . "- Windows administrator permission\r\n"
                . "- Access to Laravel server: {$serverBaseUrl}\r\n";
        }

        return "Internal Windows Inventory installer for {$siteId}\r\n"
            . "\r\n"
            . "1. Extract this ZIP file first. Do not run it from inside the ZIP viewer.\r\n"
            . "2. On the collector PC only, run START_HERE_INSTALL_COLLECTOR_PC.cmd normally. It copies itself locally, then asks for administrator approval.\r\n"
            . "3. On normal branch PCs, run INSTALL_THIS_PC_RUNNER_ONLY.cmd normally. It copies itself locally, then asks for administrator approval.\r\n"
            . "4. If installation fails, send install-site-kit.log to IT.\r\n"
            . "\r\n"
            . "Collector PC requirements:\r\n"
            . "- Windows administrator permission\r\n"
            . "- Logged-in Windows account can read/write the inventory share\r\n"
            . "- Python 3 available in PATH\r\n"
            . "- Access to Laravel server: {$serverBaseUrl}\r\n"
            . "- Access to inventory share: {$shareRoot}\r\n"
            . "\r\n"
            . "Normal PC runner requirements:\r\n"
            . "- Windows administrator permission\r\n"
            . "- Logged-in Windows account can read/write the inventory share\r\n"
            . "- Access to inventory share: {$shareRoot}\r\n";
    }

    private function directHttpsRunnerReadme(string $runnerVersion): string
    {
        return "Direct HTTPS Runner Package\r\n"
            . "\r\n"
            . "Use this package for one PC, a small/no-IT site, or a remote PC without a branch-share or collector.\r\n"
            . "\r\n"
            . "Flow:\r\n"
            . "Runner on this PC -> Laravel HTTPS portal\r\n"
            . "\r\n"
            . "Requirements and boundaries:\r\n"
            . "- Requires an HTTPS endpoint and trusted certificate.\r\n"
            . "- Do not use a collector-share token.\r\n"
            . "- Do not use plain HTTP.\r\n"
            . "- Do not disable TLS validation.\r\n"
            . "- Do not use -SkipCertificateCheck.\r\n"
            . "- Direct repair_update remains blocked for Direct HTTPS MVP.\r\n"
            . "- Official packages/site kits are generated only on Supermicro.\r\n"
            . "- Do not generate official packages from IT-ADMIN.\r\n"
            . "\r\n"
            . "Run:\r\n"
            . "INSTALL_THIS_PC_RUNNER_ONLY.cmd\r\n"
            . "It ends with PASS or FAIL and prints the installer log path (C:\\ProgramData\\InternalInventoryRunner\\logs\\installer-direct-https-*.log).\r\n"
            . "\r\n"
            . "Verify in portal:\r\n"
            . "- /runners\r\n"
            . "- Direct HTTPS transport\r\n"
            . "- runner version {$runnerVersion}\r\n"
            . "- recent heartbeat\r\n"
            . "- recent direct poll\r\n"
            . "- upload / Last Inventory populated after scan\r\n"
            . "- repair/update blocked\r\n"
            . "\r\n"
            . "Optional Supermicro checks:\r\n"
            . "php artisan inventory:direct-pilot-status\r\n"
            . "php artisan inventory:direct-runner-triage {runnerId}\r\n"
            . "php artisan inventory:direct-site-kit-audit\r\n"
            . "\r\n"
            . "Hybrid note:\r\n"
            . "Hybrid means one organization may use both modes across different sites.\r\n"
            . "It does not mean mixing Direct HTTPS and collector-share inside one runner installation.\r\n"
            . "Each package remains mode-specific.\r\n";
    }

    private function collectorSiteReadme(string $runnerVersion): string
    {
        return "Collector-share Site Package\r\n"
            . "\r\n"
            . "Use this package for an HQ, branch, lab, or multi-PC site.\r\n"
            . "\r\n"
            . "Flow:\r\n"
            . "Runner PCs -> local/SMB branch share -> Collector -> Laravel HTTPS portal\r\n"
            . "\r\n"
            . "Requirements and boundaries:\r\n"
            . "- Python 3.x is required on the collector host for MVP.\r\n"
            . "- Python is not auto-installed or bundled in this MVP.\r\n"
            . "- If Python is missing, the installer should fail clearly.\r\n"
            . "- Requires a branch-share path.\r\n"
            . "- Requires an HTTPS endpoint and trusted certificate.\r\n"
            . "- Do not use the Direct HTTPS runner wrapper for this package.\r\n"
            . "- Do not mix Direct HTTPS and collector-share inside one runner install.\r\n"
            . "- Official packages/site kits are generated only on Supermicro.\r\n"
            . "- Do not generate official packages from IT-ADMIN.\r\n"
            . "\r\n"
            . "Run first:\r\n"
            . "INSTALL_COLLECTOR_SITE.cmd\r\n"
            . "\r\n"
            . "Then deploy/install branch runners using the staged collector-share runner package/share flow.\r\n"
            . "\r\n"
            . "Verify in portal:\r\n"
            . "- /collectors\r\n"
            . "- collector status recent\r\n"
            . "- /runners\r\n"
            . "- collector-share runners appear normally\r\n"
            . "- CSV upload/ingest works\r\n"
            . "- command delivery/ACK works through collector-share\r\n"
            . "- runner version {$runnerVersion}\r\n"
            . "\r\n"
            . "Optional Supermicro checks:\r\n"
            . "php artisan inventory:install-preflight\r\n"
            . "php artisan inventory:production-readiness\r\n"
            . "\r\n"
            . "Hybrid note:\r\n"
            . "Hybrid means one organization may use both modes across different sites.\r\n"
            . "It does not mean mixing Direct HTTPS and collector-share inside one runner installation.\r\n"
            . "Each package remains mode-specific.\r\n";
    }

    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        if (is_file($zipPath)) {
            File::delete($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Could not create zip: {$zipPath}");
        }

        foreach (File::allFiles($sourceDir) as $file) {
            $relativePath = Str::replaceFirst(dirname($sourceDir) . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $relativePath));
        }

        $zip->close();
    }
}
