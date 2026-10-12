<?php

namespace RustixDev\Domains\Acme;

/**
 * Certificate signing requests for the order's finalize step.
 */
final class Csr
{
    /**
     * A CSR for $names (the first is the common name), signed with $privateKeyPem, as DER.
     *
     * @param  non-empty-list<string>  $names
     */
    public static function create(array $names, string $privateKeyPem): string
    {
        foreach ($names as $name) {
            if (! preg_match('/^[a-z0-9.-]{1,253}$/', $name)) {
                throw new AcmeException('Invalid name for a certificate.');
            }
        }

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new AcmeException('The certificate key could not be read.');
        }

        // OpenSSL only takes subjectAltName from a config file.
        $config = tempnam(sys_get_temp_dir(), 'ext-domains-csr');
        file_put_contents($config, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            'req_extensions = san',
            'prompt = no',
            '[dn]',
            '[san]',
            'subjectAltName = '.implode(',', array_map(fn (string $name) => 'DNS:'.$name, $names)),
            '',
        ]));

        try {
            $csr = openssl_csr_new(['commonName' => $names[0]], $key, [
                'config' => $config,
                'digest_alg' => 'sha256',
                'req_extensions' => 'san',
            ]);
            if ($csr === false || ! openssl_csr_export($csr, $pem)) {
                throw new AcmeException('Could not create the certificate request: '.(openssl_error_string() ?: 'OpenSSL error'));
            }
        } finally {
            @unlink($config);
        }

        return self::pemToDer($pem);
    }

    public static function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $pem);

        return (string) base64_decode((string) $body, true);
    }

    /**
     * The leaf certificate's expiry date as a Unix time, and whether the chain's
     * first certificate belongs to $privateKeyPem.
     *
     * @return array{expires: int, issued: int, matches: bool}
     */
    public static function inspect(string $chainPem, string $privateKeyPem): array
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $chainPem, $match)) {
            throw new AcmeException('The CA returned no certificate.');
        }

        $info = openssl_x509_parse($match[0]);
        if ($info === false) {
            throw new AcmeException('The CA returned a certificate that could not be read.');
        }

        return [
            'expires' => (int) $info['validTo_time_t'],
            'issued' => (int) $info['validFrom_time_t'],
            'matches' => openssl_x509_check_private_key($match[0], $privateKeyPem),
        ];
    }
}
