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
            if (! Schema::hasColumn('companies', 'tree')) {
                $table->string('tree', 40)->default('off1')->index()->after('short_name');
            }
        });

        // Seed the initial office trees: Rusell → Off2, everything else → Off1.
        DB::table('companies')->where('name', 'ilike', '%rusell%')->update(['tree' => 'off2']);
        DB::table('companies')->whereNull('tree')->orWhere('tree', '')->update(['tree' => 'off1']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'tree')) {
                $table->dropColumn('tree');
            }
        });
    }
};
