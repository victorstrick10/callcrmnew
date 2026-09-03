<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'chosen_static_proxy_id')) {
                $table->unsignedBigInteger('chosen_static_proxy_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'chosen_static_proxy_id')) {
                $table->dropColumn('chosen_static_proxy_id');
            }
        });
    }
};
