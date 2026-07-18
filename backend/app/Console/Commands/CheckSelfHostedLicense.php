<?php

namespace App\Console\Commands;

use App\Services\Licensing\LicenseService;
use Illuminate\Console\Command;

class CheckSelfHostedLicense extends Command
{
    protected $signature = 'license:check';

    protected $description = 'Self-hosted deployments only: verify this school\'s license with the main SKULOS platform and enforce grace/lockout.';

    public function handle(LicenseService $licenses): int
    {
        $licenses->checkSelfHosted();

        return self::SUCCESS;
    }
}
