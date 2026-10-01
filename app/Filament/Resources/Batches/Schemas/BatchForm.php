<?php

namespace App\Filament\Resources\Batches\Schemas;

use App\Enums\ProductType;
use App\Filament\Forms\CameraOrFile;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\AllOption;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Rules\GramAmount;
use App\Support\ActiveScope;
use App\Support\DocumentUpload;
use App\Support\Money;
use App\Support\Weight;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class BatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Lote'))
                    ->schema([
                        // WHERE the stock lands (prompt 238). Stock always belongs to a sede — it drives the
                        // per-premises legal ceiling and the registro de dispensación — so intake must NAME it
                        // rather than inherit an invisible scope. Defaults to the sede in the topbar; BLANK in
                        // the "all sedes" rollup so an owner picks deliberately (never a guessed first row,
                        // prompt 148); disabled and pre-filled when the club has a single sede, where there is
                        // nothing to choose. Fixed at intake, like the genetic — disabled once the batch exists.
                        // Prompt 303 — on CREATE with more than one location, a multi-choice (*Sedes*, stores included), "all"
                        // first, exactly like 297's products: one lote, a part at each location ticked, each with its own
                        // quantity. On edit a batch is one part at one location; moving stock later is *Trasladar* (302).
                        Select::make('location_id')
                            ->label(fn (string $operation): string => self::choosesSedes($operation) ? __('Sedes') : __('Sede'))
                            ->multiple(fn (string $operation): bool => self::choosesSedes($operation))
                            ->options(fn (string $operation): array => self::choosesSedes($operation) ? self::sedeChoices() : Location::assignableOptions(includeStores: true)) // stock may be received at the store (277)
                            ->default(fn (string $operation): string|array|null => self::defaultSede($operation))
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Select $component, mixed $state, mixed $old, string $operation): void {
                                if (self::choosesSedes($operation)) {
                                    $component->state(AllOption::sync((array) $state, (array) $old, array_keys(Location::assignableOptions(includeStores: true))));
                                }
                            })
                            ->disabled(fn (string $operation): bool => $operation !== 'create' || self::singleSede())
                            // A disabled field is not submitted by default; the single-sede value still must be.
                            ->dehydrated()
                            ->helperText(fn (string $operation): ?string => $operation === 'create' && ! self::singleSede()
                                ? __('Donde entra el stock. Después se puede trasladar con «Trasladar».')
                                : null),

                        // Prompt 323 (Ben) — "you should first select the type, as it's hard to know what you're adding to".
                        // A FILTER for the strain below, never stored: the batch takes its type from its strain, as before.
                        Select::make('product_type')
                            ->label(__('Tipo de producto'))
                            ->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $case): array => [$case->value => $case->label()])->all())
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                $chosen = filled($get('genetic_id')) ? Genetic::query()->find($get('genetic_id')) : null;
                                if ($chosen !== null && $chosen->product_type->value !== $state) {
                                    $set('genetic_id', null); // a strain of the old type no longer fits
                                }
                            })
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        Select::make('genetic_id')
                            ->label(__('Genética'))
                            // On create, only ACTIVE strains of the chosen type; on edit the strain is fixed and shown as is.
                            ->relationship('genetic', 'name', modifyQueryUsing: fn (Builder $query, Get $get, string $operation): Builder => $operation === 'create'
                                ? $query->where('active', true)->where('product_type', (string) $get('product_type'))
                                : $query)
                            ->getOptionLabelFromRecordUsing(fn (Genetic $record): string => $record->pickerLabel())
                            ->searchable(['name'])
                            ->preload()
                            ->required()
                            ->live()
                            ->placeholder(fn (string $operation, Get $get): ?string => $operation === 'create' && blank($get('product_type')) ? __('Elige primero el tipo') : null)
                            // A strain of another type (or a retired one) is refused, whatever the form was made to submit.
                            ->rule(fn (string $operation, Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($operation, $get): void {
                                if ($operation === 'create' && filled($value) && ! Genetic::query()->whereKey($value)->where('active', true)->where('product_type', (string) $get('product_type'))->exists()) {
                                    $fail(__('Elige una genética del tipo seleccionado.'));
                                }
                            })
                            // The strain is fixed at intake — never reassign an existing batch; and on create it waits for the type.
                            ->disabled(fn (string $operation, Get $get): bool => $operation !== 'create' || blank($get('product_type'))),

                        // Prompt 282 — the club's own name for the batch. Free, not unique (one harvest across several
                        // strains shares it), editable at any time; a rename reaches every part of the lote. The lote
                        // number stays the fixed traceability key and is not a field.
                        TextInput::make('label')
                            ->label(__('Nombre'))
                            ->maxLength(60)
                            ->helperText(__('Como lo llamáis vosotros, p. ej. «Cosecha verano 2026». Opcional.')),

                        // Prompt 298 — the grow's or supplier's own lote number, if there is one; left empty, a readable one is
                        // generated (AMN-260912-3). Only at intake: the lote number is the fixed traceability key.
                        TextInput::make('batch_no')
                            ->label(__('Nº de lote propio'))
                            ->maxLength(40)
                            ->helperText(__('El número del cultivo o del proveedor, si lo tiene. Si lo dejas vacío, se genera uno.'))
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        // On the batch's own page the lote number is shown clearly, with a copy button.
                        TextEntry::make('lote_number')
                            ->label(__('Nº de lote'))
                            ->state(fn (?Batch $record): ?string => $record?->batch_no)
                            ->copyable()
                            ->copyMessage(__('Copiado'))
                            ->icon(Heroicon::OutlinedClipboardDocument) // the visible "copy" cue: a click copies it
                            ->iconPosition(IconPosition::After)
                            ->tooltip(__('Copiar'))
                            ->weight(FontWeight::SemiBold)
                            ->visible(fn (string $operation): bool => $operation !== 'create'),

                        // Prompt 305 — every location holding a part of this lote, with what is left there and a link: after each
                        // club visit the owner sees where the lote stands everywhere. Read-only.
                        TextEntry::make('lote_parts')
                            ->label(__('Partes del lote'))
                            ->state(fn (?Batch $record): ?HtmlString => $record === null ? null : self::lotePartsList($record))
                            ->html()
                            ->columnSpanFull()
                            ->visible(fn (string $operation): bool => $operation !== 'create'),

                        // Prompt 302 — what is left here, so a move from this page (the header's *Asignar a sede / Trasladar*)
                        // shows its effect at once. Read-only: stock moves only through the ledger.
                        TextEntry::make('remaining_display')
                            ->label(__('Restante'))
                            ->state(fn (?Batch $record): ?string => $record === null ? null : ($record->isUnitType()
                                ? __(':count uds', ['count' => (int) $record->remaining_units])
                                : $record->remaining_cg->formatted()))
                            ->extraAttributes(['data-batch-remaining' => ''])
                            ->visible(fn (string $operation): bool => $operation !== 'create'),

                        // Intake quantity — only at creation, and in the genetic's own unit:
                        // grams for a WEIGHT genetic, whole units for a UNIT genetic. Stock
                        // thereafter moves solely through the ledger (Ajuste / Merma), never a free edit.
                        // With several locations ticked (303) this is the TOTAL to share out with *Repartir a partes iguales*;
                        // what is saved is each location's own quantity below.
                        DecimalInput::make('grams')
                            ->label(fn (Get $get): string => self::isSplit($get) ? __('Cantidad total (g)') : __('Cantidad (g)'))
                            ->numeric()
                            ->rule(new GramAmount)
                            ->minValue(0)
                            ->required(fn (Get $get): bool => ! self::isUnitGenetic($get('genetic_id')) && ! self::isSplit($get))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ! self::isUnitGenetic($get('genetic_id'))),

                        TextInput::make('units')
                            ->label(fn (Get $get): string => self::isSplit($get) ? __('Cantidad total (uds)') : __('Cantidad (uds)'))
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->required(fn (Get $get): bool => self::isUnitGenetic($get('genetic_id')) && ! self::isSplit($get))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && self::isUnitGenetic($get('genetic_id'))),

                        // Prompt 303 — *Repartir a partes iguales* shares the total above over the locations ticked; then one
                        // quantity per location, and their live total.
                        Actions::make([
                            Action::make('splitEqually')
                                ->label(__('Repartir a partes iguales'))
                                ->icon(Heroicon::OutlinedScale)
                                ->color('gray')
                                ->action(fn ($livewire) => $livewire->splitEqually()),
                        ])->visible(fn (string $operation, Get $get): bool => $operation === 'create' && self::isSplit($get)),
                        Grid::make(['default' => 1, 'sm' => 2])->columnSpanFull()
                            ->schema(fn (Get $get): array => self::partFields($get))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && self::isSplit($get)),
                        TextEntry::make('split_total')
                            ->label(__('Total'))
                            ->state(fn (Get $get): string => self::splitTotal($get))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && self::isSplit($get)),

                        DecimalInput::make('cost_per_gram_eur')
                            ->label(__('Coste por gramo (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        // Prompt 278 (Ben's 271) — the SALE price is the batch's: required at intake, so no batch is ever
                        // received unpriced. Changed later only through the audited "Precio" action (prices.manage).
                        DecimalInput::make('sale_price_eur')
                            ->label(fn (Get $get): string => self::isUnitGenetic($get('genetic_id')) ? __('Precio por unidad (€)') : __('Precio por gramo (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        DecimalInput::make('price_per_eighth_eur')
                            ->label(__('Precio por octavo — 3.5 g (€)'))
                            ->helperText(__('Opcional.'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ! self::isUnitGenetic($get('genetic_id'))),

                        // Prompt 340 — on the batch's page, the CURRENT price, read-only (changed only through the audited
                        // Precio), and the way to change it right here.
                        TextEntry::make('current_price')
                            ->label(__('Precio'))
                            ->state(fn (?Batch $record): HtmlString => new HtmlString(self::currentPriceHtml($record)))
                            ->extraAttributes(['data-batch-current-price' => 'true'])
                            ->visible(fn (string $operation): bool => $operation === 'edit'),

                        DatePicker::make('acquired_or_harvested_on')
                            ->label(__('Fecha de adquisición/cosecha')),

                        DatePicker::make('expires_on')
                            ->label(__('Caducidad')),

                        CameraOrFile::field(FileUpload::make('lab_report_path')
                            ->label(__('Informe de laboratorio'))
                            ->disk('documents')
                            ->getUploadedFileUsing(DocumentUpload::withoutDirectUrl())
                            ->visibility('private')
                            ->maxSize(DocumentUpload::maxKilobytes())
                            ->helperText(DocumentUpload::helperText()), camera: 'environment', accept: 'image/*,application/pdf'),

                        // Photos of THIS harvest (278) — shown on the members' menu and the counter; public disk (a product
                        // photo is not personal data), resized on upload so the menu stays light.
                        CameraOrFile::field(FileUpload::make('images')
                            ->label(__('Fotos del lote'))
                            ->image()
                            ->imageEditor()
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1200')
                            ->imageResizeTargetHeight('1200')
                            ->disk('public')
                            ->directory('batches')
                            ->multiple()
                            ->columnSpanFull(), camera: 'environment'),

                        Textarea::make('notes')
                            ->label(__('Notas'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /** Is the currently-selected genetic dispensed by unit (preroll/edible)? */
    private static function isUnitGenetic(?string $geneticId): bool
    {
        return $geneticId !== null && (Genetic::query()->find($geneticId)?->isUnitType() ?? false);
    }

    /** On create with more than one location to choose from, the location is a multi-choice (303). */
    private static function choosesSedes(string $operation): bool
    {
        return $operation === 'create' && ! self::singleSede();
    }

    /** @return array<string, string> "all" (the owner's *Todas las sedes*, a manager's *Tus sedes*) + each location */
    private static function sedeChoices(): array
    {
        $sedes = Location::assignableOptions(includeStores: true);

        return count($sedes) > 1 ? [AllOption::KEY => ArticleForm::allLabel()] + $sedes : $sedes;
    }

    /** @return string|list<string>|null */
    private static function defaultSede(string $operation): string|array|null
    {
        $active = app(ActiveScope::class)->locationId();
        if (! self::choosesSedes($operation)) {
            return $active;
        }

        return $active !== null && array_key_exists($active, Location::assignableOptions(includeStores: true)) ? [$active] : [];
    }

    /** @return list<string> the locations ticked (without "all") */
    public static function chosenSedes(Get $get): array
    {
        return AllOption::chosen($get('location_id'));
    }

    private static function isSplit(Get $get): bool
    {
        return count(self::chosenSedes($get)) > 1;
    }

    /** @return list<TextInput> one quantity per location ticked, in the strain's unit, each more than zero */
    private static function partFields(Get $get): array
    {
        return self::partFieldsFor(self::chosenSedes($get), self::isUnitGenetic($get('genetic_id')));
    }

    /**
     * One quantity per location — 303's boxes, shared with 305's *Añadir existencias en otra sede*.
     *
     * @param  list<string>  $locationIds
     * @return list<TextInput>
     */
    public static function partFieldsFor(array $locationIds, bool $unit): array
    {
        $names = Location::query()->withoutGlobalScopes()->whereIn('id', $locationIds)->pluck('name', 'id');

        return array_map(fn (string $id): TextInput => DecimalInput::make(($unit ? 'units_at.' : 'grams_at.').$id)
            ->label(($unit ? __('Unidades en :sede', ['sede' => $names[$id] ?? '']) : __('Gramos en :sede', ['sede' => $names[$id] ?? ''])))
            ->numeric()
            ->required()
            ->live(onBlur: true)
            ->rules($unit ? ['integer', 'gt:0'] : ['gt:0', new GramAmount])
            ->validationMessages([
                'gt' => __('Cada sede necesita una cantidad mayor que cero; quita la sede que no recibe nada.'),
                'required' => __('Cada sede necesita una cantidad mayor que cero; quita la sede que no recibe nada.'),
            ]), $locationIds);
    }

    /** "1000,00 g" / "40 uds" — the live sum of the parts. */
    private static function splitTotal(Get $get): string
    {
        return self::splitTotalFor($get, self::chosenSedes($get), self::isUnitGenetic($get('genetic_id')));
    }

    /** @param  list<string>  $locationIds */
    public static function splitTotalFor(Get $get, array $locationIds, bool $unit): string
    {
        $values = array_intersect_key((array) ($get($unit ? 'units_at' : 'grams_at') ?? []), array_flip($locationIds));
        if ($unit) {
            return __(':count uds', ['count' => array_sum(array_map(fn ($v): int => (int) (DecimalInput::number($v) ?? 0), $values))]);
        }
        $cg = array_sum(array_map(fn ($v): int => ($n = DecimalInput::number($v)) !== null ? Weight::fromGrams($n)->centigrams : 0, $values));

        return Weight::fromCentigrams($cg)->formatted();
    }

    /** Prompt 305 — the lote's parts, one line each: location, what is left, a link to that part. */
    private static function lotePartsList(Batch $record): HtmlString
    {
        $parts = $record->lotePartsQuery()->with('location')->get()->sortBy(fn (Batch $b): string => (string) $b->location?->name);
        $items = $parts->map(fn (Batch $b): string => sprintf(
            '<li data-lote-part class="flex items-center justify-between gap-3 py-1 pointer-coarse:min-h-11"><a href="%s" data-touch-target class="font-medium text-primary-600 hover:underline dark:text-primary-400">%s</a><span class="tabular-nums">%s</span></li>',
            e(BatchResource::getUrl('edit', ['record' => $b])),
            e((string) $b->location?->name).($b->is($record) ? ' · '.e(__('este')) : ''),
            e($b->isUnitType() ? __(':count uds', ['count' => (int) $b->remaining_units]) : $b->remaining_cg->formatted()),
        ))->implode('');

        return new HtmlString('<ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">'.$items.'</ul>');
    }

    /** One sede in the org: nothing to choose, so the field is pre-filled and locked. */
    private static function singleSede(): bool
    {
        return Location::query()->count() === 1;
    }

    /** Prompt 340 — "12.00 €/g · octavo 38.00 €" and *Cambiar precio*, which opens the page's Precio action. */
    private static function currentPriceHtml(?Batch $record): string
    {
        if ($record === null) {
            return '';
        }

        $unit = $record->isUnitType();
        $rate = $unit ? $record->price_per_unit_cents : $record->price_per_gram_cents;
        $parts = [$rate !== null
            ? e(($unit ? __('Precio por unidad') : __('Precio por gramo')).': '.Money::fromCents((int) $rate)->formatted())
            : e(__('Sin precio propio'))];
        if (! $unit && $record->price_per_eighth_cents !== null) {
            $parts[] = e(__('Precio por octavo').': '.Money::fromCents((int) $record->price_per_eighth_cents)->formatted());
        }
        $change = Auth::user()?->can('prices.manage')
            ? ' <button type="button" wire:click="mountAction(\'price\')" data-batch-change-price class="ml-2 inline-flex min-h-11 items-center font-semibold text-primary-600 underline-offset-2 hover:underline dark:text-primary-400">'.e(__('Cambiar precio')).'</button>'
            : '';

        return '<span class="block">'.implode('</span><span class="block">', $parts).'</span>'.$change;
    }
}
