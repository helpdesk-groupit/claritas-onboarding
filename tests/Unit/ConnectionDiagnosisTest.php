<?php

namespace Tests\Unit;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\ConnectionDiagnosis;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cover for the raw-error → plain-language translator.
 *
 * ConnectionDiagnosis is pure, so every signature it claims to recognise is
 * pinned here rather than discovered in production — which is where the Zoho
 * one below was found.
 */
class ConnectionDiagnosisTest extends TestCase
{
    private function zohoConn(string $user = 'billing@nurengroup.com'): EmailWorkflowConnection
    {
        return new EmailWorkflowConnection([
            'provider_id' => 'imap',
            'imap_host' => 'imap.zoho.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $user,
        ]);
    }

    // ── The failure this class was written for ───────────────────────────

    /**
     * The exact string Zoho returned in production on 2026-07-17 for a mailbox
     * with IMAP switched off, while a second mailbox on the same host worked.
     */
    public function test_it_explains_zohos_imap_disabled_rejection(): void
    {
        $message = ConnectionDiagnosis::explain(
            new \Exception('NO [ALERT] You are yet to enable IMAP for your account. Please contact your administrator (Failure)'),
            $this->zohoConn()
        );

        $this->assertStringContainsString('IMAP is switched off for billing@nurengroup.com', $message);
        $this->assertStringContainsString('Zoho Mail → Settings → Mail Accounts → IMAP Access', $message);
        // The insight that stops this being read as a bug in the automation.
        $this->assertStringContainsString('per-mailbox setting', $message);
        // The class name that noticed the problem is not the remedy.
        $this->assertStringNotContainsString('Exception', $message);
    }

    /**
     * An earlier draft said "or ask your mail administrator to", and the
     * operator went to Zoho's Admin Console → Email Policy → Restrictions,
     * which reported "IMAP Access: Enabled" while every login stayed refused.
     * That screen governs whether users are ALLOWED to switch IMAP on; it does
     * not switch it on. The message must send them to the mailbox, and must say
     * why the admin screen's "Enabled" is not the answer.
     */
    public function test_it_distinguishes_the_org_wide_permission_from_the_per_mailbox_switch(): void
    {
        $message = ConnectionDiagnosis::explain(
            new \Exception('NO [ALERT] You are yet to enable IMAP for your account. Please contact your administrator'),
            $this->zohoConn()
        );

        $this->assertStringContainsString('Sign in as that mailbox and switch it on', $message);
        $this->assertStringContainsString('only PERMITS IMAP', $message);
        $this->assertStringContainsString('not enough on its own', $message);
        // Must not send the operator back to the admin console as the remedy.
        $this->assertStringNotContainsString('ask your mail administrator', $message);
    }

    /**
     * The bound exists for untrusted provider text; truncating our own remedy
     * mid-sentence would cut off the very part the operator needs.
     *
     * A long (but valid — the column allows 255) mailbox pushes the explanation
     * past MAX_LENGTH, which is what proves the curated path is not capped.
     */
    public function test_our_own_explanation_is_never_truncated_by_the_raw_text_bound(): void
    {
        $longMailbox = str_repeat('a', 200).'@nurengroup.com';

        $message = ConnectionDiagnosis::explain(
            new \Exception('NO [ALERT] You are yet to enable IMAP for your account'),
            $this->zohoConn($longMailbox)
        );

        $this->assertGreaterThan(ConnectionDiagnosis::MAX_LENGTH, mb_strlen($message));
        // The closing clause survives — the remedy must reach the operator whole.
        $this->assertStringEndsWith('while this one does not.', $message);
    }

    /**
     * webklex rethrows its causes wrapped, so an explanation that only reads
     * getMessage() would miss every real-world instance of these errors.
     */
    public function test_it_reads_the_whole_exception_chain_not_just_the_wrapper(): void
    {
        $wrapped = new \Exception(
            'Failed to fetch messages',
            0,
            new \Exception('NO [ALERT] You are yet to enable IMAP for your account')
        );

        $this->assertStringContainsString(
            'IMAP is switched off',
            ConnectionDiagnosis::explain($wrapped, $this->zohoConn())
        );
    }

    public function test_it_names_the_provider_settings_path_from_the_host(): void
    {
        $gmail = new EmailWorkflowConnection([
            'provider_id' => 'imap',
            'imap_host' => 'imap.gmail.com',
            'imap_username' => 'ap@example.com',
        ]);

        $this->assertStringContainsString(
            'Gmail → Settings → Forwarding and POP/IMAP',
            ConnectionDiagnosis::explain(new \Exception('IMAP access is disabled'), $gmail)
        );
    }

    public function test_an_unknown_host_still_explains_the_cause_generically(): void
    {
        $other = new EmailWorkflowConnection([
            'provider_id' => 'imap',
            'imap_host' => 'mail.acme.internal',
            'imap_username' => 'ap@acme.internal',
        ]);

        $message = ConnectionDiagnosis::explain(new \Exception('IMAP is disabled'), $other);

        $this->assertStringContainsString('IMAP is switched off for ap@acme.internal', $message);
        $this->assertStringContainsString("your mail provider's IMAP settings", $message);
    }

    /**
     * Microsoft Graph, verbatim from production 2026-07-17. Guzzle summarises
     * the body at 120 chars, so Graph's sentence arrives cut mid-word — the
     * fixture keeps that truncation, because matching on the prose after
     * "hosted on-" would pass here and fail on the real thing.
     */
    public function test_it_explains_an_account_whose_mailbox_graph_cannot_read(): void
    {
        $message = ConnectionDiagnosis::explain(new \Exception(
            'HTTP request returned status code 404: {"error":{"code":"MailboxNotEnabledForRESTAPI",'
            .'"message":"The mailbox is either inactive, soft-deleted, or is hosted on- (truncated...)'
        ));

        // Leads with the cause that actually bites, and is the one nobody
        // guesses: Exchange does not finish creating a mailbox until someone
        // signs in to it, so an admin address that is correctly licensed still
        // 404s. Confirmed 2026-07-17 — the account had Microsoft 365 Business
        // Basic (which DOES include Exchange Online Plan 1), so an earlier draft
        // that led with "no licence" sent the operator to check a box that was
        // already ticked.
        $this->assertStringContainsString('never provisioned', $message);
        $this->assertStringContainsString('outlook.office.com', $message);
        // The non-obvious trap: delegated Graph reads whoever consented.
        $this->assertStringContainsString('account you consent as IS the mailbox that gets read', $message);
        $this->assertStringNotContainsString('RequestException', $message);
    }

    // ── The other two failures operators actually hit ────────────────────

    public function test_it_explains_rejected_credentials_and_points_at_app_passwords(): void
    {
        $message = ConnectionDiagnosis::explain(
            new \Exception('[AUTHENTICATIONFAILED] Invalid credentials'),
            $this->zohoConn()
        );

        $this->assertStringContainsString('rejected the username or password', $message);
        $this->assertStringContainsString('app-specific password', $message);
    }

    public function test_it_explains_an_unreachable_server_with_the_settings_to_check(): void
    {
        $message = ConnectionDiagnosis::explain(
            new \Exception('Connection refused'),
            $this->zohoConn()
        );

        $this->assertStringContainsString('imap.zoho.com:993 over ssl', $message);
        $this->assertStringContainsString('host, port and encryption', $message);
    }

    /**
     * A Graph timeout on an OAuth connection must NOT tell the operator to check
     * host/port/encryption — an OAuth provider has none, and on 2026-07-17 that
     * advice was read as "Microsoft needs incoming server settings", sending the
     * operator to configure fields that cannot exist. Same transport error
     * (cURL 28 / timed out), opposite remedy — the branch must split on kind.
     */
    public function test_a_graph_timeout_does_not_send_the_operator_hunting_for_server_settings(): void
    {
        $outlook = new EmailWorkflowConnection(['provider_id' => 'outlook', 'account_label' => 'admin@claritas.asia']);

        $message = ConnectionDiagnosis::explain(new \Exception(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received '
            .'for https://graph.microsoft.com/v1.0/me/messages'
        ), $outlook);

        $this->assertStringContainsString('has no host, port or encryption to configure', $message);
        $this->assertStringNotContainsString('Check the host, port and encryption', $message);
    }

    /** With no connection to ask, the URL in the error is what reveals it's an API. */
    public function test_a_timeout_naming_an_https_url_is_treated_as_an_api_failure(): void
    {
        $message = ConnectionDiagnosis::explain(new \Exception(
            'cURL error 28: Operation timed out ... for https://gmail.googleapis.com/gmail/v1/users/me/messages'
        ));

        $this->assertStringContainsString('no host, port or encryption to configure', $message);
    }

    /** An IMAP timeout still gets the mail-server remedy — the split must not overreach. */
    public function test_an_imap_timeout_still_points_at_the_mail_server_settings(): void
    {
        $message = ConnectionDiagnosis::explain(new \Exception('Connection timed out'), $this->zohoConn());

        $this->assertStringContainsString('imap.zoho.com:993 over ssl', $message);
        $this->assertStringContainsString('host, port and encryption', $message);
    }

    // ── Fallback behaviour must not regress ──────────────────────────────

    /**
     * The pre-existing safeMessage() contract: our own RuntimeExceptions carry
     * an operator-facing string already, so they pass through unprefixed.
     */
    public function test_our_own_runtime_exceptions_pass_through_without_a_class_prefix(): void
    {
        $this->assertSame(
            'Storage connection is not configured — finish the wizard first.',
            ConnectionDiagnosis::explain(
                new RuntimeException('Storage connection is not configured — finish the wizard first.')
            )
        );
    }

    public function test_an_unrecognised_error_keeps_its_class_name_and_message(): void
    {
        $this->assertSame(
            'InvalidArgumentException: something entirely new went wrong',
            ConnectionDiagnosis::explain(new \InvalidArgumentException('something entirely new went wrong'))
        );
    }

    public function test_it_bounds_the_message_because_provider_errors_can_echo_pii(): void
    {
        $message = ConnectionDiagnosis::explain(new \Exception(str_repeat('x', 5000)));

        $this->assertLessThanOrEqual(ConnectionDiagnosis::MAX_LENGTH, mb_strlen($message));
    }

    public function test_it_collapses_whitespace_so_the_flash_stays_one_line(): void
    {
        $this->assertSame(
            'Exception: line one line two',
            ConnectionDiagnosis::explain(new \Exception("line one\n\n\tline two"))
        );
    }

    /** Without a connection there is no mailbox to name, but the cause still lands. */
    public function test_it_works_without_a_connection(): void
    {
        $message = ConnectionDiagnosis::explain(new \Exception('IMAP access is disabled'));

        $this->assertStringContainsString('IMAP is switched off for this mailbox', $message);
        $this->assertStringContainsString("your mail provider's IMAP settings", $message);
    }

    // ── OAuth consent rejections ─────────────────────────────────────────
    //
    // The provider redirects back with `error` + `error_description`, and the
    // description is the ONLY copy of why consent failed — nothing else in the
    // request says. The callback used to drop both and flash a fixed
    // "Authorization was cancelled or failed", which is why a Microsoft
    // connection could fail for hours with not one line in the log.

    /**
     * The likeliest Microsoft failure, and the one that reads as "authorize
     * worked": the user signs in fine, Microsoft accepts the credentials, then
     * refuses to grant the scopes because the tenant reserves consent for
     * admins. AADSTS90094 arrives dressed as a plain `access_denied`, which is
     * indistinguishable from a cancelled sign-in unless the description is read.
     */
    public function test_it_explains_when_the_tenant_reserves_consent_for_an_admin(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError(
            'access_denied',
            'AADSTS90094: The grant requires admin permission. Trace ID: 0000aaaa-11bb-cccc'
        );

        $this->assertStringContainsString('administrator', $message);
        $this->assertStringContainsString('Grant admin consent', $message);
        // The sign-in genuinely succeeded — saying "cancelled" sends the
        // operator to retry the one step that already works.
        $this->assertStringNotContainsString('cancelled', $message);
    }

    /** AADSTS65001 is the same remedy by a different code — nothing consented yet. */
    public function test_it_explains_when_nothing_has_consented_to_the_app_yet(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError(
            'consent_required',
            'AADSTS65001: The user or administrator has not consented to use the application with ID ...'
        );

        $this->assertStringContainsString('Grant admin consent', $message);
        $this->assertStringContainsString('API permissions', $message);
    }

    /**
     * The trap built into the registry: the authorize URL is hardcoded to the
     * shared /common endpoint, which Microsoft refuses for a single-tenant app
     * registration created after 2018 — the default when you register an app in
     * your own tenant. Nothing about the app registration looks wrong, so the
     * remedy has to name the setting.
     */
    public function test_it_explains_a_single_tenant_app_registration_on_the_common_endpoint(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError(
            'invalid_request',
            "AADSTS50194: Application 'abc' is not configured as a multi-tenant application. "
            .'Usage of the /common endpoint is not supported for such applications created after 10/15/2018.'
        );

        $this->assertStringContainsString('Supported account types', $message);
        $this->assertStringContainsString('multitenant', $message);
    }

    /** A mailbox in another tenant, signed into a single-tenant registration. */
    public function test_it_explains_an_account_from_a_different_tenant(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError(
            'invalid_request',
            'AADSTS50020: User account from identity provider does not exist in tenant'
        );

        $this->assertStringContainsString('different Microsoft tenant', $message);
    }

    public function test_it_explains_rejected_scopes(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError(
            'invalid_scope',
            "AADSTS70011: The provided value for the input parameter 'scope' is not valid."
        );

        $this->assertStringContainsString('Mail.Read', $message);
        $this->assertStringContainsString('offline_access', $message);
    }

    /**
     * A real cancel, with no code to disambiguate it. This one MAY say
     * cancelled — but must still name the consent policy, because a blocked
     * consent can arrive as a bare access_denied too.
     */
    public function test_a_bare_access_denied_covers_both_a_cancel_and_a_blocked_consent(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError('access_denied', 'the user canceled the authentication');

        $this->assertStringContainsString('cancelled', $message);
        $this->assertStringContainsString('admin', $message);
    }

    public function test_it_tells_the_operator_to_retry_a_temporary_provider_fault(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError('temporarily_unavailable', 'AADSTS50058: try again');

        $this->assertStringContainsString('try again', $message);
    }

    /**
     * An unrecognised code must still carry the provider's own words through —
     * a fixed sentence is exactly the failure this method exists to remove.
     */
    public function test_an_unrecognised_oauth_error_still_surfaces_the_providers_own_words(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError('weird_new_error', 'AADSTS99999: something novel');

        $this->assertStringContainsString('weird_new_error', $message);
        $this->assertStringContainsString('AADSTS99999: something novel', $message);
    }

    public function test_an_unrecognised_oauth_error_with_no_description_still_names_the_code(): void
    {
        $this->assertStringContainsString(
            'weird_new_error',
            ConnectionDiagnosis::explainOAuthError('weird_new_error', null)
        );
    }

    /**
     * Provider text is untrusted and unbounded, so it is capped — but as ONE
     * portion, not per field: capping `error` and `error_description`
     * separately let a 5000-char description through at 2× the bound.
     *
     * The assertion counts the provider's own characters rather than the whole
     * string, because our leading sentence is trusted and sits outside the cap
     * (same rule as explain() — see cap()).
     */
    public function test_it_bounds_an_unrecognised_oauth_description(): void
    {
        $message = ConnectionDiagnosis::explainOAuthError('weird', str_repeat('x', 5000));

        $this->assertLessThanOrEqual(ConnectionDiagnosis::MAX_LENGTH, mb_substr_count($message, 'x'));
    }
}
