<?php

namespace RustixDev\Domains\Acme;

use OpenSSLAsymmetricKey;

/**
 * JSON Web Signatures for ACME (RFC 7515, RFC 8555 section 6.2) with an
 * ES256 (P-256) or RS256 account key.
 */
final class Jws
{
    private readonly OpenSSLAsymmetricKey $key;

    private readonly array $details;

    public function __construct(string $privateKeyPem)
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new AcmeException('The ACME account key could not be read.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ! in_array($details['type'], [OPENSSL_KEYTYPE_EC, OPENSSL_KEYTYPE_RSA], true)) {
            throw new AcmeException('The ACME account key must be an EC P-256 or RSA key.');
        }
        if ($details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new AcmeException('An EC account key must use the P-256 curve.');
        }

        $this->key = $key;
        $this->details = $details;
    }

    /** A new P-256 private key as PEM. */
    public static function generateEcKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new AcmeException('Could not create a key: '.(openssl_error_string() ?: 'OpenSSL error'));
        }

        return $pem;
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function algorithm(): string
    {
        return $this->details['type'] === OPENSSL_KEYTYPE_EC ? 'ES256' : 'RS256';
    }

    /**
     * The public key as a JWK, members in lexicographic order as the thumbprint needs.
     *
     * @return array<string, string>
     */
    public function jwk(): array
    {
        if ($this->details['type'] === OPENSSL_KEYTYPE_EC) {
            return [
                'crv' => 'P-256',
                'kty' => 'EC',
                'x' => self::base64url(str_pad($this->details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                'y' => self::base64url(str_pad($this->details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ];
        }

        return [
            'e' => self::base64url($this->details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::base64url($this->details['rsa']['n']),
        ];
    }

    /** RFC 7638 thumbprint of the account key. */
    public function thumbprint(): string
    {
        return self::base64url(hash('sha256', json_encode($this->jwk(), JSON_UNESCAPED_SLASHES), true));
    }

    /** The TXT record value for a dns-01 challenge token (RFC 8555 section 8.4). */
    public function dnsChallengeValue(string $token): string
    {
        return self::base64url(hash('sha256', $token.'.'.$this->thumbprint(), true));
    }

    /**
     * A flattened JWS. $payload null makes a POST-as-GET (empty payload).
     * Without $kid the public key is embedded, as newAccount requires.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{protected: string, payload: string, signature: string}
     */
    public function sign(string $url, string $nonce, ?array $payload, ?string $kid = null): array
    {
        $header = ['alg' => $this->algorithm(), 'nonce' => $nonce, 'url' => $url];
        $header += $kid === null ? ['jwk' => $this->jwk()] : ['kid' => $kid];

        $protected = self::base64url(json_encode($header, JSON_UNESCAPED_SLASHES));
        $body = $payload === null ? '' : self::base64url(json_encode($payload === [] ? new \stdClass : $payload, JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($protected.'.'.$body, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new AcmeException('Could not sign the ACME request.');
        }

        if ($this->details['type'] === OPENSSL_KEYTYPE_EC) {
            $signature = self::derToRaw($signature, 32);
        }

        return ['protected' => $protected, 'payload' => $body, 'signature' => self::base64url($signature)];
    }

    /** OpenSSL gives ECDSA signatures as DER SEQUENCE { r, s }; JWS wants r || s, each $size bytes. */
    public static function derToRaw(string $der, int $size): string
    {
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new AcmeException('Unexpected ECDSA signature format.');
            }
            $length = ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\0");
            $parts[] = str_pad($value, $size, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }

    /** The reverse of derToRaw(), for checking signatures in tests. */
    public static function rawToDer(string $raw): string
    {
        $size = intdiv(strlen($raw), 2);
        $encode = function (string $int): string {
            $int = ltrim($int, "\0");
            if ($int === '' || ord($int[0]) & 0x80) {
                $int = "\0".$int;
            }

            return "\x02".chr(strlen($int)).$int;
        };
        $sequence = $encode(substr($raw, 0, $size)).$encode(substr($raw, $size));

        return "\x30".chr(strlen($sequence)).$sequence;
    }
}
