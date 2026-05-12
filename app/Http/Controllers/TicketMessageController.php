<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use App\Mail\TicketNewMessageMail;
use App\Notifications\NewTicketMessageNotification;
use App\Services\AttachmentProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class TicketMessageController extends Controller
{
    /**
     * Poll endpoint — returns messages for the ticket, optionally only those
     * after a given message id (used by the chat UI to fetch new messages).
     */
    public function index(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorizeAccess($user, $ticket);

        $afterId = (int) $request->query('after_id', 0);

        $messages = $ticket->messages()
            ->with(['sender:id,name,role,profile_picture', 'attachments'])
            ->when($afterId > 0, fn($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn(TicketMessage $m) => $this->serialize($m));

        return response()->json([
            'messages'    => $messages,
            'last_id'     => $messages->last()['id'] ?? $afterId,
            'ticket'      => [
                'status'      => $ticket->status,
                'assigned_to' => $ticket->assigned_to,
            ],
        ]);
    }

    /**
     * Post a new message (text and/or file attachments — multi-file supported).
     */
    public function store(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorizeAccess($user, $ticket);

        if ($ticket->isArchivedStatus()) {
            return response()->json(['error' => 'This ticket is archived (resolved or closed).'], 422);
        }

        $request->validate([
            'message'        => 'nullable|string|max:5000',
            'attachments'    => 'nullable|array|max:10',
            'attachments.*'  => 'file|max:10240|mimes:pdf,jpg,jpeg,png,gif,webp|valid_file_content',
        ]);

        $hasMessage     = !empty(trim((string) $request->input('message')));
        $hasAttachments = $request->hasFile('attachments');

        if (!$hasMessage && !$hasAttachments) {
            return response()->json(['error' => 'Message or attachment is required.'], 422);
        }

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id'   => $user->id,
            'message'   => $request->input('message'),
            // Legacy single-attachment columns left null for new messages.
        ]);

        // Process and store each uploaded file as a TicketMessageAttachment row.
        if ($hasAttachments) {
            foreach ($request->file('attachments') as $file) {
                $meta = AttachmentProcessor::store(
                    $file,
                    'ticket_attachments',
                    $message->id . '_msg_'
                );
                TicketMessageAttachment::create(array_merge(['message_id' => $message->id], $meta));
            }
        }

        // Notify the OTHER participants — raiser + PIC, never the sender.
        // Same recipient set drives both the in-app bell and the email so they
        // stay in sync; an email-only recipient with no User row isn't expected
        // here (raiser + PIC are always Users) so no Employee fallback needed.
        $recipientIds = collect([$ticket->user_id, $ticket->assigned_to])
            ->filter()
            ->unique()
            ->reject(fn($id) => $id === $user->id)
            ->values();

        // Manager-loop fallback for un-PIC'd tickets: when the raiser replies
        // on a ticket that has no assigned PIC, the recipient set would
        // otherwise be empty (the raiser can't notify themselves) — so the
        // conversation goes silent on the team side. Bring the dept's manager
        // pool in so someone with management rights sees the reply and can
        // pick the ticket up as PIC. Only triggers on this specific shape so
        // an already-active manager-to-raiser thread doesn't get spammed.
        if (empty($ticket->assigned_to) && (int) $ticket->user_id === (int) $user->id) {
            $managerIds = $ticket->managersForNotification()
                ->pluck('id')
                ->reject(fn($id) => (int) $id === (int) $user->id);
            $recipientIds = $recipientIds->merge($managerIds)->unique()->values();
        }

        if ($recipientIds->isNotEmpty()) {
            $recipients = User::whereIn('id', $recipientIds)->get();
            Notification::send(
                $recipients,
                new NewTicketMessageNotification($ticket, $message, $user)
            );

            // Email after the HTTP response is returned, mirroring the proven
            // AnnouncementController pattern. Uses Mail::send() (not queue())
            // because terminating() already moves the work out of the request
            // path, and ->send() works reliably regardless of queue config.
            // Pass the already-resolved recipient ids in via `use` so the email
            // dispatch matches the bell exactly (including the manager-loop
            // fallback above) — no re-derivation inside the closure.
            $ticketId       = $ticket->id;
            $messageId      = $message->id;
            $senderId       = $user->id;
            $recipientIdSet = $recipientIds->all();
            app()->terminating(function () use ($ticketId, $messageId, $senderId, $recipientIdSet) {
                $ticket  = \App\Models\Ticket::find($ticketId);
                $message = \App\Models\TicketMessage::find($messageId);
                $sender  = User::find($senderId);
                if (!$ticket || !$message || !$sender || empty($recipientIdSet)) return;

                foreach (User::whereIn('id', $recipientIdSet)->get() as $recipient) {
                    if (empty($recipient->work_email)) continue;
                    try {
                        \Illuminate\Support\Facades\Log::info(
                            "Ticket chat email firing — to: {$recipient->work_email}, ticket: {$ticket->ticket_number}"
                        );
                        Mail::to($recipient->work_email)->send(
                            new TicketNewMessageMail($ticket, $message, $sender, $recipient)
                        );
                        \Illuminate\Support\Facades\Log::info(
                            "Ticket chat email sent — to: {$recipient->work_email}, ticket: {$ticket->ticket_number}"
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning(
                            "Ticket chat email failed for user #{$recipient->id} on ticket {$ticket->ticket_number}: " . $e->getMessage()
                        );
                    }
                }
            });
        }

        return response()->json([
            'message' => $this->serialize($message->load(['sender:id,name,role,profile_picture', 'attachments'])),
            'ticket'  => [
                'status' => $ticket->fresh()->status,
            ],
        ], 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Serialise a message for the chat JSON feed. Normalises BOTH the legacy
     * single-attachment columns AND the new multi-attachment table into one
     * `attachments` array so the JS just iterates one shape.
     */
    private function serialize(TicketMessage $m): array
    {
        $attachments = [];

        // Legacy single attachment (messages from before multi-file support)
        if ($m->hasAttachment()) {
            $attachments[] = [
                'url'      => $m->attachmentUrl(),
                'name'     => $m->attachment_original_name,
                'mime'     => $m->attachment_mime,
                'is_image' => $m->isImageAttachment(),
            ];
        }

        // New multi-attachment rows
        foreach ($m->attachments as $att) {
            $attachments[] = [
                'url'      => $att->url(),
                'name'     => $att->original_name,
                'mime'     => $att->mime,
                'is_image' => $att->is_image,
            ];
        }

        return [
            'id'            => $m->id,
            'message'       => $m->message,
            'sender_id'     => $m->user_id,
            'sender_name'   => $m->sender?->name ?? 'Unknown',
            'sender_role'   => $m->sender?->role,
            'sender_avatar' => $m->sender?->profile_picture_url,
            'is_mine'       => $m->user_id === Auth::id(),
            'created_at'    => $m->created_at?->toIso8601String(),
            'created_human' => $m->created_at?->diffForHumans(),
            'created_time'  => $m->created_at?->setTimezone(config('app.timezone'))->format('M j, g:i a'),
            'attachments'   => $attachments,
        ];
    }

    private function authorizeAccess(User $user, Ticket $ticket): void
    {
        if ($user->isSuperadmin() || $user->isSystemAdmin()) {
            return;
        }
        if ($ticket->user_id === $user->id || $ticket->assigned_to === $user->id) {
            return;
        }
        if ($user->canManageTicketsForDepartment($ticket->department)) {
            return;
        }
        abort(403);
    }
}
