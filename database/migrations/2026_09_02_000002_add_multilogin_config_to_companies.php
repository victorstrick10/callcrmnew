<?php

use App\Models\Company;
use App\Services\IntegrationSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'multilogin_config')) {
                $table->json('multilogin_config')->nullable()->after('multilogin_base_url');
            }
            if (! Schema::hasColumn('companies', 'service_status')) {
                $table->json('service_status')->nullable()->after('multilogin_config');
            }
        });

        // Seed each company's Multilogin config from the existing GLOBAL integration
        // settings so the current working setup keeps functioning after the UI move.
        try {
            $settings = app(IntegrationSettingsService::class);
            $global = $settings->getSettings('multilogin');
            if (! empty($global)) {
                $advanced = collect($global)
                    ->except(['automation_token'])
                    ->toArray();
                $globalToken = (string) ($global['automation_token'] ?? '');

                foreach (Company::query()->get() as $company) {
                    $changed = false;
                    if (empty($company->multilogin_config)) {
                        $company->multilogin_config = $advanced;
                        $changed = true;
                    }
                    if ($globalToken !== '' && ! $company->getMultiloginToken()) {
                        $company->setMultiloginToken($globalToken);
                        $changed = true;
                    }
                    if ($changed) {
                        $company->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: fresh installs simply start with empty per-company config.
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['multilogin_config', 'service_status'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
