<?php

namespace RustixDev\Domains\Console;

use Illuminate\Console\Command;
use RustixDev\Domains\Services\DomainWorker;

class RenewCommand extends Command
{
    protected $signature = 'domains:renew
        {--domain= : Only this domain}
        {--force : Renew active certificates even when they are not due yet}';

    protected $description = 'Renew certificates that expire soon, retry failed ones and keep records pointing at the right address.';

    public function handle(DomainWorker $worker): int
    {
        $domain = $this->option('domain');
        $lines = $worker->renew(force: (bool) $this->option('force'), only: is_string($domain) && $domain !== '' ? strtolower($domain) : null);

        foreach ($lines as $line) {
            $this->line($line);
        }
        if ($lines === []) {
            $this->line('Nothing to renew.');
        }

        return self::SUCCESS;
    }
}
