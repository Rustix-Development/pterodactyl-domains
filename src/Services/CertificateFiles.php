<?php

namespace RustixDev\Domains\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Models\Server;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Support\DomainSettings;

/**
 * Writes fullchain.pem and privkey.pem into the server's files at
 * <certificate folder>/<domain>/, and removes that folder again.
 */
class CertificateFiles
{
    public function __construct(
        private readonly DomainSettings $settings,
        private readonly WritesFileContents $writer,
        private readonly DeletesFiles $deleter,
    ) {}

    public function folder(Domain $domain): string
    {
        return rtrim($this->settings->certPath(), '/').'/'.$domain->name;
    }

    public function write(Domain $domain, Server $server): void
    {
        if ($domain->cert_chain === null || $domain->cert_key === null) {
            return;
        }

        $folder = $this->folder($domain);
        $this->writer->write($server, $folder.'/fullchain.pem', $domain->cert_chain);
        $this->writer->write($server, $folder.'/privkey.pem', $domain->cert_key);
        $domain->cert_written_at = CarbonImmutable::now();
    }

    public function delete(Domain $domain, Server $server): void
    {
        $this->deleter->delete($server, $this->settings->certPath(), [$domain->name]);
    }
}
