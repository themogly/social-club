{{-- Prompt 310 — the panel's ONE request hook. A panel middleware that refuses a button press (a Livewire request) answers
     403 with `X-Csc-Reason` and `X-Csc-Location` (App\Support\PanelRefusal): go there — *Confirma tu identidad*, the counter,
     the no-sede page — instead of Livewire's raw error box. Any other failure is Livewire's as before. --}}
<script data-panel-refusal-hook>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ respond, fail }) => {
            let to = null;
            respond(({ response }) => {
                if (response.headers.get('X-Csc-Reason')) to = response.headers.get('X-Csc-Location');
            });
            fail(({ preventDefault }) => {
                if (! to) return;
                preventDefault();
                window.location.assign(to);
            });
        });
    });
</script>
