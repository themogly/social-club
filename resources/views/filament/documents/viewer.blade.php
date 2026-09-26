{{-- Prompt 252 — an Article-9 document or a signature shown INSIDE a Filament modal, never a new tab or a
     window.open. The `$url` is the same short-lived, access-logged signed URL IssueDocumentUrl / VaultUrl
     already issues; the modal does not extend its lifetime. Images render inline; a PDF renders in an iframe
     with one same-tab fallback ("Abrir en el visor") for a device with no inline PDF viewer. --}}
<div class="space-y-3">
    @if ($isPdf)
        <iframe
            src="{{ $url }}"
            title="{{ __('Documento') }}"
            class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
            style="height: 70vh;"
        ></iframe>

        {{-- The one case a modal cannot render: open in the SAME tab (never a new one). --}}
        <a href="{{ $url }}" class="fi-link inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
            {{ __('Abrir en el visor') }}
        </a>
    @else
        <img
            src="{{ $url }}"
            alt="{{ __('Documento') }}"
            class="mx-auto max-h-[70vh] w-auto rounded-lg"
        >
    @endif
</div>
