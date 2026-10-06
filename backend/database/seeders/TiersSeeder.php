<?php

namespace Database\Seeders;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Suppliers\Models\Tiers;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Référentiel tiers de démonstration : une fiche par bénéficiaire présent
 * dans la chaîne, avec un compte validé hors période de vigilance, saisi par
 * le Comptable et validé par le Chef Comptable. Les engagements sont
 * rattachés à leur tiers.
 */
class TiersSeeder extends Seeder
{
    public function run(): void
    {
        $comptable = User::query()->where('role', 'comptable')->first();
        $chef = User::query()->where('role', 'chef_comptable')->first();

        $names = Engagement::query()->whereNotNull('beneficiary_name')->pluck('beneficiary_name')
            ->merge(Liquidation::query()->whereNotNull('fournisseur')->pluck('fournisseur'))
            ->filter()
            ->unique(fn (string $name) => Tiers::normalize($name))
            ->values();

        foreach ($names as $index => $name) {
            $normalized = Tiers::normalize($name);
            $tiers = Tiers::query()->firstOrCreate(
                ['nom_normalise' => $normalized],
                [
                    'code' => sprintf('TIE-%06d', Tiers::query()->count() + 1),
                    'type' => 'fournisseur',
                    'raison_sociale' => $name,
                    'nif' => sprintf('NIF-DEMO-%04d', $index + 1),
                    'pays' => 'Gabon',
                    'status' => 'actif',
                    'created_by' => $comptable?->id,
                ],
            );

            if (! $tiers->bankAccounts()->exists()) {
                $tiers->bankAccounts()->create([
                    'banque' => 'BGFI Bank Gabon',
                    'agence' => 'Libreville Centre',
                    'numero' => sprintf('GA21400010%012d', $tiers->id),
                    'titulaire' => $name,
                    'devise' => 'XAF',
                    'justificatif' => 'RIB certifié',
                    'status' => TiersBankAccount::VALIDE,
                    'created_by' => $comptable?->id,
                    'validated_by' => $chef?->id,
                    'validated_at' => now()->subDays(TiersBankAccount::VIGILANCE_DAYS + 30),
                ]);
            }

            Engagement::query()
                ->whereNull('tiers_id')
                ->get(['id', 'beneficiary_name'])
                ->filter(fn (Engagement $engagement) => filled($engagement->beneficiary_name) && Tiers::normalize($engagement->beneficiary_name) === $normalized)
                ->each(fn (Engagement $engagement) => $engagement->forceFill(['tiers_id' => $tiers->id])->save());
        }
    }
}
