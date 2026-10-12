<?php

namespace RustixDev\Domains;

use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use RustixDev\Domains\Console\RenewCommand;
use RustixDev\Domains\Console\WorkCommand;
use RustixDev\Domains\Dns\DnsManager;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Support\DomainSettings;

class DomainsProvider extends ExtensionProvider
{
    private const string HOST = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i';

    public function boot(): void
    {
        $this->registerApiRoutes();

        $this->registerPermissions('Domains & SSL', [
            'read' => 'See the server\'s domains and certificate status.',
            'manage' => 'Add and remove domains, change where they point and request certificates.',
        ]);

        $providers = array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(DnsManager::DRIVERS), DnsManager::DRIVERS);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('dns_provider', 'dns_provider', 'cloudflare', ['string'])
                ->field('select', $providers)
                ->label('DNS provider')
                ->tab('DNS'),
            ExtensionSettingDefinition::make('cloudflare_token', 'cloudflare_token', null, ['nullable', 'string', 'max:255'])
                ->secret()
                ->label('Cloudflare API token')
                ->help('A token with Zone:Read and DNS:Edit on the zones below. It is stored encrypted and never sent to browsers.')
                ->tab('DNS'),
            ExtensionSettingDefinition::make('zones', 'zones', [])
                ->list(['regex:'.self::HOST], 50)
                ->label('Domains for subdomains')
                ->help('Users pick a subdomain under one of these, e.g. play.example.com. Each must be a zone (or inside one) the token can edit.')
                ->tab('DNS'),
            ExtensionSettingDefinition::make('allow_own_domains', 'allow_own_domains', true, ['boolean'])
                ->field('toggle')
                ->label('Allow own domains')
                ->help('Users can attach a domain they own anywhere. They create the records at their DNS host; the panel shows which.')
                ->tab('DNS'),
            ExtensionSettingDefinition::make('srv_records', 'srv_records', true, ['boolean'])
                ->field('toggle')
                ->label('SRV records')
                ->help('Add an SRV record for games that read one (Minecraft), so players can join without the port.')
                ->tab('DNS'),
            ExtensionSettingDefinition::make('srv_services', 'srv_services', DomainSettings::SRV_DEFAULTS)
                ->list(['regex:/^(egg|tag|name):[^=]{1,64}=_[a-z0-9][a-z0-9-]{0,30}\._(tcp|udp|tls)$/i'], 100)
                ->label('SRV services')
                ->help('Which eggs get which SRV service: egg:<id>=, tag:<tag>= or name:<part of the egg name>=, then e.g. _minecraft._tcp. The first match wins.')
                ->tab('DNS'),
            ExtensionSettingDefinition::make('reserved_labels', 'reserved_labels', [])
                ->list(['regex:/^[a-z0-9*?-]{1,63}$/'], 200)
                ->label('Blocked subdomain names')
                ->help('On top of the built-in list (www, mail, panel, node*, ns*, admin, api...). * matches anything.')
                ->tab('DNS'),

            ExtensionSettingDefinition::make('allow_ssl', 'allow_ssl', true, ['boolean'])
                ->field('toggle')
                ->label('Allow certificates')
                ->tab('Certificates'),
            ExtensionSettingDefinition::make('acme_directory', 'acme_directory', DomainSettings::LETS_ENCRYPT, ['nullable', 'string', 'max:500', 'url', 'starts_with:https://'])
                ->label('ACME directory')
                ->help('Let\'s Encrypt by default. For testing use the staging directory: '.DomainSettings::LETS_ENCRYPT_STAGING)
                ->tab('Certificates'),
            ExtensionSettingDefinition::make('acme_email', 'acme_email', null, ['nullable', 'email', 'max:191'])
                ->label('ACME contact email')
                ->help('The CA sends expiry and policy notices here.')
                ->tab('Certificates'),
            ExtensionSettingDefinition::make('acme_delegation_zone', 'acme_delegation_zone', null, ['nullable', 'string', 'regex:'.self::HOST])
                ->label('Delegation zone for own domains')
                ->help('e.g. acme.example.com, on the DNS provider above. Own domains point _acme-challenge here with a CNAME, so their certificates renew on their own. Leave empty to offer certificates only for subdomains.')
                ->tab('Certificates'),
            ExtensionSettingDefinition::make('cert_path', 'cert_path', '/ssl', ['nullable', 'string', 'max:191', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/\.\./'])
                ->label('Certificate folder')
                ->help('Inside the server\'s files. Each domain gets <folder>/<domain>/fullchain.pem and privkey.pem.')
                ->tab('Certificates'),
            ExtensionSettingDefinition::make('renew_days', 'renew_days', 30, ['integer', 'min:1', 'max:60'])
                ->field('number')
                ->label('Renew this many days before expiry')
                ->tab('Certificates'),

            ExtensionSettingDefinition::make('max_domains', 'max_domains', 3, ['integer', 'min:1', 'max:100'])
                ->field('number')
                ->label('Domains per server')
                ->tab('Limits'),
        ]));

        $this->registerCommands([WorkCommand::class, RenewCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('domains:work')->everyMinute()->withoutOverlapping(30);
            $schedule->command('domains:renew')->hourly()->withoutOverlapping(120);
        });

        // After a transfer the server has new allocations, so its records need new targets.
        $this->listenToServerOperations(function (OperationCompleted $event): void {
            if ($event->operation === 'transfer' && $event->successful) {
                Domain::query()->where('server_uuid', $event->serverUuid)->update(['dns_sync_due' => true]);
            }
        });

        $this->app->when(DomainSettings::class)->needs(ExtensionSettings::class)->give(fn () => $this->settings());
    }
}
