<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Actions\Stock\MoveArticleToLocation;
use App\Enums\Role;
use App\Filament\Forms\CameraOrFile;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Support\AllOption;
use App\Models\Article;
use App\Models\Location;
use App\Support\ActiveScope;
use App\Support\VapeLikeName;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Producto'))
                    ->schema([
                        // WHERE the article is sold (prompt 294, the same field as BatchForm's — prompt 238). Stock always
                        // belongs to a sede, so the form must NAME it rather than inherit an invisible scope: in the "Todas
                        // las sedes" view that scope is null and the insert failed in production. Defaults to the sede in
                        // the top bar; BLANK in the rollup so an owner picks deliberately (never a guessed first row,
                        // prompt 148); disabled and pre-filled when the club has a single sede. Sedes only — a bar/shop
                        // article is sold at a counter and the Almacén / cultivo has none.
                        // Prompt 297 — on CREATE with several sedes it is a multi-choice (*Sedes*), "all" first: one product
                        // per sede ticked. On EDIT it is the product's one sede, correctable until it has history.
                        Select::make('location_id')
                            ->label(fn (string $operation): string => self::choosesSedes($operation) ? __('Sedes') : __('Sede'))
                            ->multiple(fn (string $operation): bool => self::choosesSedes($operation))
                            ->options(fn (string $operation): array => self::choosesSedes($operation) ? self::sedeChoices() : Location::assignableOptions())
                            ->default(fn (string $operation): string|array|null => self::defaultSede($operation))
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Select $component, mixed $state, mixed $old, string $operation, Get $get, Set $set): void {
                                if (self::choosesSedes($operation)) {
                                    $component->state(AllOption::sync((array) $state, (array) $old, array_keys(Location::assignableOptions())));
                                    self::zeroNewOpeningStock($get, $set);
                                }
                            })
                            ->disabled(fn (string $operation, ?Article $record): bool => self::singleSede() || ($operation === 'edit' && $record?->hasHistory() === true))
                            ->helperText(fn (string $operation, ?Article $record): ?string => $operation === 'edit' && $record?->hasHistory() === true
                                ? MoveArticleToLocation::lockedMessage($record)
                                : null)
                            // A disabled field is not submitted by default; the single-sede value still must be.
                            ->dehydrated()
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),

                        // Prompt 347 — a cannabis vape entered as a BAR product never reaches the registro de dispensación
                        // or the limits. A name that looks like one gets the warning and a one-tap confirmation (an
                        // accessory with no cannabis may match); saving stays allowed and the confirmation is audited.
                        Text::make(fn (): HtmlString => new HtmlString(Blade::render(
                            '<span data-vape-bar-warning class="text-sm font-medium text-warning-700 dark:text-warning-400">{{ $text }} <x-filament::link :href="$href">{{ $link }}</x-filament::link></span>',
                            [
                                'text' => __('Los vapeadores con cannabis no son productos de barra: añádelos como genética de tipo «Vapeador», para que cuenten en el registro y en los límites.'),
                                'href' => GeneticResource::getUrl('create'),
                                'link' => __('Genéticas → Nueva'),
                            ],
                        )))
                            ->visible(fn (Get $get): bool => VapeLikeName::barProduct((string) $get('name')))
                            ->columnSpanFull(),
                        Checkbox::make('accessory_confirmed')
                            ->label(__('Es un accesorio, no contiene cannabis'))
                            ->accepted()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => VapeLikeName::barProduct((string) $get('name')))
                            ->columnSpanFull(),

                        // Prompt 378 — which cash box its money goes in: the bar's (drinks, food) or the shop's (products, merch).
                        // Snapshotted on each order item, so changing it never moves a past order's cash.
                        ToggleButtons::make('sold_at')
                            ->label(__('Se vende en'))
                            ->options(['BAR' => __('Barra'), 'SHOP' => __('Tienda')])
                            ->default('BAR')
                            ->inline()
                            ->required()
                            ->helperText(__('Barra: bebidas y comida. Tienda: productos y merchandising. Decide en qué bote va su dinero si la sede separa la tienda.'))
                            ->columnSpanFull(),

                        // No *Categoría* (prompt 295, Shane): nothing in the app can create a product category — only the demo
                        // seeder does — so on a live club the drop-down was always empty. The `category_id` column and any
                        // existing values stay; grouping bar products would be a small resource of its own.

                        // The model stores integer cents in price_cents; the pages
                        // convert euros ↔ cents (mutate hooks on Create/Edit).
                        DecimalInput::make('price_eur')
                            ->label(__('Precio (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        // Opening stock is set here at creation only. Thereafter use the
                        // "Reponer" row action, which records the movement in the ledger.
                        TextInput::make('stock')
                            ->label(__('Existencias'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && count(self::chosenSedes($get)) <= 1),

                        // Prompt 297 — several sedes ticked: one opening stock per sede, following the selection live.
                        self::openingStockPerSede()
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && count(self::chosenSedes($get)) > 1),

                        TextInput::make('low_stock_threshold')
                            ->label(__('Umbral de stock bajo'))
                            ->numeric()
                            ->minValue(0),

                        // Prompt 297 — the same product at other sedes (created together, or added with «Añadir a otra sede»): a
                        // CHOICE of where this edit also applies, nothing ticked by default. Only sedes the user works at.
                        CheckboxList::make('apply_to')
                            ->label(__('Aplicar los cambios también en'))
                            ->helperText(__('Nombre, precio, imágenes, umbral y activo. Las existencias de cada sede no cambian.'))
                            ->options(fn (?Article $record): array => $record instanceof Article ? self::siblingChoices($record) : [])
                            ->default([])
                            ->live()
                            ->afterStateUpdated(function (CheckboxList $component, mixed $state, mixed $old, ?Article $record): void {
                                $component->state(AllOption::sync((array) $state, (array) $old, array_keys(self::siblingChoices($record, withAll: false))));
                            })
                            ->visible(fn (string $operation, ?Article $record): bool => $operation === 'edit' && $record instanceof Article && self::siblingChoices($record, withAll: false) !== [])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Imágenes'))
                    ->schema([
                        CameraOrFile::field(FileUpload::make('images')
                            ->label(__('Imágenes'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->multiple(), camera: 'environment'),
                    ]),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),

            ]);
    }

    /**
     * One number per chosen sede, labelled with its name (prompt 297).
     */
    public static function openingStockPerSede(): Grid
    {
        return Grid::make(['default' => 1, 'sm' => 2, 'lg' => 3])->columnSpanFull()->schema(fn (Get $get): array => collect(self::chosenSedes($get))
            ->map(fn (string $id): TextInput => TextInput::make("stock_at.{$id}")
                ->label(__('Existencias en :sede', ['sede' => Location::query()->withoutGlobalScopes()->whereKey($id)->value('name')]))
                ->numeric()
                ->minValue(0)
                ->default(0))
            ->all());
    }

    /** A sede just ticked shows 0, not an empty box (fields added live miss their default). */
    public static function zeroNewOpeningStock(Get $get, Set $set): void
    {
        foreach (self::chosenSedes($get) as $id) {
            if ($get("stock_at.{$id}") === null) {
                $set("stock_at.{$id}", 0);
            }
        }
    }

    /** @return list<string> the sedes ticked (without "all") */
    public static function chosenSedes(Get $get): array
    {
        return AllOption::chosen($get('location_id'));
    }

    /** On create, with more than one sede in the club, the sede is a multi-choice. */
    private static function choosesSedes(string $operation): bool
    {
        return $operation === 'create' && ! self::singleSede();
    }

    /** @return array<string, string> "all" (the owner's *Todas las sedes*, a manager's *Tus sedes*) + each sede */
    private static function sedeChoices(): array
    {
        $sedes = Location::assignableOptions();

        return count($sedes) > 1 ? [AllOption::KEY => self::allLabel()] + $sedes : $sedes;
    }

    public static function allLabel(): string
    {
        return Auth::user()?->hasRole(Role::OWNER->value) === true ? __('Todas las sedes') : __('Tus sedes');
    }

    /** @return string|list<string>|null */
    private static function defaultSede(string $operation): string|array|null
    {
        $active = app(ActiveScope::class)->locationId();
        if (! self::choosesSedes($operation)) {
            return $active;
        }

        return $active !== null && array_key_exists($active, Location::assignableOptions()) ? [$active] : [];
    }

    /**
     * The group's other products the user may reach, keyed by article id, labelled by sede.
     *
     * @return array<string, string>
     */
    private static function siblingChoices(?Article $record, bool $withAll = true): array
    {
        if (! $record instanceof Article || $record->group_id === null) {
            return [];
        }

        $reachable = Location::assignableOptions();
        $siblings = $record->groupSiblings()->whereIn('location_id', array_keys($reachable))->get()
            ->mapWithKeys(fn (Article $sibling): array => [(string) $sibling->getKey() => $reachable[$sibling->location_id]])
            ->sort()
            ->all();

        return $withAll && count($siblings) > 1 ? [AllOption::KEY => __('Todas')] + $siblings : $siblings;
    }

    /** One sede in the org: nothing to choose, so the field is pre-filled and locked. */
    private static function singleSede(): bool
    {
        return Location::query()->sedes()->count() === 1;
    }
}
