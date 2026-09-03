<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'multilogin_email_encrypted')) {
                $table->text('multilogin_email_encrypted')->nullable();
            }
            if (! Schema::hasColumn('companies', 'multilogin_password_encrypted')) {
                $table->text('multilogin_password_encrypted')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['multilogin_email_encrypted', 'multilogin_password_encrypted'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
