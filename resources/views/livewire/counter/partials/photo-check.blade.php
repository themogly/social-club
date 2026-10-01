{{-- Prompt 348 — a card was scanned: the photo, large, BEFORE anything else (Ben: "bring a picture up when they scan the QR
     code"). Shared by every screen that finds a member (it lives in the member lookup). «No es esta persona» selects
     nobody, audits `member.card_misuse_suspected` and counts it on the member's record. --}}
@if ($checkMember = $this->photoCheckMember())
    @php($photoUrl = \App\Support\VaultUrl::photo($checkMember, auth()->user(), \App\Support\CounterOperator::id()))
    {{-- An overlay over the screen (whatever is behind it — a scan can arrive with a member already open), with Android Back
         closing it before it leaves the page (`historyDialog`, CLAUDE.md): Back selects nobody and records nothing. --}}
    <div class="fixed inset-0 z-40 flex items-center justify-center overflow-y-auto bg-ink/50 p-4"
         x-data="historyDialog('photo-check', () => $wire.cancelPhotoCheck())">
    <section data-photo-check role="alertdialog" aria-modal="true" aria-labelledby="photo-check-name"
             class="flex w-full max-w-lg flex-col items-center gap-4 rounded-2xl border-2 border-brand/40 bg-surface p-5 text-center shadow-xl dark:border-slate-600 dark:bg-slate-900">
        <p class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ __('¿Es esta persona?') }}</p>
        @if ($photoUrl)
            <img src="{{ $photoUrl }}" alt="{{ __('Foto de :name', ['name' => $checkMember->fullName()]) }}" data-photo-check-photo
                 class="h-60 w-60 rounded-2xl object-cover sm:h-72 sm:w-72">
        @else
            <div data-photo-check-no-photo class="flex h-60 w-60 flex-col items-center justify-center rounded-2xl border border-warning/40 bg-warning/10 text-warning sm:h-72 sm:w-72">
                <x-counter.icon name="camera" class="h-10 w-10" />
                <span class="mt-2 text-sm font-semibold">{{ __('Sin foto') }}</span>
            </div>
        @endif
        <div>
            <p id="photo-check-name" class="text-xl font-bold text-ink dark:text-slate-100">{{ $checkMember->fullName() }}</p>
            <p class="text-sm text-ink-muted dark:text-slate-400">{{ $checkMember->member_no }}</p>
            @if ((int) $checkMember->card_misuse_count > 0)
                <p data-card-misuse class="mt-1 text-sm font-semibold text-warning">{{ __('Tarjeta usada por otra persona: :count', ['count' => (int) $checkMember->card_misuse_count]) }}</p>
            @endif
        </div>
        <div class="grid w-full max-w-md grid-cols-2 gap-3">
            <x-button type="button" variant="secondary" size="lg" data-photo-check-no wire:click="rejectPhotoCheck">{{ __('No es esta persona') }}</x-button>
            <x-button type="button" variant="primary" size="lg" data-photo-check-yes wire:click="confirmPhotoCheck">{{ __('Sí, es esta persona') }}</x-button>
        </div>
    </section>
    </div>
@endif
