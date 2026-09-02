<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            if (! Schema::hasColumn('static_proxies', 'provider')) {
                $table->string('provider', 40)->default('')->after('label');
            }
            if (! Schema::hasColumn('static_proxies', 'location')) {
                $table->string('location', 255)->default('')->after('provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            foreach (['provider', 'location'] as $col) {
                if (Schema::hasColumn('static_proxies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
