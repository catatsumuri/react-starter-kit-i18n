<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MarkNotificationAsReadController extends Controller
{
    public function __invoke(Request $request, string $notification): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $databaseNotification = $user->notifications()->findOrFail($notification);
        $databaseNotification->markAsRead();

        $actionUrl = $databaseNotification->data['action_url'] ?? null;

        if (! is_string($actionUrl) || $actionUrl === '') {
            return back();
        }

        if (str_starts_with($actionUrl, '/') && ! str_starts_with($actionUrl, '//')) {
            return redirect($actionUrl);
        }

        if (filter_var($actionUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($actionUrl, PHP_URL_SCHEME) === 'https') {
            return Inertia::location($actionUrl);
        }

        return back();
    }
}
