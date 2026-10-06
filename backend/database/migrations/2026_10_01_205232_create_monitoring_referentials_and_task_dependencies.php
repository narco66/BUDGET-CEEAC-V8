<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pap_tasks', function (Blueprint $table) {
            $table->foreignId('depends_on_id')->nullable()->constrained('pap_tasks')->nullOnDelete();
        });

        Schema::table('indicators', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(1);
            $table->string('formula')->nullable();
        });

        Schema::create('se_referentials', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('code');
            $table->string('label');
            $table->decimal('weight', 5, 2)->nullable();
            $table->unsignedSmallInteger('formula_version')->default(1);
            $table->date('effective_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['kind', 'code']);
        });

        $now = now();
        $rows = [];
        foreach ([
            'administrative' => 'Administrative',
            'financiere' => 'Financière',
            'technique' => 'Technique',
            'organisationnelle' => 'Organisationnelle',
            'logistique' => 'Logistique',
            'institutionnelle' => 'Institutionnelle',
            'reglementaire' => 'Réglementaire',
            'externe' => 'Externe',
        ] as $code => $label) {
            $rows[] = ['kind' => 'cause', 'code' => $code, 'label' => $label, 'weight' => null, 'formula_version' => 1, 'effective_on' => '2026-01-01', 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ([
            'pertinence' => 'Pertinence',
            'coherence' => 'Cohérence',
            'efficacite' => 'Efficacité',
            'efficience' => 'Efficience',
            'impact' => 'Impact',
            'durabilite' => 'Durabilité',
        ] as $code => $label) {
            $rows[] = ['kind' => 'critere', 'code' => $code, 'label' => $label, 'weight' => null, 'formula_version' => 1, 'effective_on' => '2026-01-01', 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ([
            'physique' => [40, 'Avancement physique'],
            'financier' => [20, 'Exécution financière (payé / révisé)'],
            'delai' => [15, 'Respect des échéances'],
            'indicateurs' => [15, 'Taux d’atteinte des indicateurs'],
            'risques' => [5, 'Maîtrise des risques critiques'],
            'qualite' => [5, 'Absence d’écarts critiques'],
        ] as $code => [$weight, $label]) {
            $rows[] = ['kind' => 'score', 'code' => $code, 'label' => $label, 'weight' => $weight, 'formula_version' => 1, 'effective_on' => '2026-01-01', 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('se_referentials')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('se_referentials');
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['weight', 'formula']);
        });
        Schema::table('pap_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depends_on_id');
        });
    }
};
