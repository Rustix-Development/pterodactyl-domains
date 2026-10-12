<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_domains_domains', function (Blueprint $table) {
            $table->id();
            // Null once the server is deleted; the worker then removes the DNS records and the row.
            $table->unsignedInteger('server_id')->nullable();
            $table->char('server_uuid', 36);
            $table->string('kind', 16)->default('subdomain');
            $table->string('name', 253)->unique();
            $table->string('zone', 253)->nullable();
            $table->unsignedInteger('allocation_id')->nullable();

            // DNS: where the name points, the provider's record ids, and the check result.
            $table->string('target', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('srv_service', 64)->nullable();
            $table->json('dns_records');
            $table->string('dns_status', 16)->default('pending');
            $table->string('dns_error', 1000)->nullable();
            $table->timestamp('dns_checked_at')->nullable();
            $table->boolean('dns_sync_due')->default(false);

            // Own domains: the _acme-challenge CNAME target label and whether it is in place.
            $table->string('acme_token', 64)->nullable()->unique();
            $table->boolean('delegation_ok')->default(false);

            // Certificate state. The key is stored encrypted so renewals can write the files again.
            $table->string('ssl_status', 16)->default('none')->index();
            $table->string('ssl_error', 1000)->nullable();
            $table->unsignedSmallInteger('ssl_attempts')->default(0);
            $table->timestamp('ssl_next_attempt_at')->nullable()->index();
            $table->timestamp('ssl_started_at')->nullable();
            $table->mediumText('cert_chain')->nullable();
            $table->text('cert_key')->nullable();
            $table->timestamp('cert_issued_at')->nullable();
            $table->timestamp('cert_expires_at')->nullable()->index();
            $table->timestamp('cert_written_at')->nullable();

            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('allocation_id')->references('id')->on('allocations')->nullOnDelete();
        });

        Schema::create('ext_domains_acme_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('directory_hash', 64)->unique();
            $table->string('directory', 500);
            $table->text('private_key');
            $table->string('kid', 500)->nullable();
            $table->string('email', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_domains_acme_accounts');
        Schema::dropIfExists('ext_domains_domains');
    }
};
