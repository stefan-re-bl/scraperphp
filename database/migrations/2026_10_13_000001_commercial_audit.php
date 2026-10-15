<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead', function (Blueprint $table) {
            $table->boolean('contact_enabled')->default(false);
        });
        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->unsignedInteger('preparation_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead', fn (Blueprint $table) => $table->dropColumn('contact_enabled'));
        Schema::table('contact_attempts', fn (Blueprint $table) => $table->dropColumn('preparation_seconds'));
    }
};
