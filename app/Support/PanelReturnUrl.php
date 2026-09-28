<?php

namespace App\Support;

use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where the panel lands after the active scope changes (prompt 284): back on the page the person was on.
 *
 * It is a redirect target, so it is only ever followed when it is a PANEL page on THIS host — it must resolve to a
 * `filament.admin.*` route (the panel's path is '' so a path prefix proves nothing: `/counter`, `/socio`, Livewire's
 * endpoint and every other non-panel route are refused by name, not by guessing prefixes). Anything else — another
 * host, a protocol-relative `//host`, a `javascript:` URL, a counter or member-app page — falls back to the dashboard.
 *
 * A record page (edit, view, or any resource page that holds a `{record}`) whose record the NEW scope hides would
 * 404 on reload, so it lands on that resource's list instead. That check runs under the scope just applied.
 */
class PanelReturnUrl
{
    /**
     * The page to return to: the URL captured at mount, OR the browser's current address when it is that SAME page
     * (same scheme, host, port and path) — Filament writes a table search, filter or tab into the address bar AFTER the
     * page loaded, so the mount-time URL would drop them. The browser's value can therefore only change the query
     * string of the page the switcher was mounted on; any other value is ignored in favour of the mounted one.
     */
    public static function samePage(string $mounted, ?string $current): string
    {
        $a = parse_url($mounted);
        $b = parse_url((string) $current);

        if (! is_array($a) || ! is_array($b)) {
            return $mounted;
        }

        foreach (['scheme', 'host', 'port', 'path', 'user', 'pass'] as $part) {
            if (($a[$part] ?? null) !== ($b[$part] ?? null)) {
                return $mounted;
            }
        }

        return (string) $current;
    }

    public static function after(?string $url): string
    {
        $home = url('/');
        $parts = parse_url((string) $url);
        $app = parse_url($home);

        if (! is_array($parts) || ! is_array($app)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ($parts['host'] ?? null) !== ($app['host'] ?? null)
            || ($parts['port'] ?? null) !== ($app['port'] ?? null)
            || str_starts_with($parts['path'] ?? '/', '//')
            || str_contains((string) $url, '\\')) {
            return $home;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create((string) $url, 'GET'));
        } catch (Throwable) {
            return $home;
        }

        if (! str_starts_with((string) $route->getName(), 'filament.admin.')) {
            return $home;
        }

        $page = $route->getControllerClass(); // null for a closure route

        if ($page !== null && is_subclass_of($page, Page::class) && in_array(InteractsWithRecord::class, class_uses_recursive($page), true)) {
            $resource = $page::getResource();
            $key = $route->parameter('record');

            if (! is_string($key) || $resource::resolveRecordRouteBinding($key) === null) {
                return $resource::hasPage('index') ? $resource::getUrl('index') : $home;
            }
        }

        return (string) $url;
    }
}
