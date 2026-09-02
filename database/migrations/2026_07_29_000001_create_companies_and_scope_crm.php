<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 80)->unique();
            $table->string('lead_api_url', 500)->default('');
            $table->text('lead_api_key_encrypted')->nullable();
            $table->text('calendly_api_token_encrypted')->nullable();
            $table->string('calendly_org_uri', 500)->default('');
            $table->text('calendly_webhook_signing_key_encrypted')->nullable();
            $table->text('multilogin_token_encrypted')->nullable();
            $table->string('multilogin_base_url', 255)->default('https://api.multilogin.com');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $defaultId = DB::table('companies')->insertGetId([
            'name' => 'Default',
            'slug' => 'default',
            'lead_api_url' => '',
            'multilogin_base_url' => 'https://api.multilogin.com',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('contacts', function (Blueprint $table) use ($defaultId) {
            $table->unsignedBigInteger('company_id')->default($defaultId)->after('id');
            $table->string('referrer', 500)->default('');
            $table->text('lead_user_agent')->nullable();
            $table->string('lead_ip', 80)->default('');
            $table->json('lead_raw_json')->nullable();
            $table->timestamp('lead_synced_at')->nullable();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->dropUnique(['email']);
            $table->unique(['company_id', 'email']);
        });

        Schema::table('appointments', function (Blueprint $table) use ($defaultId) {
            $table->unsignedBigInteger('company_id')->default($defaultId)->after('id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        // Keep appointment company aligned with contact company for existing rows.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                UPDATE appointments
                SET company_id = contacts.company_id
                FROM contacts
                WHERE appointments.contact_id = contacts.id
            ');
        } else {
            // sqlite / mysql fallback
            $rows = DB::table('appointments')->get(['id', 'contact_id']);
            foreach ($rows as $row) {
                $companyId = DB::table('contacts')->where('id', $row->contact_id)->value('company_id');
                if ($companyId) {
                    DB::table('appointments')->where('id', $row->id)->update(['company_id' => $companyId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'email']);
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'company_id',
                'referrer',
                'lead_user_agent',
                'lead_ip',
                'lead_raw_json',
                'lead_synced_at',
            ]);
            $table->unique('email');
        });

        Schema::dropIfExists('companies');
    }
};
