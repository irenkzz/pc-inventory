<?php

namespace App\Console\Commands;

use App\Services\Deployment\SiteKitProfileValidator;
use Illuminate\Console\Command;

class ValidateSiteProfile extends Command
{
    protected $signature = 'inventory:validate-site-profile
        {profile : JSON deployment profile path}
        {--strict : Treat warnings as deployment-blocking failures}';

    protected $description = 'Validate a site-kit deployment profile before building a runner and collector kit';

    public function handle(SiteKitProfileValidator $validator): int
    {
        try {
            $resolved = $validator->resolvePath((string) $this->argument('profile'));
            $profile = $validator->load((string) $this->argument('profile'));
            $result = $validator->validate($profile);
        } catch (\Throwable $exception) {
            $this->line(json_encode([
                'status' => 'fail',
                'profile' => (string) $this->argument('profile'),
                'errors' => [$exception->getMessage()],
                'warnings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $warnings = $result['warnings'];
        $errors = $result['errors'];
        if ($this->option('strict') && $warnings !== []) {
            $errors = array_merge($errors, $warnings);
        }

        $status = $errors === [] ? 'ok' : 'fail';
        $this->line(json_encode([
            'status' => $status,
            'profile' => $resolved,
            'site_id' => $profile['site_id'] ?? null,
            'errors' => $errors,
            'warnings' => $warnings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
