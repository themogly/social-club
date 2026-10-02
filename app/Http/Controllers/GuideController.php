<?php

namespace App\Http\Controllers;

use App\Actions\ResolveLocale;
use App\Models\User;
use App\Support\Guides\DocsAccess;
use App\Support\Guides\Guide;
use App\Support\Guides\GuideLibrary;
use App\Support\Guides\GuidePdf;
use App\Support\Guides\GuideRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 353 — the guides, wherever they are read: the counter (⋯ → Guías, its own layout), a staff phone on `/docs`
 * (the guides-only cookie), and — for the images and the PDF — the panel's Manual too. Thin: who may read what is
 * {@see GuideLibrary}; the guides-only access is {@see DocsAccess}.
 */
class GuideController extends Controller
{
    // --- The counter ------------------------------------------------------------------------------------------------------

    public function counterIndex(): View|RedirectResponse
    {
        $reader = $this->counterReader();
        if ($reader === null) {
            return redirect()->route('counter.home'); // the PIN first — the hub shows the pad
        }

        return view('guides.counter', ['guides' => GuideLibrary::visibleTo($reader), 'guide' => null]);
    }

    public function counterShow(string $guide): View|RedirectResponse
    {
        $reader = $this->counterReader();
        if ($reader === null) {
            return redirect()->route('counter.home');
        }

        return view('guides.counter', ['guides' => [], 'guide' => $this->page($this->readable($guide, $reader))]);
    }

    // --- /docs: a staff phone, by PIN ---------------------------------------------------------------------------------------

    public function docs(Request $request): View
    {
        abort_unless(DocsAccess::enabled(), 404);
        $reader = $this->docsReader($request);

        return $reader === null
            ? view('guides.docs-pin', ['lockedOut' => DocsAccess::lockedOut((string) $request->ip())])
            : view('guides.docs', ['reader' => $reader, 'guides' => GuideLibrary::visibleTo($reader), 'guide' => null]);
    }

    public function docsSignIn(Request $request): RedirectResponse
    {
        abort_unless(DocsAccess::enabled(), 404);
        $user = DocsAccess::attempt($request, (string) $request->input('pin', ''));

        return $user === null
            ? redirect()->route('guides.docs')->with('docs_error', true)
            : redirect()->route('guides.docs')->withCookie(DocsAccess::issue($user));
    }

    public function docsShow(Request $request, string $guide): View|RedirectResponse
    {
        abort_unless(DocsAccess::enabled(), 404);
        $reader = $this->docsReader($request);
        if ($reader === null) {
            return redirect()->route('guides.docs');
        }

        return view('guides.docs', ['reader' => $reader, 'guides' => [], 'guide' => $this->page($this->readable($guide, $reader))]);
    }

    public function docsSignOut(): RedirectResponse
    {
        return redirect()->route('guides.docs')->withCookie(DocsAccess::forget());
    }

    // --- Images and the PDF: any reader allowed to read that guide ----------------------------------------------------------

    public function image(Request $request, string $guide, string $file): BinaryFileResponse
    {
        $this->readable($guide, $this->anyReader($request));
        $path = GuideLibrary::imagePath($guide, $file);
        abort_if($path === null, 404);

        // The URL carries the image's hash (?v=…), so it can be cached for a year; private — it sits behind a sign-in.
        return response()->file($path, ['Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    public function pdf(Request $request, string $guide): Response
    {
        $readable = $this->readable($guide, $this->anyReader($request));

        return response(GuidePdf::bytes($readable), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$readable->slug.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // --- Helpers --------------------------------------------------------------------------------------------------------------

    /** On the counter the reader is the person signed in by PIN (267); a terminal with nobody at the PIN has none. */
    private function counterReader(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    /** The phone's reader, whose own language the pages then speak (the one locale rule, ResolveLocale). */
    private function docsReader(Request $request): ?User
    {
        $reader = DocsAccess::reader($request);
        if ($reader !== null) {
            app()->setLocale((new ResolveLocale)->handle($reader));
        }

        return $reader;
    }

    /** The panel or counter person, else the phone's guides-only cookie. */
    private function anyReader(Request $request): ?User
    {
        return $this->counterReader() ?? DocsAccess::reader($request);
    }

    /** The guide, if this reader may read it — a 404 otherwise, so a staff member cannot even learn a managers' slug. */
    private function readable(string $slug, ?User $reader): Guide
    {
        $guide = GuideLibrary::find($slug);
        abort_if($guide === null || ! GuideLibrary::canRead($reader, $guide), $reader === null ? 403 : 404);

        return $guide;
    }

    /** @return array{guide: Guide, html: string, toc: list<array{id: string, title: string}>} */
    private function page(Guide $guide): array
    {
        return ['guide' => $guide] + GuideRenderer::render($guide, fn (string $file): string => GuideLibrary::imageUrl($guide, $file));
    }
}
