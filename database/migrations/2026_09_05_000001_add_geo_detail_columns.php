<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            if (! Schema::hasColumn('static_proxies', 'exit_region_code')) {
                $table->string('exit_region_code', 40)->default('')->after('exit_region');
            }
            if (! Schema::hasColumn('static_proxies', 'exit_zip')) {
                $table->string('exit_zip', 40)->default('')->after('exit_city');
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'proxy_region_code')) {
                $table->string('proxy_region_code', 40)->nullable()->after('proxy_actual_region');
            }
            if (! Schema::hasColumn('appointments', 'proxy_zip')) {
                $table->string('proxy_zip', 40)->nullable()->after('proxy_actual_city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            foreach (['exit_region_code', 'exit_zip'] as $col) {
                if (Schema::hasColumn('static_proxies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            foreach (['proxy_region_code', 'proxy_zip'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
