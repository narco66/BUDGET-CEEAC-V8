<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->unsignedBigInteger('montant_degage')->default(0)->after('montant');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_motif')->nullable();
        });

        Schema::create('engagement_degagements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('montant');
            $table->unsignedBigInteger('engage_net_avant');
            $table->string('motif');
            $table->string('acte', 100)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_degagements');
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropColumn(['montant_degage', 'cancelled_at', 'cancellation_motif']);
        });
    }
};
