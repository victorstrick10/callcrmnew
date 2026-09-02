<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_numbers_v2', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('number');
            $table->string('status', 40)->default('available');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('profile_type', 30)->default('');
            $table->string('multilogin_profile_id', 255)->default('');
            $table->string('profile_name', 500)->default('');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'number']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        $defaultCompanyId = DB::table('companies')->orderBy('id')->value('id');

        if ($defaultCompanyId && Schema::hasTable('profile_numbers')) {
            $rows = DB::table('profile_numbers')->orderBy('number')->get();
            $chunk = [];
            foreach ($rows as $row) {
                $companyId = $defaultCompanyId;
                if (! empty($row->appointment_id)) {
                    $fromAppt = DB::table('appointments')->where('id', $row->appointment_id)->value('company_id');
                    if ($fromAppt) {
                        $companyId = $fromAppt;
                    }
                }

                $chunk[] = [
                    'company_id' => $companyId,
                    'number' => (int) $row->number,
                    'status' => $row->status,
                    'appointment_id' => $row->appointment_id,
                    'profile_type' => $row->profile_type ?? '',
                    'multilogin_profile_id' => $row->multilogin_profile_id ?? '',
                    'profile_name' => '',
                    'reserved_at' => $row->reserved_at,
                    'created_at' => $row->created_at,
                ];

                if (count($chunk) >= 200) {
                    DB::table('profile_numbers_v2')->insert($chunk);
                    $chunk = [];
                }
            }
            if ($chunk) {
                DB::table('profile_numbers_v2')->insert($chunk);
            }
        }

        Schema::dropIfExists('profile_numbers');
        Schema::rename('profile_numbers_v2', 'profile_numbers');
    }

    public function down(): void
    {
        Schema::create('profile_numbers_legacy', function (Blueprint $table) {
            $table->integer('number')->primary();
            $table->string('status', 40)->default('available');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('profile_type', 30)->default('');
            $table->string('multilogin_profile_id', 255)->default('');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $seen = [];
        foreach (DB::table('profile_numbers')->orderBy('id')->get() as $row) {
            $n = (int) $row->number;
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            DB::table('profile_numbers_legacy')->insert([
                'number' => $n,
                'status' => $row->status,
                'appointment_id' => $row->appointment_id,
                'profile_type' => $row->profile_type,
                'multilogin_profile_id' => $row->multilogin_profile_id,
                'reserved_at' => $row->reserved_at,
                'created_at' => $row->created_at,
            ]);
        }

        Schema::dropIfExists('profile_numbers');
        Schema::rename('profile_numbers_legacy', 'profile_numbers');
    }
};
