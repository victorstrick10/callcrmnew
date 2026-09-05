<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'region_code')) {
                $table->string('region_code', 40)->nullable()->after('region');
            }
            if (! Schema::hasColumn('appointments', 'postal')) {
                $table->string('postal', 40)->nullable()->after('city');
            }
        });

        // Backfill from already-stored ip-api responses (only ip-api-shaped
        // geo_json carries a short region code + zip; skip legacy ipinfo data).
        foreach (DB::table('appointments')->whereNotNull('geo_json')->select('id', 'geo_json')->cursor() as $row) {
            $j = json_decode((string) $row->geo_json, true);
            if (! is_array($j) || ($j['status'] ?? '') !== 'success') {
                continue;
            }
            $updates = [];
            if (trim((string) ($j['zip'] ?? '')) !== '') {
                $updates['postal'] = (string) $j['zip'];
            }
            if (trim((string) ($j['region'] ?? '')) !== '') {
                $updates['region_code'] = (string) $j['region'];
            }
            if ($updates) {
                DB::table('appointments')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            foreach (['region_code', 'postal'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
