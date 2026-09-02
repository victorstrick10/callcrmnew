<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_proxies', function (Blueprint $table) {
            $table->id();
            $table->string('label', 255)->default('');
            $table->string('host', 255);
            $table->unsignedInteger('port');
            $table->string('username', 255)->default('');
            $table->text('password')->nullable();
            $table->string('protocol', 20)->default('http');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_proxies');
    }
};
