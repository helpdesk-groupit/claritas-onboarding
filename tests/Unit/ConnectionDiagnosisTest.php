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
}
