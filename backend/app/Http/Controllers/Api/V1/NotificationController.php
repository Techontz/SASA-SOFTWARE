<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread_only')) {
            $query = $request->user()->unreadNotifications();
        }

        $notifications = $query->paginate(min(50, (int) $request->input('per_page', 20)));

        return ApiResponse::data(
            $notifications->through(fn ($notification) => [
                'id' => $notification->id,
                'event' => $notification->data['event'] ?? null,
                'subject' => $notification->data['subject'] ?? null,
                'body' => $notification->data['body'] ?? null,
                'severity' => $notification->data['severity'] ?? 'info',
                'url' => $notification->data['url'] ?? null,
                'reference' => $notification->data['reference'] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
            ]),
            ['unread_count' => $request->user()->unreadNotifications()->count()]
        );
    }

    public function unreadCount(Request $request)
    {
        return ApiResponse::data(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return ApiResponse::data(['id' => $id, 'read_at' => $notification->fresh()->read_at?->toIso8601String()]);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::message('All notifications marked as read.');
    }
}
