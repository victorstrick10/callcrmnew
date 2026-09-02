<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            foreach (['exit_country' => 40, 'exit_region' => 80, 'exit_city' => 80, 'exit_isp' => 120] as $col => $len) {
                if (! Schema::hasColumn('static_proxies', $col)) {
                    $table->string($col, $len)->default('')->after('exit_ip');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            foreach (['exit_country', 'exit_region', 'exit_city', 'exit_isp'] as $col) {
                if (Schema::hasColumn('static_proxies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
