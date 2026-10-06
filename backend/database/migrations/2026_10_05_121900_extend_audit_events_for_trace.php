<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contexte d’audit ajouté sans réécrire les événements déjà enregistrés.
 * Aucune clé étrangère : un UPDATE déclenché par une suppression métier
 * serait refusé par le journal en ajout seul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique();
            $table->timestampTz('occurred_at')->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->string('actor_name')->nullable();
            $table->json('habilitation')->nullable();
            $table->unsignedBigInteger('organization_unit_id')->nullable();
            $table->unsignedSmallInteger('exercise_year')->nullable();
            $table->string('module', 64)->nullable();
            $table->string('entity_reference', 128)->nullable();
            $table->json('changed_fields')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->unsignedBigInteger('causation_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('sensitivity', 32)->default('interne');
            $table->string('channel', 32)->nullable();
            $table->string('user_agent', 180)->nullable();
            $table->string('source_table', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('context')->nullable();

            $table->unique(['source_table', 'source_id']);
            $table->index('occurred_at');
            $table->index('module');
            $table->index('correlation_id');
            $table->index(['object_type', 'object_id']);
        });

        Schema::create('audit_holds', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('object_type', 64)->nullable();
            $table->string('object_id', 64)->nullable();
            $table->string('motif');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_holds');
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropUnique(['source_table', 'source_id']);
            $table->dropIndex(['occurred_at']);
            $table->dropIndex(['module']);
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['object_type', 'object_id']);
            $table->dropColumn([
                'uuid', 'occurred_at', 'actor_type', 'actor_name', 'habilitation',
                'organization_unit_id', 'exercise_year', 'module', 'entity_reference',
                'changed_fields', 'correlation_id', 'causation_id', 'request_id',
                'sensitivity', 'channel', 'user_agent', 'source_table', 'source_id', 'context',
            ]);
        });
    }
};
