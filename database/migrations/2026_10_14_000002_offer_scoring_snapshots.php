<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_offer_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('total');
            $table->json('dimensions');
            $table->json('explanation');
            $table->float('confidence');
            $table->float('coverage');
            $table->string('rule_version');
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['lead_id', 'offer_id']);
            $table->index(['offer_id', 'total']);
        });
        Schema::table('contact_attempts', fn (Blueprint $table) => $table->json('score_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('contact_attempts', fn (Blueprint $table) => $table->dropColumn('score_snapshot'));
        Schema::dropIfExists('lead_offer_scores');
    }
};
