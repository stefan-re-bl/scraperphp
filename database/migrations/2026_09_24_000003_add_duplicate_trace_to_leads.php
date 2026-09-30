<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('duplicate_status')->default('unique')->after('data_quality');
            $table->string('duplicate_rule')->nullable()->after('duplicate_status');
            $table->foreignId('possible_duplicate_of_lead_id')->nullable()->after('duplicate_rule')->constrained('leads')->nullOnDelete();
        });

        Schema::table('import_runs', function (Blueprint $table) {
            $table->unsignedInteger('possible_duplicate_count')->default(0)->after('duplicate_count');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('possible_duplicate_of_lead_id');
            $table->dropColumn(['duplicate_status', 'duplicate_rule']);
        });

        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn('possible_duplicate_count');
        });
    }
};
