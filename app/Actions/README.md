# App\Actions

Single-purpose Action classes — one job each, invoked from controllers, Livewire
components, Filament resources, commands. Business logic lives here (or in fat
models), never in controllers. Compliance-critical Actions (dispensation, wallet
movements, till close) wrap their checks and the stock/ledger write in one DB
transaction. See CLAUDE.md → Architecture rules.
