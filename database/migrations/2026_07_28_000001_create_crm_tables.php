<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 120)->default('');
            $table->string('last_name', 120)->default('');
            $table->string('email', 255)->unique();
            $table->string('phone', 80)->default('');
            $table->string('company', 180)->default('');
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('calendly_event_uri', 500)->nullable()->unique();
            $table->string('calendly_invitee_uri', 500)->nullable();
            $table->string('event_name', 255)->default('Scheduled Call');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->string('invitee_timezone', 120)->default('');
            $table->string('status', 40)->default('scheduled');
            $table->string('ip_address', 255)->default('');
            $table->text('user_agent')->nullable();
            $table->string('city', 120)->default('');
            $table->string('region', 120)->default('');
            $table->string('country', 120)->default('');
            $table->string('country_code', 20)->default('');
            $table->float('latitude')->nullable();
            $table->float('longitude')->nullable();
            $table->string('timezone', 120)->default('');
            $table->string('proxy_status', 30)->default('not_requested');
            $table->string('proxy_host', 255)->nullable();
            $table->integer('proxy_port')->nullable();
            $table->text('proxy_username')->nullable();
            $table->text('proxy_password')->nullable();
            $table->string('proxy_protocol', 20)->nullable();
            $table->string('proxy_country', 10)->nullable();
            $table->string('proxy_region', 255)->nullable();
            $table->string('proxy_city', 255)->nullable();
            $table->string('proxy_requested_region', 255)->nullable();
            $table->string('proxy_requested_city', 255)->nullable();
            $table->string('proxy_match_level', 30)->nullable();
            $table->text('proxy_raw_response')->nullable();
            $table->timestamp('proxy_created_at')->nullable();
            $table->text('proxy_last_error')->nullable();
            $table->string('client_isp', 255)->default('');
            $table->string('client_org', 255)->default('');
            $table->string('client_asn', 80)->default('');
            $table->string('proxy_exit_ip', 80)->default('');
            $table->string('proxy_isp', 255)->default('');
            $table->string('proxy_org', 255)->default('');
            $table->string('proxy_asn', 80)->default('');
            $table->string('proxy_actual_country', 20)->default('');
            $table->string('proxy_actual_region', 255)->default('');
            $table->string('proxy_actual_city', 255)->default('');
            $table->jsonb('proxy_candidates_json')->nullable();
            $table->timestamps();
        });

        Schema::create('browser_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->integer('number');
            $table->string('profile_role', 30);
            $table->string('profile_name', 255);
            $table->string('multilogin_profile_id', 255)->default('');
            $table->string('proxy_label', 255)->default('');
            $table->string('status', 40)->default('reserved');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('profile_numbers', function (Blueprint $table) {
            $table->integer('number')->primary();
            $table->string('status', 40)->default('available');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('profile_type', 30)->default('');
            $table->string('multilogin_profile_id', 255)->default('');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 60)->unique();
            $table->text('encrypted_json')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('last_test_status', 40)->default('');
            $table->text('last_test_message')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 255);
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('integration_settings');
        Schema::dropIfExists('profile_numbers');
        Schema::dropIfExists('browser_profiles');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('contacts');
    }
};
