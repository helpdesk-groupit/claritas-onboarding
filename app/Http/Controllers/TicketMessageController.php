<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use App\Notifications\NewTicketMessageNotification;
use App\Services\AttachmentProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // In-app notify the OTHER participants (never the sender themselves).
        $recipientIds = collect([$ticket->user_id, $ticket->assigned_to])
            ->filter()
            ->unique()
            ->reject(fn($id) => $id === $user->id)
            ->values();

        if ($recipientIds->isNotEmpty()) {
            $recipients = User::whereIn('id', $recipientIds)->get();
            Notification::send(
                $recipients,
                new NewTicketMessageNotification($ticket, $message, $user)
            );
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
