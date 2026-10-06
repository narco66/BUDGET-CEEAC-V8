<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->string('nature', 16);
            $table->unsignedBigInteger('montant_propose')->default(0);
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['exercice_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_proposals');
    }
};
