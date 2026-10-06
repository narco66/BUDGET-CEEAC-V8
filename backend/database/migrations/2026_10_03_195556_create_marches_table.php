<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('objet');
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->unsignedBigInteger('montant')->default(0);
            $table->string('procedure', 32);
            $table->string('statut', 32)->default('projet');
            $table->date('notified_on')->nullable();
            $table->foreignId('engagement_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marches');
    }
};
