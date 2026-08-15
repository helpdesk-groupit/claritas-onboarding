<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves files from private (non-public) storage with authentication and authorization checks.
 * Prevents direct URL access to sensitive documents (NRIC, contracts, certificates, etc.).
 */
class SecureFileController extends Controller
{
    /**
     * Allowed directory prefixes and their required roles.
     * 'self' means the employee themselves can also access the file.
     */
    private const DIRECTORY_PERMISSIONS = [
        'nric_documents' => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'employee_contracts' => ['hr_manager', 'superadmin', 'system_admin', 'self'],
        'employee_documents' => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'education_certificates' => ['hr_manager', 'hr_executive', 'hr_intern', 'superadmin', 'system_admin', 'self'],
        'leave-attachments' => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'aarfs' => ['hr_manager', 'hr_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin', 'self'],
        'invoices' => ['hr_manager', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'rental_contracts' => ['hr_manager', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'claim_receipts' => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'claim_supporting' => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        // Asset Decommissioning — quotation/receipt/report docs (Finance + IT).
        'ewaste_quotations' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'ewaste_receipts' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'decommission_reports' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'hr_manager', 'superadmin', 'system_admin'],
        // Vendor Management — contracts, quotations and invoices. Mirrors User::VENDOR_ROLES;
        // keep the two in step or a role reaches the page but 403s on every document on it.
        'vendor_contracts' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'vendor_billing' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        // Proof of payment for those invoices. Same set again — the slip is read on the same
        // row as the bill it settles, so anyone who can open one must be able to open both.
        'vendor_payment_slips' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        // Signed AARFs — same set again: whoever reaches the vendor profile reads its documents.
        'rental_acknowledgements' => ['finance_manager', 'finance_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        // ticket_attachments is intentionally not listed here — it follows
        // ticket-level access (creator / assignee / dept manager / sysadmin),
        // not directory roles, since work-role-gated dept managers (Tech,
        // Marketing, etc.) carry users.role='employee' and can't be enumerated.
        // Resolved in canAccessTicketFile() instead.
    ];

    /**
     * Download/stream a file from secure (private) storage.
     *
     * @param  string  $path  The relative path within private storage (e.g., "nric_documents/abc123.pdf")
     */
    public function serve(Request $request, string $path): StreamedResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Authentication required.');
        }

        // Prevent path traversal attacks
        $path = str_replace(['..', "\0"], '', $path);

        // Check private storage first, fall back to public for backward compatibility
        $disk = 'local';
        if (! Storage::disk('local')->exists($path)) {
            if (Storage::disk('public')->exists($path)) {
                $disk = 'public';
            } else {
                abort(404);
            }
        }

        // Determine the directory prefix
        $directory = explode('/', $path)[0] ?? '';

        // Check directory-level permission
        if (! $this->hasAccess($user, $directory, $path)) {
            abort(403);
        }

        $mimeType = Storage::disk($disk)->mimeType($path);
        $fileName = basename($path);

        return Storage::disk($disk)->download($path, $fileName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * Check if the user has access to the given directory/file.
     */
    private function hasAccess($user, string $directory, string $path): bool
    {
        // Tickets follow ticket-level access (creator / assignee / dept manager
        // / sysadmin), not directory-level roles, because work-role-gated dept
        // managers carry users.role='employee' and can't be listed in
        // DIRECTORY_PERMISSIONS. Mirrors TicketController::authorizeView().
        if ($directory === 'ticket_attachments') {
            return $this->canAccessTicketFile($user, $path);
        }

        // Expense-claim receipts/supporting docs follow CLAIM-level access (owner, HR, the
        // claim's approving manager, or an item approver) — not just directory-level roles,
        // because work-role-gated managers (e.g. IT/Group managers) who legitimately review a
        // team claim carry users.role='employee'/'it_manager' and aren't in DIRECTORY_PERMISSIONS.
        // Mirrors ExpenseClaimController::authorizeReview() + owner check.
        if ($directory === 'claim_receipts' || $directory === 'claim_supporting') {
            return $this->canAccessClaimFile($user, $path);
        }

        $permissions = self::DIRECTORY_PERMISSIONS[$directory] ?? null;

        // If directory not in permissions map, deny by default
        if ($permissions === null) {
            return false;
        }

        // Check role-based access
        if (in_array($user->role, $permissions)) {
            return true;
        }

        // Check 'self' access — employee can view their own files
        if (in_array('self', $permissions) && $user->employee) {
            return $this->isOwnFile($user, $path);
        }

        return false;
    }

    /**
     * Decide if $user can read the ticket-attachment file at $path.
     *
     * Same access rule as TicketController::authorizeView(). The path lookup
     * spans all three attachment models because the ticket_attachments/
     * directory holds files from each:
     *   - TicketAttachment       (creation-time supporting docs, file_path)
     *   - TicketMessage          (legacy single-file chat, attachment_path)
     *   - TicketMessageAttachment (multi-file chat, file_path → message → ticket)
     */
    private function canAccessTicketFile($user, string $path): bool
    {
        if ($user->role === 'superadmin' || $user->role === 'system_admin') {
            return true;
        }

        $ticketId = \App\Models\TicketAttachment::where('file_path', $path)->value('ticket_id');

        if (! $ticketId) {
            $ticketId = \App\Models\TicketMessage::where('attachment_path', $path)->value('ticket_id');
        }

        if (! $ticketId) {
            $messageId = \App\Models\TicketMessageAttachment::where('file_path', $path)->value('message_id');
            if ($messageId) {
                $ticketId = \App\Models\TicketMessage::where('id', $messageId)->value('ticket_id');
            }
        }

        if (! $ticketId) {
            return false;
        }

        $ticket = \App\Models\Ticket::find($ticketId);
        if (! $ticket) {
            return false;
        }

        if ($ticket->user_id === $user->id || $ticket->assigned_to === $user->id) {
            return true;
        }

        return $user->canManageTicketsForDepartment($ticket->department);
    }

    /**
     * Decide if $user can read an expense-claim receipt/supporting file at $path.
     *
     * Same access set as ExpenseClaimController::viewReceipt()/authorizeReview():
     *   - superadmin / system_admin / HR (canViewAllClaims) — all claims
     *   - the claim owner
     *   - the claim's approving manager, or any item's approver
     *   - the employee's reporting manager
     */
    private function canAccessClaimFile($user, string $path): bool
    {
        if ($user->role === 'superadmin' || $user->role === 'system_admin') {
            return true;
        }
        if (method_exists($user, 'canViewAllClaims') && $user->canViewAllClaims()) {
            return true;
        }

        $item = \App\Models\ExpenseClaimItem::where(function ($q) use ($path) {
            $q->where('receipt_path', $path)
                ->orWhereJsonContains('receipt_paths', $path)
                ->orWhereJsonContains('supporting_paths', $path);
        })->with('claim.employee')->first();

        if (! $item || ! $item->claim) {
            return false;
        }

        $emp = $user->employee;
        if (! $emp) {
            return false;
        }
        $claim = $item->claim;

        // Owner of the claim.
        if ((int) $claim->employee_id === (int) $emp->id) {
            return true;
        }
        // The approving manager for the whole claim.
        if ((int) $claim->manager_id === (int) $emp->id) {
            return true;
        }
        // The employee's reporting manager.
        if ($claim->employee && (int) $claim->employee->manager_id === (int) $emp->id) {
            return true;
        }
        // A manager any item on the claim was routed to for approval (covers legacy split data).
        if ($claim->items()->where('approver_id', $emp->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine if a file belongs to the authenticated user's employee record.
     */
    private function isOwnFile($user, string $path): bool
    {
        $employee = $user->employee;
        if (! $employee) {
            return false;
        }

        // Check NRIC files
        $nricPaths = is_array($employee->nric_file_paths) ? $employee->nric_file_paths : json_decode($employee->nric_file_paths ?? '[]', true);
        if (in_array($path, $nricPaths ?: [])) {
            return true;
        }

        // Check contract files
        foreach ($employee->contracts ?? [] as $contract) {
            if ($contract->file_path === $path) {
                return true;
            }
        }

        // Check education certificate files
        foreach ($employee->educationHistories ?? [] as $edu) {
            $certPaths = is_array($edu->certificate_paths) ? $edu->certificate_paths : json_decode($edu->certificate_paths ?? '[]', true);
            if (in_array($path, $certPaths ?: [])) {
                return true;
            }
        }

        // Check leave attachment files
        foreach ($employee->leaveApplications ?? [] as $leave) {
            if ($leave->attachment_path === $path) {
                return true;
            }
        }

        // Check expense-claim receipt + supporting files (claim_receipts / claim_supporting).
        // Owned when the file belongs to an item on one of the employee's own claims.
        if (\App\Models\ExpenseClaimItem::where(function ($q) use ($path) {
            $q->where('receipt_path', $path)
                ->orWhereJsonContains('receipt_paths', $path)
                ->orWhereJsonContains('supporting_paths', $path);
        })
            ->whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id))
            ->exists()) {
            return true;
        }

        // Note: ticket_attachments/* are NOT handled here — hasAccess() routes
        // them to canAccessTicketFile() before reaching this method.

        return false;
    }
}
