<?php

namespace App\Console\Commands;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\Revenues\Models\MemberState;
use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueContribution;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ceeac:importer-2026')]
#[Description('Importe l’organigramme et le budget voté 2026 de la Commission')]
class ImporterCeeac2026 extends Command
{
    public function handle(): int
    {
        $budget = json_decode((string) file_get_contents(database_path('data/budget-ceeac-2026.json')), true);
        if (! is_array($budget)) {
            $this->error('Le fichier du budget 2026 est illisible.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($budget): void {
            $this->renameLegacyUnits();
            $units = $this->importOrganigramme();
            $this->placeUsers($units);
            $exercice = Exercice::query()->firstOrCreate(
                ['annee' => 2026],
                ['statut' => 'executoire', 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31'],
            );
            $codes = $this->importExpenses($exercice, $units, $budget['expenses']);
            BudgetLine::query()
                ->where('exercice_id', $exercice->id)
                ->whereNotIn('code', $codes)
                ->update(['officiel' => false]);
            $this->importReceipts($exercice, $units, $budget['contributions'], $budget['dons']);
        });

        $this->info('Organigramme et budget 2026 importés.');

        return self::SUCCESS;
    }

    private function renameLegacyUnits(): void
    {
        $renames = [
            ['PRES', 'departement', 'DPRES'],
            ['SG', 'departement', 'DSG'],
            ['DATI', 'direction', 'DATI-DENER'],
            ['DSI', 'direction', 'DSG-DSI'],
            ['DMC', 'direction', 'DMCAEMF-DMC'],
            ['DPS', 'direction', 'DAPPS-DMARAC'],
            ['DAJ', 'direction', 'DPRES-CAB-BCJ'],
            ['DRH', 'direction', 'DSG-DRHMG'],
            ['DPL', 'direction', 'DSG-DRHMG-SMG'],
            ['DB', 'direction', 'DSG-DPPB-SB'],
        ];
        foreach ($renames as [$from, $kind, $to]) {
            OrganizationUnit::query()
                ->where('sigle', $from)
                ->where('kind', $kind)
                ->where('sigle', '!=', $to)
                ->update(['sigle' => $to]);
        }
    }

    /**
     * @return array<string, OrganizationUnit>
     */
    private function importOrganigramme(): array
    {
        /** @var list<array<string, mixed>> $tree */
        $tree = require database_path('data/organigramme-ceeac-2026.php');
        $units = [];
        $this->walk($tree, null, $units);

        return $units;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, OrganizationUnit>  $units
     */
    private function walk(array $nodes, ?OrganizationUnit $parent, array &$units): void
    {
        foreach ($nodes as $node) {
            $unit = OrganizationUnit::query()->firstOrNew(['sigle' => $node['sigle']]);
            $unit->fill([
                'parent_id' => $parent?->id,
                'name' => $node['name'],
                'kind' => $node['kind'],
                'is_technical' => (bool) $node['technical'],
            ]);
            $unit->save();
            $units[$unit->sigle] = $unit;
            $this->walk($node['children'] ?? [], $unit, $units);
        }
    }

    /**
     * @param  array<string, OrganizationUnit>  $units
     */
    private function placeUsers(array $units): void
    {
        $places = [
            'clarisse.ndong@ceeac.int' => 'DATI-DENER',
            'jp.okombi@ceeac.int' => 'DATI-DENER',
            'serge.mabika@ceeac.int' => 'DATI',
            'aline.moussavou@ceeac.int' => 'DSG',
            'ordonnateur@ceeac.int' => 'DPRES',
            'dsi.initiateur@ceeac.int' => 'DSG-DSI',
            'dsi.directeur@ceeac.int' => 'DSG-DSI',
            'dmc.initiateur@ceeac.int' => 'DMCAEMF-DMC',
            'dmc.directeur@ceeac.int' => 'DMCAEMF-DMC',
            'dmc.commissaire@ceeac.int' => 'DMCAEMF',
            'dps.initiateur@ceeac.int' => 'DAPPS-DMARAC',
            'dps.directeur@ceeac.int' => 'DAPPS-DMARAC',
            'dps.commissaire@ceeac.int' => 'DAPPS',
            'daj.initiateur@ceeac.int' => 'DPRES-CAB-BCJ',
            'daj.directeur@ceeac.int' => 'DPRES-CAB-BCJ',
            'drh.initiateur@ceeac.int' => 'DSG-DRHMG',
            'drh.directeur@ceeac.int' => 'DSG-DRHMG',
            'dpl.initiateur@ceeac.int' => 'DSG-DRHMG-SMG',
            'dpl.directeur@ceeac.int' => 'DSG-DRHMG',
            'chef.budget@ceeac.int' => 'DSG-DPPB-SB',
            'directeur.budget@ceeac.int' => 'DSG-DPPB',
            'controleur.financier@ceeac.int' => 'DPRES-CFC',
            'rita.obame@ceeac.int' => 'DPRES-ACC',
            'marc.ndzie@ceeac.int' => 'DPRES-ACC',
            'paul.nguema@ceeac.int' => 'DPRES-ACC',
            'blaise.essono@ceeac.int' => 'DSG-DPPB-SB',
        ];
        foreach ($places as $email => $sigle) {
            if (! isset($units[$sigle])) {
                continue;
            }
            User::query()->where('email', $email)->update(['organization_unit_id' => $units[$sigle]->id]);
        }
    }

    /**
     * @param  array<string, OrganizationUnit>  $units
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function importExpenses(Exercice $exercice, array $units, array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $sigle = $this->structureFor($code);
            $unit = $units[$sigle] ?? $units['DSG'] ?? reset($units);
            $nature = ($row['nature'] ?? '') === 'pap' ? BudgetNature::Pap : BudgetNature::HorsPap;
            $line = BudgetLine::query()->firstOrNew([
                'exercice_id' => $exercice->id,
                'code' => $code,
            ]);
            $line->fill([
                'organization_unit_id' => $unit->id,
                'label' => mb_substr((string) $row['label'], 0, 255),
                'nature' => $nature,
                'chapitre' => mb_substr($code, 0, 3),
                'article' => mb_strlen($code) >= 4 ? mb_substr($code, 0, 4) : null,
                'paragraphe' => mb_strlen($code) >= 5 ? mb_substr($code, 0, 5) : null,
                'nature_depense' => $this->natureDepense($code),
                'montant_vote' => (int) $row['montant'],
                'montant_ceeac' => (int) $row['ceeac'],
                'montant_ptf' => (int) $row['ptf'],
                'officiel' => true,
            ]);
            if (! $line->exists) {
                $line->ajustements = 0;
            }
            $line->save();
            if ($nature === BudgetNature::Pap) {
                $enrichment = PapEnrichment::query()->firstOrNew(['budget_line_id' => $line->id]);
                $enrichment->fill([
                    'status' => $enrichment->status ?: 'a_completer',
                    'pilier' => mb_substr((string) ($row['pilier'] ?? ''), 0, 255),
                    'axe' => mb_substr((string) ($row['axe'] ?? ''), 0, 255),
                    'activite' => mb_substr((string) $row['label'], 0, 255),
                    'unite_responsable' => $unit->sigle,
                    'periode' => '2026',
                ]);
                $enrichment->save();
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @param  array<string, OrganizationUnit>  $units
     * @param  list<array<string, mixed>>  $contributions
     * @param  list<array<string, mixed>>  $dons
     */
    private function importReceipts(Exercice $exercice, array $units, array $contributions, array $dons): void
    {
        $states = ['AO', 'BI', 'CM', 'CF', 'CG', 'CD', 'GA', 'GQ', 'RW', 'ST', 'TD'];
        $basis = array_sum(array_map(fn (array $row): int => (int) $row['montant'], $contributions));
        $quotes = [];
        $assigned = 0;
        foreach ($contributions as $index => $row) {
            $quote = $index === array_key_last($contributions)
                ? 10000 - $assigned
                : (int) round(((int) $row['montant']) * 10000 / max(1, $basis));
            $quotes[] = $quote;
            $assigned += $quote;
        }
        foreach ($contributions as $index => $row) {
            $state = MemberState::query()->where('code', $states[$index] ?? '')->first();
            if ($state === null) {
                continue;
            }
            RevenueContribution::query()->updateOrCreate(
                ['exercice_id' => $exercice->id, 'member_state_id' => $state->id],
                [
                    'quote_part' => $quotes[$index],
                    'montant_attendu' => (int) $row['montant'],
                    'echeance' => '2026-12-31',
                    'observations' => 'Quote-part votée, annexe 1 du budget 2026.',
                ],
            );
        }

        $author = User::query()->where('email', 'directeur.budget@ceeac.int')->first() ?? User::query()->first();
        $category = RevenueCategory::query()->where('code', 'DON')->first();
        $unit = $units['DSG-DCMR'] ?? null;
        if ($author === null || $category === null) {
            return;
        }
        foreach ($dons as $row) {
            RevenueForecast::query()->updateOrCreate(
                ['exercice_id' => $exercice->id, 'code' => (string) $row['code']],
                [
                    'category_id' => $category->id,
                    'organization_unit_id' => $unit?->id,
                    'label' => mb_substr((string) $row['label'], 0, 255),
                    'description' => 'Don ou financement inscrit à l’annexe 1 du budget 2026.',
                    'montant' => (int) $row['montant'],
                    'source_label' => (string) $row['label'],
                    'periode' => '2026',
                    'date_prevue' => '2026-12-31',
                    'statut' => 'valide',
                    'author_id' => $author->id,
                    'validated_by' => $author->id,
                ],
            );
        }
    }

    private function structureFor(string $code): string
    {
        return match (true) {
            str_starts_with($code, '64411') => 'DPRES-BL-CF',
            str_starts_with($code, '64412') => 'DPRES-BL-CD',
            str_starts_with($code, '64413') => 'DPRES-BL-UA',
            str_starts_with($code, '64414') => 'DPRES-BL-BI',
            str_starts_with($code, '64415') => 'DPRES-BL-CG',
            str_starts_with($code, '64416') => 'DPRES-BL-TD',
            str_starts_with($code, '64417') => 'DPRES-BL-GQ',
            str_starts_with($code, '64418') => 'DPRES-BL-RW',
            str_starts_with($code, '644') => 'DPRES-BL',
            str_starts_with($code, '206233'), str_starts_with($code, '206234'), str_starts_with($code, '206235'), str_starts_with($code, '213'), str_starts_with($code, '6143') => 'DSG-DSI',
            str_starts_with($code, '20631'), str_starts_with($code, '206301'), str_starts_with($code, '617'), str_starts_with($code, '618') => 'DSG-DCRPP',
            str_starts_with($code, '20632') => 'DSG-DPPB',
            str_starts_with($code, '20634'), str_starts_with($code, '20635') => 'DPRES-CFC',
            str_starts_with($code, '2064') => 'DSG-DCMR',
            str_starts_with($code, '2061') => 'DPRES-CAB-BCJ',
            str_starts_with($code, '20621'), str_starts_with($code, '66') => 'DSG-DRHMG',
            str_starts_with($code, '20623') => 'DSG-DRHMG-SMG',
            str_starts_with($code, '206') => 'DSG',
            str_starts_with($code, '20321'), str_starts_with($code, '20322'), str_starts_with($code, '20323') => 'DATI-DENER',
            str_starts_with($code, '2031') => 'DATI-DATT',
            str_starts_with($code, '2033') => 'DATI-DPTEN',
            str_starts_with($code, '203') => 'DATI',
            str_starts_with($code, '201') => 'DAPPS',
            str_starts_with($code, '2021') => 'DMCAEMF-DMC',
            str_starts_with($code, '2022'), str_starts_with($code, '2024') => 'DMCAEMF-DPES',
            str_starts_with($code, '2023') => 'DMCAEMF-DAEM',
            str_starts_with($code, '202') => 'DMCAEMF',
            str_starts_with($code, '2041') => 'DENRADR-DADR',
            str_starts_with($code, '2042') => 'DENRADR-DERN',
            str_starts_with($code, '204') => 'DENRADR',
            str_starts_with($code, '2051') => 'DPGDHS-DSAS',
            str_starts_with($code, '2052') => 'DPGDHS-DJSE',
            str_starts_with($code, '2053') => 'DPGDHS-DGPF',
            str_starts_with($code, '2054') => 'DPGDHS-DECDT',
            str_starts_with($code, '205') => 'DPGDHS',
            str_starts_with($code, '209'), str_starts_with($code, '67') => 'DPRES',
            str_starts_with($code, '60'), str_starts_with($code, '61'), str_starts_with($code, '23'), str_starts_with($code, '24') => 'DSG-DRHMG-SMG',
            default => 'DSG',
        };
    }

    private function natureDepense(string $code): string
    {
        return match (true) {
            str_starts_with($code, '66'), str_starts_with($code, '60'), str_starts_with($code, '61'), str_starts_with($code, '64'), str_starts_with($code, '67') => 'Fonctionnement',
            default => 'Investissement',
        };
    }
}
