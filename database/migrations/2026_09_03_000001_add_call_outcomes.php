<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'outcome')) {
                $table->string('outcome', 30)->default('pending')->index();
            }
            if (! Schema::hasColumn('appointments', 'outcome_note')) {
                $table->text('outcome_note')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'outcome_at')) {
                $table->timestamp('outcome_at')->nullable();
            }
        });

        Schema::table('browser_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('browser_profiles', 'is_kept')) {
                $table->boolean('is_kept')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            foreach (['outcome', 'outcome_note', 'outcome_at'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('browser_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('browser_profiles', 'is_kept')) {
                $table->dropColumn('is_kept');
            }
        });
    }
};
