Give servers their own addresses: subdomains on your zones or the customer's own domain, DNS records kept in sync, and free Let's Encrypt certificates written into the server's files.

## Install

Download `domains-${VERSION}.pteroext` below, then upload it under **Admin → Extensions** and enable it. From a terminal instead:

```sh
php artisan p:extension:install /path/to/domains-${VERSION}.pteroext --enable
```

Then add your Cloudflare API token (Zone:Read and DNS:Edit) and zones under the extension's settings. Requires Pterodactyl Panel v2.0.0-beta.1 or later, PHP 8.3+ and the panel's scheduler. See the README for features and settings.

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
