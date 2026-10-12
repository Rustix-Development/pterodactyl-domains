<?php

namespace RustixDev\Domains\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ACME account for one directory (production, staging...). The private key is
 * stored encrypted with the panel's APP_KEY.
 *
 * @property int $id
 * @property string $directory_hash
 * @property string $directory
 * @property string $private_key
 * @property string|null $kid
 * @property string|null $email
 */
class AcmeAccount extends Model
{
    protected $table = 'ext_domains_acme_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return ['private_key' => 'encrypted'];
    }
}
