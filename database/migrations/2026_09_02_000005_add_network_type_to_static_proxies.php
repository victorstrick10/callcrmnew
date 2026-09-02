<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            if (! Schema::hasColumn('static_proxies', 'network_type')) {
                $table->string('network_type', 30)->default('')->after('provider');
            }
        });

        // Backfill: MobileHop entries are mobile; ProxyCheap type is in the label.
        try {
            DB::table('static_proxies')->where('provider', 'mobilehop')->update(['network_type' => 'mobile']);
            DB::table('static_proxies')->where('provider', 'proxycheap')->where('label', 'like', '%MOBILE%')->update(['network_type' => 'mobile']);
            DB::table('static_proxies')->where('network_type', '')->where('provider', 'proxycheap')->update(['network_type' => 'residential']);
        } catch (\Throwable $e) {
            // Non-fatal on fresh installs.
        }
    }

    public function down(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            if (Schema::hasColumn('static_proxies', 'network_type')) {
                $table->dropColumn('network_type');
            }
        });
    }
};
