<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'geo_json')) {
                $table->json('geo_json')->nullable()->after('client_asn');
            }
            if (! Schema::hasColumn('appointments', 'geo_enriched_at')) {
                $table->timestamp('geo_enriched_at')->nullable()->after('geo_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            foreach (['geo_json', 'geo_enriched_at'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
