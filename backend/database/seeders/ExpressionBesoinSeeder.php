<?php

namespace Database\Seeders;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\EbDocument;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ExpressionBesoinSeeder extends Seeder
{
    public function run(): void
    {
        $exercice = Exercice::query()->create([
            'annee' => 2026,
            'statut' => 'executoire',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
        ]);

        $departments = [
            'DATI' => OrganizationUnit::query()->create(['sigle' => 'DATI-DEP', 'name' => 'Département des infrastructures', 'kind' => 'departement', 'is_technical' => true]),
            'SG' => OrganizationUnit::query()->create(['sigle' => 'SG', 'name' => 'Secrétariat Général', 'kind' => 'departement', 'is_technical' => false]),
            'DMCAEMF' => OrganizationUnit::query()->create(['sigle' => 'DMCAEMF', 'name' => 'Département du marché commun', 'kind' => 'departement', 'is_technical' => true]),
            'DAPPS' => OrganizationUnit::query()->create(['sigle' => 'DAPPS', 'name' => 'Département paix et sécurité', 'kind' => 'departement', 'is_technical' => true]),
            'PRES' => OrganizationUnit::query()->create(['sigle' => 'PRES', 'name' => 'Présidence', 'kind' => 'departement', 'is_technical' => false]),
        ];

        $directions = [
            'DATI' => $this->direction($departments['DATI'], 'DATI', 'Direction de l’Énergie', true),
            'DSI' => $this->direction($departments['SG'], 'DSI', 'Direction des Systèmes d’information', false),
            'DMC' => $this->direction($departments['DMCAEMF'], 'DMC', 'Marché commun', true),
            'DPS' => $this->direction($departments['DAPPS'], 'DPS', 'Paix et Sécurité', true),
            'DAJ' => $this->direction($departments['SG'], 'DAJ', 'Direction des Affaires juridiques', false),
            'DRH' => $this->direction($departments['SG'], 'DRH', 'Direction des Ressources humaines', false),
            'DPL' => $this->direction($departments['SG'], 'DPL', 'Patrimoine et Logistique', false),
        ];

        $clarisse = $this->user($directions['DATI'], 'Clarisse NDONG', 'clarisse.ndong@ceeac.int', 'Chef de Service', 'initiateur', 'CN');
        $this->user($directions['DATI'], 'Jean-Pierre OKOMBI', 'jp.okombi@ceeac.int', 'Directeur', 'directeur', 'JO');
        $this->user($directions['DATI'], 'Serge MABIKA', 'serge.mabika@ceeac.int', 'Commissaire', 'commissaire', 'SM');
        $this->user($departments['SG'], 'Aline MOUSSAVOU', 'aline.moussavou@ceeac.int', 'Secrétaire Général', 'secretaire_general', 'AM');
        $this->user($departments['PRES'], 'Président de la Commission', 'ordonnateur@ceeac.int', 'Ordonnateur principal', 'ordonnateur', 'PC');

        $initiators = ['DATI' => $clarisse];
        foreach (['DSI', 'DMC', 'DPS', 'DAJ', 'DRH', 'DPL'] as $sigle) {
            $initiators[$sigle] = $this->user($directions[$sigle], 'Initiateur '.$sigle, strtolower($sigle).'.initiateur@ceeac.int', 'Chef de Service', 'initiateur', $sigle[0].'I');
            $this->user($directions[$sigle], 'Directeur '.$sigle, strtolower($sigle).'.directeur@ceeac.int', 'Directeur', 'directeur', $sigle[0].'D');
            if ($directions[$sigle]->is_technical) {
                $this->user($directions[$sigle], 'Commissaire '.$sigle, strtolower($sigle).'.commissaire@ceeac.int', 'Commissaire', 'commissaire', $sigle[0].'C');
            }
        }

        $lines = [
            '203232' => $this->line($exercice, $directions['DATI'], '203232', 'Suivi et coordination régionale des projets énergétiques', BudgetNature::Pap, 50_000_000),
            '310101' => $this->line($exercice, $directions['DSI'], '310101', 'Équipements bureautiques et informatiques', BudgetNature::HorsPap, 200_000_000),
            '410234' => $this->line($exercice, $directions['DMC'], '410234', 'Promotion de l’intégration économique', BudgetNature::Pap, 900_000_000),
            '420156' => $this->line($exercice, $directions['DPS'], '420156', 'Missions de suivi et d’évaluation paix et sécurité', BudgetNature::Pap, 150_000_000),
            '220345' => $this->line($exercice, $directions['DAJ'], '220345', 'Abonnements et documentation', BudgetNature::HorsPap, 40_000_000),
            '430234' => $this->line($exercice, $directions['DRH'], '430234', 'Formation et renforcement des capacités', BudgetNature::Pap, 120_000_000),
            '310456' => $this->line($exercice, $directions['DPL'], '310456', 'Travaux d’aménagement et de réhabilitation', BudgetNature::HorsPap, 500_000_000),
            '410189' => $this->line($exercice, $directions['DMC'], '410189', 'Études et expertises économiques', BudgetNature::Pap, 300_000_000),
        ];

        $this->enrich($lines['203232'], 80, 'Mise en place du système de suivi régional', [
            'Analyse des besoins', 'Conception fonctionnelle', 'Développement du système', 'Acquisition d’équipements', 'Formation', 'Déploiement',
        ]);
        $this->enrich($lines['410234'], 100, 'Organisation du forum régional', ['Préparation', 'Logistique', 'Tenue du forum']);
        $this->enrich($lines['420156'], 60, 'Mission d’évaluation ECCAS-PEACE', ['Cadrage', 'Mission terrain']);
        $this->enrich($lines['430234'], 90, 'Formation GAR', ['Conception pédagogique', 'Sessions']);
        $this->enrich($lines['410189'], 40, 'Étude zone de libre-échange', ['Termes de référence']);

        $dossiers = [
            ['102', 'DMC', '410189', 234_500_000, EbStatus::Rejetee, 'clos', '—', null, 'Recrutement d’experts — étude sur la zone de libre-échange', '2026-06-28'],
            ['109', 'DPL', '310456', 345_000_000, EbStatus::Approuvee, 'ordonnateur', 'Génération de l’Engagement', null, 'Réhabilitation de la salle de conférence principale — Siège', '2026-07-15'],
            ['115', 'DRH', '430234', 80_000_000, EbStatus::Soumise, 'directeur', 'Directeur DRH', '2026-10-06', 'Formation en gestion budgétaire axée sur les résultats', null],
            ['118', 'DAJ', '220345', 23_400_000, EbStatus::Brouillon, 'initiateur', 'Initiateur', null, 'Abonnements aux bases de données juridiques et documentaires', null],
            ['119', 'DPS', '420156', 78_500_000, EbStatus::Retournee, 'initiateur', 'Initiateur · à corriger', null, 'Mission d’évaluation à mi-parcours du programme ECCAS-PEACE', '2026-08-05'],
            ['124', 'DMC', '410234', 456_000_000, EbStatus::Transformee, 'clos', 'ENG-2026-003891', null, 'Organisation du 5e Forum régional sur l’intégration économique', '2026-08-20'],
            ['126', 'DSI', '310101', 125_000_000, EbStatus::EnApprobation, 'ordonnateur', 'Ordonnateur', '2026-09-26', 'Acquisition de matériel informatique — postes et serveurs', null],
            ['127', 'DATI', '203232', 8_500_000, EbStatus::EnValidation, 'commissaire', 'Commissaire DATI', '2026-10-01', 'Mise en place du système de suivi régional des projets énergétiques', null],
        ];

        foreach ($dossiers as [$seq, $sigle, $code, $amount, $status, $step, $actor, $due, $objet, $stamp]) {
            $eb = ExpressionBesoin::query()->create([
                'reference' => sprintf('EB/2026/%s/%06d', $sigle, $seq),
                'exercice_id' => $exercice->id,
                'organization_unit_id' => $directions[$sigle]->id,
                'initiator_id' => $initiators[$sigle]->id,
                'budget_line_id' => $lines[$code]->id,
                'nature' => $lines[$code]->nature,
                'objet' => $objet,
                'contexte' => 'Besoin exprimé au titre de l’exercice 2026, rattaché à la ligne budgétaire officielle.',
                'justification' => 'Le besoin concourt à l’exécution du Budget adopté et ne peut être différé sans affecter le programme de travail.',
                'urgence' => 'normale',
                'priorite' => 'haute',
                'resultats_attendus' => 'Le résultat attendu est livré dans le délai et le plafond de la ligne.',
                'status' => $status,
                'workflow_step' => $step,
                'expected_actor_label' => $actor,
                'due_on' => $due,
                'montant' => $amount,
                'submitted_at' => $status === EbStatus::Brouillon ? null : '2026-06-01 09:00:00',
                'approved_at' => in_array($status, [EbStatus::Approuvee, EbStatus::Transformee], true) ? $stamp.' 10:00:00' : null,
                'returned_at' => $status === EbStatus::Retournee ? $stamp.' 11:00:00' : null,
                'rejected_at' => $status === EbStatus::Rejetee ? $stamp.' 16:00:00' : null,
                'return_motif' => $status === EbStatus::Retournee ? 'Justification insuffisante des coûts' : null,
                'rejection_motif' => $status === EbStatus::Rejetee ? 'Crédit incompatible avec la programmation' : null,
            ]);

            $eb->lines()->create([
                'position' => 1,
                'designation' => $objet,
                'quantite' => 1,
                'unite' => 'forfait',
                'prix_unitaire' => $amount,
                'montant' => $amount,
            ]);
            $eb->imputations()->create([
                'budget_line_id' => $lines[$code]->id,
                'montant' => $amount,
            ]);
            $eb->documents()->create([
                'uploaded_by' => $initiators[$sigle]->id,
                'type' => 'Note justificative',
                'original_name' => 'note-justificative.pdf',
                'confidentialite' => 'interne',
            ]);
            $eb->events()->create([
                'actor_id' => $initiators[$sigle]->id,
                'action' => 'creation',
                'to_status' => EbStatus::Brouillon->value,
            ]);

            if ($seq === '124') {
                Engagement::query()->create([
                    'reference' => 'ENG-2026-003891',
                    'expression_besoin_id' => $eb->id,
                    'budget_line_id' => $lines[$code]->id,
                    'montant' => $amount,
                ]);
            }

            if ($seq === '127') {
                $eb->lines()->delete();
                $parts = [
                    ['Analyse des besoins', 2_000_000],
                    ['Conception fonctionnelle', 2_500_000],
                    ['Formation', 4_000_000],
                ];
                foreach ($parts as $index => [$label, $price]) {
                    $eb->lines()->create([
                        'position' => $index + 1,
                        'designation' => $label,
                        'quantite' => 1,
                        'unite' => 'forfait',
                        'prix_unitaire' => $price,
                        'montant' => $price,
                    ]);
                }
                EbDocument::query()->create([
                    'expression_besoin_id' => $eb->id,
                    'uploaded_by' => $clarisse->id,
                    'type' => 'TDR',
                    'original_name' => 'tdr-suivi-energetique.pdf',
                ]);
            }
        }
    }

    private function direction(OrganizationUnit $parent, string $sigle, string $name, bool $technical): OrganizationUnit
    {
        return OrganizationUnit::query()->create([
            'parent_id' => $parent->id,
            'sigle' => $sigle,
            'name' => $name,
            'kind' => 'direction',
            'is_technical' => $technical,
        ]);
    }

    private function user(OrganizationUnit $unit, string $name, string $email, string $function, string $role, string $initials): User
    {
        return User::query()->create([
            'organization_unit_id' => $unit->id,
            'name' => $name,
            'email' => $email,
            'function_title' => $function,
            'role' => $role,
            'initials' => $initials,
            'password' => Hash::make('password'),
        ]);
    }

    private function line(Exercice $exercice, OrganizationUnit $unit, string $code, string $label, BudgetNature $nature, int $amount): BudgetLine
    {
        return BudgetLine::query()->create([
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unit->id,
            'code' => $code,
            'label' => $label,
            'nature' => $nature,
            'chapitre' => substr($code, 0, 2),
            'article' => substr($code, 2, 2),
            'paragraphe' => substr($code, 4, 2),
            'nature_depense' => $nature === BudgetNature::Pap ? 'Investissement' : 'Fonctionnement',
            'montant_vote' => $amount,
            'ajustements' => 0,
        ]);
    }

    /**
     * @param  list<string>  $tasks
     */
    private function enrich(BudgetLine $line, int $target, string $activity, array $tasks): void
    {
        $flags = [
            'pilier' => 'Intégration régionale et infrastructures',
            'axe' => 'Coordination des programmes sectoriels',
            'produit' => 'Prestation livrée conformément au Budget',
            'activite' => $activity,
            'resultats_attendus' => 'Le résultat programmé est atteint sur l’exercice.',
            'indicateur' => 'Taux de réalisation de l’activité',
            'cible' => '100 %',
            'periode' => 'Janvier – décembre 2026',
            'beneficiaires' => 'États membres et services de la Commission',
            'unite_responsable' => $line->organizationUnit->name,
        ];

        $optional = ['pilier', 'axe', 'produit', 'resultats_attendus', 'indicateur', 'cible', 'periode', 'beneficiaires'];
        $missing = (int) round((100 - $target) / 10);
        foreach (array_slice($optional, 0, $missing) as $key) {
            $flags[$key] = null;
        }

        $enrichment = PapEnrichment::query()->create([
            'budget_line_id' => $line->id,
            'status' => $target >= 80 ? 'validee' : 'a_completer',
            ...$flags,
        ]);

        foreach ($tasks as $index => $label) {
            PapTask::query()->create([
                'pap_enrichment_id' => $enrichment->id,
                'position' => $index + 1,
                'label' => $label,
                'proposed' => false,
                'validated' => true,
            ]);
        }
    }
}
