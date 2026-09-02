<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            if (! Schema::hasColumn('static_proxies', 'last_check_status')) {
                $table->string('last_check_status', 20)->default('')->after('enabled');
            }
            if (! Schema::hasColumn('static_proxies', 'exit_ip')) {
                $table->string('exit_ip', 64)->default('')->after('last_check_status');
            }
            if (! Schema::hasColumn('static_proxies', 'last_checked_at')) {
                $table->timestamp('last_checked_at')->nullable()->after('exit_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('static_proxies', function (Blueprint $table) {
            foreach (['last_check_status', 'exit_ip', 'last_checked_at'] as $col) {
                if (Schema::hasColumn('static_proxies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
