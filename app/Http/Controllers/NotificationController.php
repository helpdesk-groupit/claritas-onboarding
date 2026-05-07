<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * JSON feed for the bell dropdown — returns the latest notifications
     * plus the unread count.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $notifications = $user->notifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($n) => [
                'id'         => $n->id,
                'data'       => $n->data,
                'read'       => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
                'time_ago'   => $n->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function markRead(Request $request, string $id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->where('id', $id)->first();
        if (!$notification) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $notification->markAsRead();

        return response()->json([
            'ok'           => true,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllRead(Request $request)
    {
        $user = Auth::user();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread_count' => 0]);
    }
}
