<?php

use App\Domains\Administration\Services\ChainWorkflowCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('statut', 32)->default('demande');
            $table->foreignId('demandeur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('validateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        $this->replaceSketchSteps();
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_closes');
    }

    private function replaceSketchSteps(): void
    {
        $definition = DB::table('workflow_definitions')->where('code', 'chaine-depense')->first();
        if ($definition === null) {
            return;
        }
        $version = DB::table('workflow_versions')
            ->where('workflow_definition_id', $definition->id)
            ->where('status', 'actif')
            ->orderByDesc('version')
            ->first();
        if ($version === null) {
            return;
        }
        $codes = DB::table('workflow_steps')->where('workflow_version_id', $version->id)->pluck('code');
        if ($codes->contains('eng.expert_budget')) {
            return;
        }
        DB::table('workflow_steps')->where('workflow_version_id', $version->id)->delete();
        $now = now();
        foreach (ChainWorkflowCatalog::CANONICAL as $index => [$code, $label, $role]) {
            DB::table('workflow_steps')->insert([
                'workflow_version_id' => $version->id,
                'ordre' => $index + 1,
                'code' => $code,
                'label' => $label,
                'actor_role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
