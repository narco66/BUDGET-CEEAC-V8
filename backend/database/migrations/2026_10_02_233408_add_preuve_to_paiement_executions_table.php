<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_executions', function (Blueprint $table) {
            $table->string('preuve_nom')->nullable();
            $table->string('preuve_chemin')->nullable();
            $table->string('preuve_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paiement_executions', function (Blueprint $table) {
            $table->dropColumn(['preuve_nom', 'preuve_chemin', 'preuve_sha256']);
        });
    }
};
