<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->string('status', 32)->default('en_instruction')->after('montant');
            $table->string('workflow_step', 32)->default('expert_budget')->after('status');
            $table->string('expected_actor_label')->default('Expert Budget')->after('workflow_step');
            $table->string('beneficiary_name')->nullable()->after('expected_actor_label');
            $table->string('beneficiary_rccm')->nullable()->after('beneficiary_name');
            $table->string('beneficiary_nif')->nullable()->after('beneficiary_rccm');
            $table->string('last_action')->nullable()->after('beneficiary_nif');
            $table->date('due_on')->nullable()->after('last_action');
            $table->timestamp('reserved_at')->nullable()->after('due_on');
            $table->string('visa_reference')->nullable()->after('reserved_at');
            $table->timestamp('vised_at')->nullable()->after('visa_reference');
            $table->string('liquidation_reference')->nullable()->after('vised_at');
            $table->string('return_motif')->nullable()->after('liquidation_reference');
            $table->string('rejection_motif')->nullable()->after('return_motif');
        });

        Schema::create('eng_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('motif')->nullable();
            $table->text('observations')->nullable();
            $table->json('fields')->nullable();
            $table->timestamps();
        });

        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('engagement_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('montant');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidations');
        Schema::dropIfExists('eng_events');
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'workflow_step',
                'expected_actor_label',
                'beneficiary_name',
                'beneficiary_rccm',
                'beneficiary_nif',
                'last_action',
                'due_on',
                'reserved_at',
                'visa_reference',
                'vised_at',
                'liquidation_reference',
                'return_motif',
                'rejection_motif',
            ]);
        });
    }
};
