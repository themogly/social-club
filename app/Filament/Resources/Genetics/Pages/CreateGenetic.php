<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Actions\Pricing\SaveGeneticPrice;
use App\Actions\Stock\IntakeBatch;
use App\Enums\ConcentrateSubtype;
use App\Enums\CultivationType;
use App\Enums\ProductType;
use App\Enums\StrainType;
use App\Enums\UnitType;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Models\Genetic;
use App\Models\Location;
use App\Support\ActiveScope;
use App\Support\DocumentUpload;
use App\Support\Money;
use App\Support\Weight;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 247 — adding a strain is ONE flow, in the tester's order: what strain, what type, how much, which
 * sede, price, photo — and it is SELLABLE when you finish.
 *
 * On `main` this was three separate forms — the genetic, a price per sede, a batch — and a genetic saved from
 * the first was invisible at every counter until the other two were filled, with nothing saying so
 * (`sellableAt` needs active + a base price + stock; prompt 95). This is a Filament Wizard whose FINISH writes
 * all three THROUGH THE EXISTING SINGLE WRITERS, in one transaction: the genetic (active), the batch via
 * `IntakeBatch` (238's intake, the INTAKE movement, the ceiling checks), the price via `SaveGeneticPrice`. No
 * rule is reimplemented — the flow composes them. All or nothing: a failure in any step rolls back the whole
 * strain, so a half-created genetic with no price never exists.
 *
 * The full `GeneticForm` stays the EDIT path (`EditGenetic`) with every section; this is the create path.
 */
class CreateGenetic extends CreateRecord
{
    use HasWizard;

    protected static string $resource = GeneticResource::class;

    /** @return array<int, Step> */
    public function getSteps(): array
    {
        return [
            Step::make(__('Variedad'))
                ->description(__('Qué variedad es'))
                ->schema([
                    TextInput::make('name')->label(__('Nombre'))->required()->maxLength(255),
                    Select::make('strain_type')->label(__('Variedad'))
                        ->options(collect(StrainType::cases())->mapWithKeys(fn (StrainType $case): array => [$case->value => $case->label()])->all())
                        ->placeholder(__('Sin especificar')),
                    TextInput::make('thc_pct')->label(__('THC (%)'))->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                    TextInput::make('cbd_pct')->label(__('CBD (%)'))->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                    Select::make('cultivation_type')->label(__('Tipo de cultivo'))
                        ->options(collect(CultivationType::cases())->mapWithKeys(fn (CultivationType $case): array => [$case->value => $case->label()])->all()),
                ])->columns(2),

            Step::make(__('Tipo'))
                ->description(__('Cómo se dispensa'))
                ->schema([
                    Select::make('product_type')->label(__('Tipo de producto'))
                        ->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $case): array => [$case->value => $case->label()])->all())
                        ->default(ProductType::FLOWER->value)->required()->live()
                        ->helperText(fn (Get $get): string => __('Se dispensa: :modo', [
                            'modo' => (ProductType::tryFrom((string) $get('product_type')) ?? ProductType::FLOWER)->unitType()->label(),
                        ])),
                    Select::make('concentrate_subtype')->label(__('Subtipo de extracto'))
                        ->options(collect(ConcentrateSubtype::cases())->mapWithKeys(fn (ConcentrateSubtype $case): array => [$case->value => $case->label()])->all())
                        ->visible(fn (Get $get): bool => $get('product_type') === ProductType::CONCENTRATE->value),
                    TextInput::make('grams_per_unit_g')->label(__('Gramos por unidad (g)'))
                        ->numeric()->minValue(0)->step(0.01)->suffix('g')
                        ->visible(fn (Get $get): bool => self::isUnit($get('product_type')))
                        ->required(fn (Get $get): bool => self::isUnit($get('product_type'))),
                ])->columns(2),

            Step::make(__('Cantidad'))
                ->description(__('Cuánto stock entra'))
                ->schema([
                    // The first batch, in the genetic's own unit — 238's intake fields, no more.
                    TextInput::make('grams')->label(__('Cantidad (g)'))->numeric()->minValue(0)
                        ->visible(fn (Get $get): bool => ! self::isUnit($get('product_type')))
                        ->required(fn (Get $get): bool => ! self::isUnit($get('product_type'))),
                    TextInput::make('units')->label(__('Cantidad (uds)'))->numeric()->minValue(1)->step(1)
                        ->visible(fn (Get $get): bool => self::isUnit($get('product_type')))
                        ->required(fn (Get $get): bool => self::isUnit($get('product_type'))),
                    TextInput::make('cost_per_gram_eur')->label(__('Coste por gramo (€)'))->numeric()->minValue(0),
                    FileUpload::make('lab_report_path')->label(__('Informe de laboratorio'))
                        ->disk('documents')->getUploadedFileUsing(DocumentUpload::withoutDirectUrl())
                        ->visibility('private')->maxSize(DocumentUpload::maxKilobytes())
                        ->helperText(DocumentUpload::helperText()),
                ])->columns(2),

            Step::make(__('Sede'))
                ->description(__('En qué sede entra'))
                ->schema([
                    // 238's rule exactly: default to the scope, required, blank in the rollup, locked single-sede.
                    Select::make('location_id')->label(__('Sede'))
                        ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?string => app(ActiveScope::class)->locationId())
                        ->disabled(fn (): bool => Location::query()->count() === 1)
                        ->dehydrated()
                        ->required(),
                ]),

            Step::make(__('Precio'))
                ->description(__('El precio en esa sede'))
                ->schema([
                    TextInput::make('price_per_gram_eur')->label(__('Precio por gramo (€)'))->numeric()->minValue(0)->required()
                        ->visible(fn (Get $get): bool => ! self::isUnit($get('product_type')))
                        ->required(fn (Get $get): bool => ! self::isUnit($get('product_type'))),
                    TextInput::make('price_per_unit_eur')->label(__('Precio por unidad (€)'))->numeric()->minValue(0)
                        ->visible(fn (Get $get): bool => self::isUnit($get('product_type')))
                        ->required(fn (Get $get): bool => self::isUnit($get('product_type'))),
                ]),

            Step::make(__('Foto'))
                ->description(__('Una foto (opcional)'))
                ->schema([
                    // The tablet's camera directly: `capture` opens it on a phone; no photo means no image (193).
                    FileUpload::make('images')->label(__('Foto'))->image()->disk('public')->multiple()
                        ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment']),
                ]),
        ];
    }

    /**
     * The finish — three writes, one transaction, all or nothing. The genetic first (its observer derives
     * unit_type from product_type), then the batch through `IntakeBatch` and the price through
     * `SaveGeneticPrice`. If the price write throws, the genetic and the batch roll back with it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Genetic {
            $genetic = Genetic::create([
                'name' => $data['name'],
                'product_type' => $data['product_type'],
                'strain_type' => $data['strain_type'] ?? null,
                'concentrate_subtype' => $data['concentrate_subtype'] ?? null,
                'cultivation_type' => $data['cultivation_type'] ?? null,
                'thc_bp' => filled($data['thc_pct'] ?? null) ? (int) round_half_up(((float) $data['thc_pct']) * 100) : null,
                'cbd_bp' => filled($data['cbd_pct'] ?? null) ? (int) round_half_up(((float) $data['cbd_pct']) * 100) : null,
                'grams_per_unit_cg' => filled($data['grams_per_unit_g'] ?? null) ? Weight::fromGrams($data['grams_per_unit_g'])->centigrams : null,
                'images' => $data['images'] ?? [],
                'active' => true,
                'published' => true,
            ]);

            /** @var Location $location */
            $location = Location::query()->findOrFail($data['location_id']);

            $intake = [
                'cost_per_gram_cents' => (int) round_half_up(((float) ($data['cost_per_gram_eur'] ?? 0)) * 100),
                'lab_report_path' => $data['lab_report_path'] ?? null,
            ];
            $genetic->isUnitType()
                ? $intake['units'] = (int) ($data['units'] ?? 0)
                : $intake['grams'] = $data['grams'];

            (new IntakeBatch)->handle($genetic, $location, $intake);

            $priceEur = $genetic->isUnitType() ? ($data['price_per_unit_eur'] ?? 0) : ($data['price_per_gram_eur'] ?? 0);
            (new SaveGeneticPrice)->handle($genetic, $location, null, Money::fromEuros((string) $priceEur)->cents);

            $this->createdSummary = $this->summarise($genetic, $location, $data);

            return $genetic;
        });
    }

    private string $createdSummary = '';

    protected function getRedirectUrl(): string
    {
        return GeneticResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /** The outcome the tester was missing: what is in stock, at what price, and that it is visible NOW. */
    protected function getCreatedNotification(): ?Notification
    {
        $genetic = $this->getRecord();
        $reason = $genetic instanceof Genetic ? $genetic->completenessReason() : null;

        return $reason === null
            ? Notification::make()->success()->title(__('Variedad dada de alta'))->body($this->createdSummary)
            : Notification::make()->warning()->title(__('Variedad creada, pero aún no se dispensa'))
                ->body($this->summariseGap($reason));
    }

    /** @param array<string, mixed> $data */
    private function summarise(Genetic $genetic, Location $location, array $data): string
    {
        $stock = $genetic->isUnitType()
            ? trans_choice(':count unidad|:count unidades', (int) ($data['units'] ?? 0), ['count' => (int) ($data['units'] ?? 0)])
            : rtrim(rtrim((string) ($data['grams'] ?? '0'), '0'), '.').' g';
        $priceEur = $genetic->isUnitType() ? ($data['price_per_unit_eur'] ?? 0) : ($data['price_per_gram_eur'] ?? 0);
        $unit = $genetic->isUnitType() ? __('/ud') : __('/g');

        return __(':genetic dada de alta en :sede: :stock en stock, :price :unit — ya visible en el mostrador.', [
            'genetic' => $genetic->name,
            'sede' => $location->name,
            'stock' => $stock,
            'price' => Money::fromEuros((string) $priceEur)->formatted(),
            'unit' => $unit,
        ]);
    }

    private function summariseGap(string $reason): string
    {
        return match ($reason) {
            'no_price' => __('Falta un precio en esta sede.'),
            'no_stock' => __('Falta stock en esta sede.'),
            default => __('Aún no se puede dispensar en el mostrador.'),
        };
    }

    private static function isUnit(mixed $productType): bool
    {
        $type = ProductType::tryFrom((string) $productType);

        return $type !== null && $type->unitType() === UnitType::UNIT;
    }
}
