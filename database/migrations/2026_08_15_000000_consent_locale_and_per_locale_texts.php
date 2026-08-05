<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The locale the applicant was READING when they consented (prompt 153) — the same class of fact as the
        // version, and prompt 97's reasoning extends to it unchanged. NULLABLE and left NULL for existing rows:
        // members who consented under 1.0 did so before this was recorded, and absent means absent — we do not
        // invent a Spanish that was never observed. Only new consents stamp it.
        Schema::table('consent_records', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('consent_text_version');
        });

        // Any EXISTING per-org override of the two consent texts was stored as a single (Spanish) STRING. Fold
        // each into the new per-locale shape { es: <the wording they authored>, en: <the shipped English default> }
        // as a JSON row, so a club's own Spanish is preserved and the English default is present to translate.
        // Keys with no override row fall through to the array DEFAULTS. Idempotent: a value that already decodes
        // to an array is left alone.
        foreach (['consent_privacy_text', 'consent_statutes_text'] as $key) {
            foreach (DB::table('settings')->where('key', $key)->get() as $row) {
                if (is_array(json_decode((string) $row->value, true))) {
                    continue; // already per-locale
                }
                $default = Settings::DEFAULTS[$key];
                DB::table('settings')->where('id', $row->id)->update([
                    'value' => json_encode(['es' => (string) $row->value, 'en' => (string) ($default['en'] ?? '')]),
                    'type' => 'JSON',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
        // The string→array settings conversion is deliberately ONE-WAY: any English a club authored has no
        // single-string home, and reversing would silently discard it (same stance as the prompt 146 backfill).
    }
};
