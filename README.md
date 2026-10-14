# Domains & SSL

Give servers their own addresses: a free subdomain on one of your domains, or the customer's own domain from any registrar. DNS records are kept in sync with the server's allocation, and free Let's Encrypt certificates are issued, written into the server's files and renewed automatically.

## Features

- **Domains tab on every server.** Users pick a name such as `mc` under one of your domains (`mc.play.example.com`) and the port it points to. The records are created straight away.
- **Own domains.** Users can also attach a domain they control anywhere. The panel shows exactly which records to create at their DNS host, each value one click to copy, and a **Check DNS** button looks them up in public DNS and marks the domain verified. The panel never touches the DNS of an own domain.
- **A and AAAA records** to the allocation's public address: its IP alias if set, otherwise its IP, and when that is `0.0.0.0` or private, the node's address. Domains are refused when there is no public address.
- **SRV records** for games that read them, so players can join without the port. Minecraft eggs are matched out of the box; map any egg by id, tag or name to an SRV service in the settings.
- **Certificates** from Let's Encrypt (or any ACME CA) with the DNS-01 challenge, written to `/ssl/<domain>/fullchain.pem` and `privkey.pem` in the server's files. They renew on their own 30 days before they expire, and failed attempts are retried with a growing delay (30 minutes up to a day).
- **Certificates for own domains** without access to the customer's DNS: the customer adds one CNAME record once (`_acme-challenge.<their domain>` → a name in your delegation zone), and every issue and renewal sets the challenge record in your zone instead.
- **Kept in sync.** Changing the port updates the records, transfers and IP alias changes are picked up, deleting a domain removes its records and certificate files, and deleting a server cleans up its domains.
- **Admin overview** under **Admin → Domains**: every domain on the panel with its DNS and certificate status and expiry, and a **Test API token** button that checks the token and that every configured zone is reachable with it.
- **Limits and safety**: a domain limit per server, names unique across the whole panel, blocked names (`www`, `mail`, `panel`, `node*`, `ns*`, `admin`, `api`... plus your own list), rate limits on adding domains, checking DNS and requesting certificates, and a check that the name has no existing record before it is used.
- **Subuser permissions**: **Domains & SSL → read** shows the tab, **manage** allows changes. Owners and admins always have both.
- **Provider drivers**: Cloudflare is built in. The DNS code sits behind a small driver interface, so other providers can be added without changing the rest.

## How it works

**Subdomains.** When a user adds `mc` under `play.example.com`, the panel finds the Cloudflare zone for the name, checks the name is free and creates an A (or AAAA) record and, for games that use one, an SRV record. Records are never proxied, so game traffic reaches the server directly.

**Own domains.** The user adds `play.customer.com` and is shown:

| Type | Name | Value |
|---|---|---|
| A | `play.customer.com` | the server's IP |
| SRV (optional) | `_minecraft._tcp.play.customer.com` | `0 5 25565 play.customer.com` |
| CNAME (for certificates) | `_acme-challenge.play.customer.com` | `<random>.acme.example.com` |

**Check DNS** looks them up and shows what is missing. The A record makes the domain verified; the CNAME makes certificates possible.

**Certificates.** Requests are queued and handled by the panel's scheduler within a minute, so the web page never waits on the CA. For each certificate the extension:

1. creates an order with the ACME server (its own small RFC 8555 client, using PHP's OpenSSL extension; the account key is an EC P-256 key stored encrypted in the database),
2. sets the challenge TXT record through the DNS provider: at `_acme-challenge.<domain>` for subdomains, or at `<random>.<delegation zone>` for own domains, which the customer's CNAME points to,
3. waits for the CA to validate, finalizes with a fresh P-256 key and downloads the chain,
4. always removes the TXT record again, stores the certificate and its key (encrypted) so renewals and retries can write the files again, and writes `fullchain.pem` and `privkey.pem` into the server. If the server's node is offline the files are written as soon as it is back.

Point your game or web server's config at those two files. Most software has to be restarted to load a renewed certificate.

## Settings

Open **Admin → Extensions → Domains & SSL → Settings**.

| Setting | Default | What it does |
|---|---|---|
| DNS provider | Cloudflare | |
| Cloudflare API token | | Stored encrypted, never sent to browsers. See below for the permissions it needs. |
| Domains for subdomains | | The domains users can pick a subdomain under, e.g. `play.example.com`. Each must be a Cloudflare zone, or a name inside one, that the token can edit. |
| Allow own domains | On | |
| SRV records | On | |
| SRV services | Minecraft eggs | One rule per line: `egg:<id>=`, `tag:<tag>=` or `name:<part of the egg name>=`, then the service, e.g. `name:paper=_minecraft._tcp`. The first match wins. |
| Blocked subdomain names | | Added to the built-in list. `*` matches anything, e.g. `test*`. |
| Allow certificates | On | |
| ACME directory | Let's Encrypt | Must be `https://`. Use `https://acme-staging-v02.api.letsencrypt.org/directory` while trying things out. |
| ACME contact email | | The CA sends expiry and policy notices here. |
| Delegation zone for own domains | | e.g. `acme.example.com`, a zone or name inside a zone the token can edit. Leave it empty to offer certificates only for subdomains; own domains then still work, without certificates. |
| Certificate folder | `/ssl` | Inside the server's files. |
| Renew this many days before expiry | 30 | |
| Domains per server | 3 | |

### Cloudflare token

Create the token under **Cloudflare → My Profile → API Tokens → Create Token → Custom token** with:

- **Zone → Zone → Read**
- **Zone → DNS → Edit**

and limit **Zone Resources** to the zones you use for subdomains and delegation. Then press **Test API token** under **Admin → Domains**.

## Requirements

- Pterodactyl Panel v2.0.0-beta.1 or later
- PHP 8.3+ with the OpenSSL extension (part of every panel install) and, for international domain names, the intl extension.
- The panel's scheduler running every minute (the `schedule:run` cron entry from the panel install guide). It runs `domains:work` every minute and `domains:renew` every hour.
- Outgoing HTTPS from the panel to `api.cloudflare.com` and the ACME server.

## Install

1. Download `domains-<version>.pteroext` from the [latest release](https://github.com/Rustix-Development/pterodactyl-domains/releases/latest).
2. In the panel, open **Admin → Extensions**, upload the file and enable it.
3. Reload the panel, then fill in the settings.

From a terminal instead:

```sh
php artisan p:extension:install /path/to/domains-<version>.pteroext --enable
```

## Commands

```sh
php artisan domains:work                     # what the scheduler runs every minute
php artisan domains:renew                    # renew certificates that are due, retry failed ones
php artisan domains:renew --domain=mc.play.example.com --force   # renew one now
```

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/client/servers/{server}/extensions/domains/domains` | read or manage |
| POST | `/api/client/servers/{server}/extensions/domains/domains` | manage |
| PUT, DELETE | `/api/client/servers/{server}/extensions/domains/domains/{domain}` | manage |
| POST | `/api/client/servers/{server}/extensions/domains/domains/{domain}/check` | manage |
| POST | `/api/client/servers/{server}/extensions/domains/domains/{domain}/certificate` | manage |
| GET | `/api/admin/extensions/domains/overview` | admins |
| POST | `/api/admin/extensions/domains/test` | admins |

Adding a domain takes `{"kind": "subdomain", "label": "mc", "zone": "play.example.com", "allocation_id": 1, "certificate": true}` or `{"kind": "own", "domain": "play.customer.com"}`.

## Build from source

```sh
npm install
npm run typecheck
npm test
npm run build
php tests/acme.php        # checks for the ACME signing and CSR code
php artisan p:extension:doctor /path/to/domains
php artisan p:extension:pack /path/to/domains
```

## Uninstall

Remove it under **Admin → Extensions**, or from a terminal:

```sh
php artisan p:extension:remove domains
```

Removing the extension stops renewals but leaves DNS records and certificate files where they are; delete domains first if you want them gone. Its data stays in the database tables `ext_domains_domains` and `ext_domains_acme_accounts`.

## License

MIT. See [LICENSE](LICENSE).

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
