<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How often the in-browser MRZ reader gets a field right, measured from corrections (prompt 179).
 *
 * Counts only — see the migration. This is the instrument that answers prompt 128's read-rate gate without
 * assembling a corpus of real ID photos.
 */
class MrzFieldStat extends Model
{
    use BelongsToOrganisation, HasUlids;

    protected $fillable = ['organisation_id', 'field', 'prefills', 'corrections'];

    protected function casts(): array
    {
        return ['prefills' => 'integer', 'corrections' => 'integer'];
    }

    /**
     * Record one prefill of a field, and whether the applicant then corrected it.
     *
     * Upsert + atomic increment rather than read-modify-write: two applicants scanning at once must not
     * lose a count, and nothing here is worth a lock.
     */
    public static function record(string $organisationId, string $field, bool $corrected): void
    {
        static::query()->withoutGlobalScopes()->firstOrCreate(
            ['organisation_id' => $organisationId, 'field' => $field],
            ['prefills' => 0, 'corrections' => 0],
        );

        static::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)->where('field', $field)
            ->update([
                'prefills' => DB::raw('prefills + 1'),
                'corrections' => DB::raw('corrections + '.($corrected ? 1 : 0)),
                'updated_at' => now(),
            ]);
    }

    /** Corrected share of prefills, 0–100. The figure the feature should be judged on. */
    public function correctionRate(): int
    {
        return $this->prefills > 0 ? (int) round_half_up($this->corrections / $this->prefills * 100) : 0;
    }

    /**
     * @param  Builder<MrzFieldStat>  $query
     * @return Builder<MrzFieldStat>
     */
    public function scopeMeasured(Builder $query): Builder
    {
        return $query->where('prefills', '>', 0);
    }
}
