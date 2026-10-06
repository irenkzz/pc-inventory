<?php

namespace App\Console\Commands;

use App\Services\Deployment\SiteKitBuilder;
use Illuminate\Console\Command;

class BuildSiteKit extends Command
{
    protected $signature = 'inventory:build-site-kit
        {--profile= : JSON deployment profile path}
        {--transport-mode=}
        {--site-id=}
        {--site-name=}
        {--collector-name=}
        {--collector-required}
        {--share-root=}
        {--server-base-url=}
        {--site-token=}
        {--direct-runner-token=}
        {--runner-version=}
        {--scan-interval-minutes=}
        {--collector-poll-interval-minutes=}
        {--task-random-delay-minutes=}
        {--default-location=}
        {--default-room=}
        {--task-name=}
        {--install-root=}
        {--exe : Also wrap the kit into one self-extracting SITE-<ID>-runner-setup.exe (Windows + iexpress.exe only; contains the site token)}
        {--strict : Treat site profile validation warnings as deployment-blocking failures}';

    protected $description = 'Build a site-specific runner and collector deployment kit';

    public function handle(SiteKitBuilder $builder): int
    {
        $options = array_filter($this->options(), fn (mixed $value): bool => $value !== null && $value !== '');
        unset($options['exe']);

        try {
            $result = $builder->build($options);
            if ($this->option('exe')) {
                $result['exe'] = $builder->buildExe($result);
            }
        } catch (\Throwable $exception) {
            $this->line(json_encode([
                'status' => 'fail',
                'error' => $exception->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
