<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapports de performance S&E (description §61-64, §76-77, §82) : snapshot
 * figé à la génération, circuit génération → revue → validation →
 * publication, PDF officiel archivé à la publication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('kind', 32);
            $table->string('title');
            $table->foreignId('monitoring_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('situation_au');
            $table->string('status', 16)->default('brouillon');
            $table->json('snapshot');
            $table->text('commentaire')->nullable();
            $table->string('return_motif')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('performance_reports')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['reference', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reports');
    }
};
