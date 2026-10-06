<?php

namespace Tests\Unit;

use App\Services\Deployment\SiteKitBuilder;
use PHPUnit\Framework\TestCase;

class SiteKitSfxTest extends TestCase
{
    private function builder(): SiteKitBuilder
    {
        return (new \ReflectionClass(SiteKitBuilder::class))->newInstanceWithoutConstructor();
    }

    public function test_iexpress_sed_lists_wrapper_and_zip_and_hides_extraction(): void
    {
        $sed = $this->builder()->iexpressSed('SITE-HQ', 'C:\\out\\SITE-HQ-runner-setup.exe', 'C:\\tmp\\sfx', 'sfx_setup.cmd', 'site-kit-SITE-HQ.zip');

        $this->assertStringContainsString('Class=IEXPRESS', $sed);
        $this->assertStringContainsString('TargetName=C:\\out\\SITE-HQ-runner-setup.exe', $sed);
        $this->assertStringContainsString('AppLaunched=cmd /c .\sfx_setup.cmd', $sed);
        $this->assertStringContainsString('HideExtractAnimation=1', $sed);
        $this->assertStringContainsString('SourceFiles0=C:\\tmp\\sfx\\', $sed);
        $this->assertStringContainsString('FILE1="site-kit-SITE-HQ.zip"', $sed);
        $this->assertStringContainsString("%FILE0%=\r\n%FILE1%=", $sed);
    }

    public function test_sfx_wrapper_extracts_to_temp_runs_launcher_and_cleans_up(): void
    {
        $cmd = $this->builder()->sfxWrapperCmd('SITE-HQ', 'site-kit-SITE-HQ.zip');

        $this->assertStringContainsString('Expand-Archive', $cmd);
        $this->assertStringContainsString('site-kit-SITE-HQ\\INSTALL_THIS_PC_RUNNER_ONLY.cmd', $cmd);
        $this->assertStringContainsString('rmdir /s /q "%WORK%"', $cmd);
        $this->assertStringContainsString('exit /b %RC%', $cmd);
    }
}
