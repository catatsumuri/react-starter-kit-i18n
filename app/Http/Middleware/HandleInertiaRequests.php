<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use LaravelLang\Locales\Facades\Locales;
use LaravelLang\NativeLocaleNames\LocaleNames;

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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'lang' => $this->loadLangJson(),
            'locale' => app()->getLocale(),
            'localeName' => $this->currentLocaleName(),
            'locales' => $this->installedLocales(),
        ];
    }

    private function installedLocales(): array
    {
        return Locales::installed()
            ->mapWithKeys(function ($data) {
                $names = LocaleNames::get($data->code);

                return [$data->code => $names[$data->code] ?? $data->code];
            })
            ->all();
    }

    private function currentLocaleName(): string
    {
        $locale = app()->getLocale();

        return LocaleNames::get($locale)[$locale] ?? $locale;
    }

    private function loadLangJson(): array
    {
        $path = lang_path(app()->getLocale().'.json');

        return file_exists($path)
            ? json_decode(file_get_contents($path), true) ?? []
            : [];
    }
}
