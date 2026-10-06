<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordonnancements', function (Blueprint $table) {
            $table->string('empreinte', 64)->nullable()->after('signature_reference');
            $table->string('signature_version', 16)->nullable()->after('empreinte');
            $table->json('transmission_journal')->nullable()->after('transmission_error');
        });

        Schema::create('ord_suppleances', function (Blueprint $table) {
            $table->id();
            $table->string('titulaire');
            $table->string('suppleant');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('fondement');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_suppleances');
        Schema::table('ordonnancements', function (Blueprint $table) {
            $table->dropColumn(['empreinte', 'signature_version', 'transmission_journal']);
        });
    }
};
