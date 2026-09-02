<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'short_name')) {
                $table->string('short_name', 60)->default('')->after('name');
            }
        });

        // Backfill a sensible short name (last word tends to be the brand, e.g.
        // "Insight Global" -> "Global"; "Diligent Placers" -> "Placers"); users
        // can edit it on the company page.
        try {
            foreach (DB::table('companies')->get() as $c) {
                if (trim((string) ($c->short_name ?? '')) !== '') {
                    continue;
                }
                $words = preg_split('/\s+/', trim((string) $c->name)) ?: [];
                $short = $words ? end($words) : (string) $c->name;
                DB::table('companies')->where('id', $c->id)->update(['short_name' => $short]);
            }
        } catch (\Throwable $e) {
            // Non-fatal.
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'short_name')) {
                $table->dropColumn('short_name');
            }
        });
    }
};
