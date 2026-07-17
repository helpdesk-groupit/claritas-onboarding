<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-connection OAuth tenant (Microsoft directory) for Email Workflow.
 *
 * WHY: the Microsoft authorize/token URLs were hardcoded to the shared
 * `/common` endpoint, and Microsoft REFUSES `/common` for a single-tenant app
 * registration created after 15/10/2018 — which is the default when you
 * register an app in your own directory. Every Outlook sign-in therefore failed
 * with AADSTS50194 no matter how correct the permissions were (confirmed in
 * production on 2026-07-17: consent granted tenant-wide, five identical
 * failures, "not configured as a multi-tenant application").
 *
 * Microsoft offers two remedies: mark the app multi-tenant, or use a
 * tenant-specific endpoint. This column is the second — it keeps the
 * registration single-tenant (the least-privilege default) rather than opening
 * the app to consent from every Microsoft directory on earth.
 *
 * Nullable on purpose: blank falls back to the provider's default (`common`),
 * so Gmail/Drive/Sheets are untouched and a genuinely multi-tenant Microsoft app
 * keeps working with no value set.
 *
 * NOT a secret — a directory ID is a public identifier, so no encrypted cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_workflow_connections', function (Blueprint $table) {
            // Holds a directory GUID, a verified domain, or common|organizations|consumers.
            $table->string('oauth_tenant', 100)->nullable()->after('client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('email_workflow_connections', function (Blueprint $table) {
            $table->dropColumn('oauth_tenant');
        });
    }
};
