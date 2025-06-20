<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Notifications\DatabaseNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tokenId = auth()->user()->currentAccessToken()->id;

        return response()->json([
            'unread' => Notification::whereUserId($tokenId)
                ->whereNull('read_at')->latest()->get(),
            'read' => Notification::whereUserId($tokenId)
                ->whereNotNull('read_at')->latest()->take(5)->get()
        ]);
    }


    public function markAsRead(Notification $notification)
    {
        $notification->markAsRead();
        return response()->json(['status' => 'marked as read']);
    }

    public function destroy(Notification $notification)
    {
        $notification->delete();
        return response()->noContent();
    }

    public function store(Request $request)
    {
        $notification = [
            'message' => 'New order received',
            'url' => '/orders/123',
            'icon' => 'bell'
        ];

        $request->user()->notify(
            new DatabaseNotification($notification)
        );

        return response()->json(['status' => 'Notification sent']);
    }

}
