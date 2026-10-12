<?php

namespace RustixDev\Domains\Console;

use Illuminate\Console\Command;
use RustixDev\Domains\Services\DomainWorker;

class WorkCommand extends Command
{
    protected $signature = 'domains:work';

    protected $description = 'Issue requested certificates, update DNS records and clean up after deleted servers.';

    public function handle(DomainWorker $worker): int
    {
        foreach ($worker->work() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
