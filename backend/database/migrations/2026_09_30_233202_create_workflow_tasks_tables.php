<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('fingerprint', 64)->index();
            $table->string('module', 32);
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('dossier_reference');
            $table->string('subject');
            $table->string('action', 64);
            $table->string('step', 64)->nullable();
            $table->string('assigned_role', 64)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority', 16)->default('normale');
            $table->string('status', 32)->default('a_traiter');
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('objet')->nullable();
            $table->string('demandeur')->nullable();
            $table->string('structure')->nullable();
            $table->string('lien')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_action')->nullable();
            $table->timestamps();
            $table->index(['module', 'entity_id']);
        });

        Schema::create('workflow_task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_task_comments');
        Schema::dropIfExists('workflow_tasks');
    }
};
