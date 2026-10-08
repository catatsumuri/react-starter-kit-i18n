<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'notifications' => fn (): array => $this->notificationFeed($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'lang' => $this->loadLangJson(),
        ];
    }

    /**
     * @return array{
     *     items: array<int, array{
     *         id: string,
     *         title: string,
     *         body: string,
     *         action_url: string|null,
     *         read_at: string|null,
     *         created_at: string|null
     *     }>,
     *     unread_count: int
     * }
     */
    private function notificationFeed(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [
                'items' => [],
                'unread_count' => 0,
            ];
        }

        $items = $user->notifications()
            ->limit(10)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                $actionUrl = $notification->data['action_url'] ?? null;

                return [
                    'id' => $notification->id,
                    'title' => (string) ($notification->data['title'] ?? ''),
                    'body' => (string) ($notification->data['body'] ?? ''),
                    'action_url' => is_string($actionUrl) ? $actionUrl : null,
                    'read_at' => $notification->read_at?->toISOString(),
                    'created_at' => $notification->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return [
            'items' => $items,
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLangJson(): array
    {
        $path = lang_path(app()->getLocale().'.json');

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        return json_decode($contents, true) ?? [];
    }
}
