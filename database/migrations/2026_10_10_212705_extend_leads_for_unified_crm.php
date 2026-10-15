<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('entity_type')->default('organization');
            $table->boolean('is_customer')->default(false)->index();
            $table->boolean('is_collaborator')->default(false)->index();
            $table->boolean('classification_pending')->default(false);
            $table->string('manual_priority')->nullable()->index();
            $table->string('phone_original')->nullable();
            $table->unsignedBigInteger('estimated_value_minor')->nullable();
            $table->string('estimated_currency', 3)->nullable();
            $table->timestamp('origin_created_at')->nullable();
            $table->timestamp('imported_last_contact_at')->nullable();
            $table->timestamp('delivery_due_at')->nullable();
        });
        DB::table('leads')->where('relationship_type', 'customer')->update(['is_customer' => true]);
        DB::table('leads')->where('relationship_type', 'collaborator')->update(['is_collaborator' => true]);
        DB::table('leads')->where('status', 'WON')->update(['is_customer' => true]);
        DB::table('leads')->whereNotNull('phone')->update(['phone_original' => DB::raw('phone')]);

        Schema::create('lead_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_original')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('import_key')->nullable();
            $table->timestamps();
            $table->unique(['lead_id', 'import_key']);
        });
        Schema::create('client_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('status')->default('planned');
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('renews_on')->nullable()->index();
            $table->string('reference_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->string('import_key')->nullable()->unique();
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropUnique(['import_key']);
            $table->dropColumn(['import_key', 'metadata']);
        });
        Schema::dropIfExists('client_services');
        Schema::dropIfExists('lead_contacts');
        Schema::table('leads', function (Blueprint $table) {
            foreach (['is_customer', 'is_collaborator', 'manual_priority'] as $column) {
                $table->dropIndex([$column]);
            }
            $table->dropColumn(['entity_type', 'is_customer', 'is_collaborator', 'classification_pending', 'manual_priority', 'phone_original', 'estimated_value_minor', 'estimated_currency', 'origin_created_at', 'imported_last_contact_at', 'delivery_due_at']);
        });
    }
};
