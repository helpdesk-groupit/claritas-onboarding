<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMAP / app-password fields for the Email Workflow connections table.
 *
 * Generic IMAP and Yahoo authenticate with host + username + app-password
 * (not an OAuth client id/secret). The password is encrypted at rest via the
 * model's `encrypted` cast, same as the OAuth secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_workflow_connections', function (Blueprint $table) {
            $table->string('imap_host')->nullable()->after('account_label');
            $table->unsignedSmallInteger('imap_port')->nullable()->after('imap_host');
            $table->string('imap_encryption', 10)->nullable()->after('imap_port'); // ssl|tls|starttls|none
            $table->string('imap_username')->nullable()->after('imap_encryption');
            $table->text('imap_password')->nullable()->after('imap_username');      // encrypted
        });
    }

    public function down(): void
    {
        Schema::table('email_workflow_connections', function (Blueprint $table) {
            $table->dropColumn(['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password']);
        });
    }
};
