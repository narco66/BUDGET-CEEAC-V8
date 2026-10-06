<?php

namespace Database\Seeders;

use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PaiementSeeder extends Seeder
{
    public function run(): void
    {
        $unit = OrganizationUnit::query()->where('sigle', 'SG')->first() ?? OrganizationUnit::query()->first();
        $comptable = $this->user($unit, 'Rita OBAME', 'rita.obame@ceeac.int', 'Comptable', 'comptable', 'RO');
        $this->user($unit, 'Marc NDZIE', 'marc.ndzie@ceeac.int', 'Chef Comptable', 'chef_comptable', 'MN');
        $agent = $this->user($unit, 'Paul NGUEMA', 'paul.nguema@ceeac.int', 'Agent Comptable', 'agent_comptable', 'PN');

        Paiement::query()
            ->where('status', PaiementStatus::Genere->value)
            ->whereNull('mode')
            ->with('ordonnancement')
            ->get()
            ->each(function (Paiement $paiement) use ($comptable, $agent): void {
                $ordre = $paiement->ordonnancement?->reference;
                if ($ordre === 'ORD-2026-000001') {
                    $paiement->forceFill([
                        'status' => PaiementStatus::Cloture,
                        'workflow_step' => 'clos',
                        'expected_actor_label' => '—',
                        'mode' => 'virement',
                        'titulaire' => $paiement->ordonnancement?->liquidation?->fournisseur,
                        'montant_paye' => $paiement->montant,
                        'pris_en_charge_at' => now()->subDays(4),
                        'pris_en_charge_par' => $comptable->id,
                        'validated_at' => now()->subDays(3),
                        'signed_at' => now()->subDays(2),
                        'signed_by' => $agent->id,
                        'date_valeur' => now()->subDay()->toDateString(),
                        'reference_reglement' => 'REG-'.$paiement->reference,
                        'reconciled_at' => now(),
                        'reconciliation_reference' => 'REL-'.$paiement->reference,
                        'last_action' => 'Rapproché REL-'.$paiement->reference,
                    ])->save();
                    PaiementExecution::query()->firstOrCreate(
                        ['idempotence_key' => 'PAY-EXEC-'.$paiement->id.'-REG-'.$paiement->reference],
                        [
                            'paiement_id' => $paiement->id,
                            'rang' => 1,
                            'montant' => $paiement->montant,
                            'reference_reglement' => 'REG-'.$paiement->reference,
                            'mode' => 'virement',
                            'date_valeur' => now()->subDay()->toDateString(),
                            'status' => PaiementExecution::EXECUTEE,
                            'actor_id' => $comptable->id,
                        ],
                    );
                }
                if ($ordre !== 'ORD-2026-000001' && $ordre !== 'ORD-2026-000090' && $paiement->expected_actor_label === null) {
                    $paiement->forceFill([
                        'expected_actor_label' => 'Comptable · Agence Comptable',
                        'last_action' => 'Transmis depuis '.$ordre,
                        'due_on' => now()->addDays(3)->toDateString(),
                    ])->save();
                }
                if ($ordre === 'ORD-2026-000090') {
                    $paiement->forceFill([
                        'status' => PaiementStatus::EnPreparation,
                        'workflow_step' => 'comptable',
                        'expected_actor_label' => $comptable->name,
                        'pris_en_charge_at' => now()->subDay(),
                        'pris_en_charge_par' => $comptable->id,
                        'last_action' => 'Pris en charge par '.$comptable->name,
                        'due_on' => now()->addDays(2)->toDateString(),
                    ])->save();
                }
            });
    }

    private function user(?OrganizationUnit $unit, string $name, string $email, string $function, string $role, string $initials): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'organization_unit_id' => $unit?->id,
                'name' => $name,
                'function_title' => $function,
                'role' => $role,
                'initials' => $initials,
                'password' => Hash::make('password'),
            ],
        );
    }
}
