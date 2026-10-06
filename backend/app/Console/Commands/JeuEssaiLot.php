<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('demo:lot {--executer : Sauvegarde, retire le lot confirmé, puis régénère le jeu d’essai}')]
#[Description('Aperçu ou remplacement du jeu de recette, sans toucher au budget officiel')]
class JeuEssaiLot extends Command
{
    private const LOT = 'JEU-2026-10-04';

    /** @var list<string> */
    private const LIGNES_DEMO = ['220345', '310101', '310456', '410189', '410234', '420156', '430234'];

    /** @var list<string> */
    private const OBJETS_SEEDER = [
        'Recrutement d’experts — étude sur la zone de libre-échange',
        'Réhabilitation de la salle de conférence principale — Siège',
        'Formation en gestion budgétaire axée sur les résultats',
        'Abonnements aux bases de données juridiques et documentaires',
        'Mission d’évaluation à mi-parcours du programme ECCAS-PEACE',
        'Organisation du 5e Forum régional sur l’intégration économique',
        'Acquisition de matériel informatique — postes et serveurs',
        'Mise en place du système de suivi régional des projets énergétiques',
        'Abonnements aux bases de données juridiques',
        'Étude complémentaire hors programmation',
        'Équipements — système de suivi énergétique',
        'Location de véhicules — mission régionale',
        'Licences logicielles de sécurité — renouvellement',
        'Fournitures de bureau — acompte',
        'Fournitures de bureau — T3',
    ];

    /** @var list<string> */
    private const JUSTIFICATIONS = [
        'Besoin exprimé au titre de l’exercice 2026, rattaché à la ligne budgétaire officielle.',
        'Besoin approuvé et transmis automatiquement en engagement.',
        'Besoin approuvé, engagé et transmis en liquidation.',
    ];

    /** @var list<string> */
    private const ENGAGEMENTS_SEEDER = [
        'ENG-2026-003891', 'ENG-2026-000455', 'ENG-2026-000457', 'ENG-2026-000461',
        'ENG-2026-000452', 'ENG-2026-000445', 'ENG-2026-000901', 'ENG-2026-000902',
        'ENG-2026-000903', 'ENG-2026-000904', 'ENG-2026-000905',
    ];

    /** @var list<string> */
    private array $fichiers = [];

    public function handle(): int
    {
        $refus = $this->garde();
        if ($refus !== null) {
            $this->error($refus);

            return self::FAILURE;
        }

        $avant = $this->empreinte();
        $plan = $this->plan();
        $this->afficher($plan, $avant);

        if (! $this->option('executer')) {
            $this->info('Aperçu seulement. Aucune suppression. Relancer avec --executer pour appliquer ce lot.');

            return self::SUCCESS;
        }

        try {
            $sauvegarde = $this->sauvegarder();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Sauvegarde : '.$sauvegarde);

        try {
            DB::transaction(function () use ($avant, $plan): void {
                $this->supprimer($plan);
                if ($this->empreinte() !== $avant) {
                    throw new RuntimeException('L’empreinte du budget officiel a changé. Suppression annulée.');
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Suppression interrompue, transaction annulée : '.$exception->getMessage());

            return self::FAILURE;
        }

        $retires = $this->retirerFichiers();
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        $generation = Artisan::call('demo:jeu-essai');
        $this->output->write(Artisan::output());
        $apres = $this->empreinte();
        $conserve = $apres === $avant;
        $this->journal($sauvegarde, $plan, $avant, $apres, $retires, $generation, $conserve);

        if (! $conserve) {
            $this->error('L’empreinte officielle diffère après génération. Restaurer '.$sauvegarde.' avant toute autre opération.');

            return self::FAILURE;
        }
        if ($generation !== 0) {
            $this->error('La génération du jeu d’essai a échoué. Le budget officiel est inchangé.');

            return self::FAILURE;
        }

        $this->info('Lot '.self::LOT.' en place. Budget officiel, organigramme et dons DON- inchangés.');

        return self::SUCCESS;
    }

    private function garde(): ?string
    {
        if (app()->environment('production')) {
            return 'Environnement de production : le lot de recette est refusé.';
        }

        $nom = (string) config('database.default');
        $config = config('database.connections.'.$nom);
        if (! is_array($config) || ($config['driver'] ?? '') !== 'pgsql') {
            return 'Le lot ne s’exécute que sur la connexion PostgreSQL de cette application.';
        }
        if (($config['database'] ?? '') !== 'budget_ceeac_v8') {
            return 'Base refusée ('.($config['database'] ?? 'inconnue').'). Seule budget_ceeac_v8 est autorisée.';
        }
        if (($config['host'] ?? '') !== '127.0.0.1' || (string) ($config['port'] ?? '') !== '5433') {
            return 'Connexion refusée : l’hôte ou le port ne correspond pas à la base locale attendue.';
        }

        return null;
    }

    /**
     * @return array<string, int|string|list<string>>
     */
    private function empreinte(): array
    {
        $lignes = DB::table('budget_lines')->where('officiel', true)->orderBy('code')->get(['code', 'montant_vote', 'ajustements']);
        $unites = DB::table('organization_units')->orderBy('sigle')->pluck('sigle');
        $dons = DB::table('revenue_forecasts')->where('code', 'like', 'DON-%')->orderBy('code')->get(['code', 'montant']);

        return [
            'lignes_officielles' => $lignes->count(),
            'vote' => (int) $lignes->sum('montant_vote'),
            'ajustements' => (int) $lignes->sum('ajustements'),
            'empreinte_lignes' => hash('sha256', $lignes->map(fn ($ligne): string => $ligne->code.'|'.$ligne->montant_vote.'|'.$ligne->ajustements)->implode("\n")),
            'unites' => $unites->count(),
            'empreinte_unites' => hash('sha256', $unites->implode('|')),
            'dons' => $dons->count(),
            'empreinte_dons' => hash('sha256', $dons->map(fn ($don): string => $don->code.'|'.$don->montant)->implode("\n")),
            'exercices' => DB::table('exercices')->orderBy('annee')->get(['annee', 'statut'])->map(fn ($exercice): string => $exercice->annee.':'.$exercice->statut)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        $lignesDemo = DB::table('budget_lines')->where('officiel', false)->whereIn('code', self::LIGNES_DEMO)->pluck('id');
        $besoins = DB::table('expression_besoins')
            ->where(function ($query) use ($lignesDemo): void {
                $query->where('objet', 'like', "Jeu d'essai%")
                    ->orWhere('objet', 'like', 'Jeu d’essai%')
                    ->orWhere('justification', 'like', 'Dossier de démonstration%')
                    ->orWhereIn('objet', self::OBJETS_SEEDER)
                    ->orWhereIn('justification', self::JUSTIFICATIONS)
                    ->orWhereIn('budget_line_id', $lignesDemo);
            })
            ->orderBy('reference')
            ->get(['id', 'reference', 'objet']);
        $incertains = $this->sauf('expression_besoins', 'id', $besoins->pluck('id'))->orderBy('reference')->get(['reference', 'objet']);

        $engagements = DB::table('engagements')
            ->where(function ($query) use ($besoins): void {
                $ids = $besoins->pluck('id');
                if ($ids->isNotEmpty()) {
                    $query->whereIn('expression_besoin_id', $ids);
                }
                $query->orWhereIn('reference', self::ENGAGEMENTS_SEEDER);
            })
            ->pluck('id');
        $engagementsGardes = $this->sauf('engagements', 'id', $engagements)->count();

        $liquidations = DB::table('liquidations')->whereIn('engagement_id', $engagements)->pluck('id');
        $ordres = DB::table('ordonnancements')->whereIn('liquidation_id', $liquidations)->pluck('id');
        $paiements = DB::table('paiements')->whereIn('ordonnancement_id', $ordres)->pluck('id');

        $previsions = DB::table('revenue_forecasts')->where('code', 'like', 'PRV-%')->pluck('id');
        $titres = DB::table('revenue_orders')
            ->where(function ($query) use ($previsions): void {
                $query->where('motif', 'like', "Jeu d'essai%")
                    ->orWhere('motif', 'like', 'Jeu d’essai%')
                    ->orWhere('motif', 'like', 'Audit recettes%')
                    ->orWhereIn('forecast_id', $previsions);
            })
            ->pluck('id');
        $titresGardes = $this->sauf('revenue_orders', 'id', $titres)->count();
        $previsionsLiees = $this->sauf('revenue_orders', 'id', $titres)->whereIn('forecast_id', $previsions)->pluck('forecast_id');
        $previsions = $previsions->diff($previsionsLiees)->values();

        $recusLies = DB::table('revenue_allocations')->whereIn('order_id', $titres)->pluck('receipt_id');
        $recusMarques = DB::table('revenue_receipts')
            ->where(function ($query): void {
                $query->where('reference_bancaire', 'like', 'VIR-REC-JEU-%')
                    ->orWhere('commentaire', 'like', '%jeu d%essai%')
                    ->orWhere('commentaire', 'like', '%Audit recettes%');
            })
            ->pluck('id');
        $recus = $recusLies->merge($recusMarques)->unique()->values();
        $allocations = DB::table('revenue_allocations')->whereIn('receipt_id', $recus);
        $recusProteges = ($titres->isEmpty() ? $allocations : $allocations->whereNotIn('order_id', $titres))->pluck('receipt_id');
        $recus = $recus->diff($recusProteges)->values();

        $tiers = DB::table('tiers')
            ->where(function ($query): void {
                $query->where('nif', 'like', 'NIF-DEMO-%')->orWhere('nif', 'like', 'JEU-NIF-%');
            })
            ->pluck('id');
        $tiersProteges = collect()
            ->merge($this->sauf('engagements', 'id', $engagements)->whereIn('tiers_id', $tiers)->pluck('tiers_id'))
            ->merge($this->sauf('revenue_orders', 'id', $titres)->whereIn('tiers_id', $tiers)->pluck('tiers_id'))
            ->merge(DB::table('marches')->where('objet', 'not like', "Jeu d'essai%")->where('objet', 'not like', 'Jeu d’essai%')->whereIn('tiers_id', $tiers)->pluck('tiers_id'))
            ->unique()->values();
        $tiers = $tiers->diff($tiersProteges)->values();

        $marches = DB::table('marches')
            ->where(function ($query): void {
                $query->where('objet', 'like', "Jeu d'essai%")->orWhere('objet', 'like', 'Jeu d’essai%');
            })
            ->pluck('id');
        $marchesGardes = $this->sauf('marches', 'id', $marches)->get(['id', 'objet']);

        $paiementsFiges = DB::table('paiement_executions')->pluck('paiement_id')->unique()->values();
        $ordresFiges = DB::table('paiements')->whereIn('id', $paiementsFiges)->pluck('ordonnancement_id');
        $liquidationsFigees = DB::table('ordonnancements')->whereIn('id', $ordresFiges)->pluck('liquidation_id');
        $engagementsFiges = DB::table('liquidations')->whereIn('id', $liquidationsFigees)->pluck('engagement_id');
        $besoinsFiges = DB::table('engagements')->whereIn('id', $engagementsFiges)->pluck('expression_besoin_id');
        $journaux = [
            'expression_besoin_id' => DB::table('eb_events')->pluck('expression_besoin_id'),
            'engagement_id' => DB::table('eng_events')->pluck('engagement_id'),
            'liquidation_id' => DB::table('liq_events')->pluck('liquidation_id'),
            'ordonnancement_id' => DB::table('ord_events')->pluck('ordonnancement_id'),
            'paiement_id' => DB::table('pay_events')->pluck('paiement_id'),
        ];
        $besoins = $besoins->reject(fn ($ligne): bool => $besoinsFiges->contains($ligne->id)
            || $journaux['expression_besoin_id']->contains($ligne->id)
            || DB::table('engagements')->where('expression_besoin_id', $ligne->id)->exists())->values();
        $engagements = $engagements->diff($engagementsFiges)->diff($journaux['engagement_id'])->values();
        $liquidations = $liquidations->diff($liquidationsFigees)->diff($journaux['liquidation_id'])->values();
        $ordres = $ordres->diff($ordresFiges)->diff($journaux['ordonnancement_id'])->values();
        $paiements = $paiements->diff($paiementsFiges)->diff($journaux['paiement_id'])->values();
        $figes = DB::table('expression_besoins')->whereIn('id', $besoinsFiges)->orderBy('reference')->get(['reference', 'objet'])
            ->map(fn ($ligne): string => $ligne->reference.' · '.$ligne->objet)
            ->all();
        $engagementsGardes = $this->sauf('engagements', 'id', $engagements)->count();
        $tiers = DB::table('tiers')
            ->where(function ($query): void {
                $query->where('nif', 'like', 'NIF-DEMO-%')->orWhere('nif', 'like', 'JEU-NIF-%');
            })
            ->pluck('id');
        $tiersProteges = collect()
            ->merge($this->sauf('engagements', 'id', $engagements)->whereIn('tiers_id', $tiers)->pluck('tiers_id'))
            ->merge($this->sauf('revenue_orders', 'id', $titres)->whereIn('tiers_id', $tiers)->pluck('tiers_id'))
            ->merge(DB::table('marches')->where('objet', 'not like', "Jeu d'essai%")->where('objet', 'not like', 'Jeu d’essai%')->whereIn('tiers_id', $tiers)->pluck('tiers_id'))
            ->unique()->values();
        $tiers = $tiers->diff($tiersProteges)->values();
        $besoinsGardes = (int) DB::table('expression_besoins')->count() - $besoins->count();

        return [
            'besoins' => $besoins->pluck('id')->all(),
            'besoins_libelles' => $besoins->map(fn ($ligne): string => $ligne->reference.' · '.$ligne->objet)->all(),
            'besoins_incertains' => $incertains->map(fn ($ligne): string => $ligne->reference.' · '.$ligne->objet)->all(),
            'besoins_gardes' => $besoinsGardes,
            'engagements' => $engagements->all(),
            'engagements_gardes' => $engagementsGardes,
            'liquidations' => $liquidations->all(),
            'ordonnancements' => $ordres->all(),
            'paiements' => $paiements->all(),
            'previsions' => $previsions->all(),
            'titres' => $titres->all(),
            'titres_gardes' => $titresGardes,
            'recus' => $recus->all(),
            'tiers' => $tiers->all(),
            'tiers_proteges' => $tiersProteges->all(),
            'marches' => $marches->all(),
            'marches_gardes' => $marchesGardes->map(fn ($ligne): string => '#'.$ligne->id.' · '.$ligne->objet)->all(),
            'figes' => $figes,
            'notifications' => (int) DB::table('notifications')->count(),
            'taches' => (int) DB::table('workflow_tasks')->count(),
            'documents_generes' => (int) DB::table('generated_documents')->count(),
            'audit' => (int) DB::table('audit_events')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, int|string|list<string>>  $empreinte
     */
    private function afficher(array $plan, array $empreinte): void
    {
        $this->line('Base budget_ceeac_v8 · local · lot '.self::LOT);
        $this->line('Officiel : '.$empreinte['lignes_officielles'].' lignes, vote '.$empreinte['vote'].', '.$empreinte['unites'].' unités, '.$empreinte['dons'].' dons.');
        $this->line('Exercices : '.implode(', ', $empreinte['exercices']));
        $this->line('Les journaux de circuit sont en ajout seul : les dossiers qui en ont un sont conservés.');
        $this->table(['Élément', 'Ciblés', 'Conservés'], [
            ['Expressions de besoin', count($plan['besoins']), $plan['besoins_gardes']],
            ['Engagements', count($plan['engagements']), $plan['engagements_gardes']],
            ['Liquidations', count($plan['liquidations']), '—'],
            ['Ordonnancements', count($plan['ordonnancements']), '—'],
            ['Paiements', count($plan['paiements']), '—'],
            ['Prévisions PRV', count($plan['previsions']), 'dons DON-'],
            ['Titres', count($plan['titres']), $plan['titres_gardes']],
            ['Encaissements', count($plan['recus']), '—'],
            ['Tiers de démonstration', count($plan['tiers']), count($plan['tiers_proteges'])],
            ['Marchés', count($plan['marches']), count($plan['marches_gardes'])],
        ]);
        foreach ($plan['besoins_incertains'] as $ligne) {
            $this->warn('EB d’origine incertaine, conservé : '.$ligne);
        }
        foreach ($plan['marches_gardes'] as $ligne) {
            $this->line('Marché conservé : '.$ligne);
        }
        foreach ($plan['figes'] as $ligne) {
            $this->line('Dossier conservé : une exécution de paiement ne se supprime pas : '.$ligne);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function supprimer(array $plan): void
    {
        $this->fichiers = array_merge(
            $this->chemins('eb_documents', 'path', 'expression_besoin_id', $plan['besoins']),
            $this->chemins('paiement_executions', 'preuve_chemin', 'paiement_id', $plan['paiements']),
            $this->chemins('revenue_documents', 'chemin', 'order_id', $plan['titres']),
            $this->chemins('revenue_documents', 'chemin', 'receipt_id', $plan['recus']),
        );

        $this->supprimerOu($plan['paiements'], 'pay_events', 'paiement_id');
        $this->supprimerIds('paiements', $plan['paiements']);
        $this->supprimerIds('ordonnancements', $plan['ordonnancements']);
        $this->supprimerOu($plan['liquidations'], 'liquidation_rectifications', 'liquidation_id');
        $this->supprimerIds('liquidations', $plan['liquidations']);
        $this->supprimerOu($plan['engagements'], 'engagement_degagements', 'engagement_id');
        $this->supprimerIds('marches', $plan['marches']);
        $this->supprimerIds('engagements', $plan['engagements']);
        $this->supprimerIds('expression_besoins', $plan['besoins']);

        $this->supprimerOu($plan['titres'], 'revenue_adjustments', 'order_id');
        $this->supprimerOu($plan['recus'], 'revenue_adjustments', 'receipt_id');
        $this->supprimerOu($plan['titres'], 'revenue_allocations', 'order_id');
        $this->supprimerOu($plan['recus'], 'revenue_allocations', 'receipt_id');
        $this->supprimerOu($plan['titres'], 'revenue_reminders', 'order_id');
        $this->supprimerOu($plan['titres'], 'revenue_events', 'order_id');
        $this->supprimerOu($plan['recus'], 'revenue_events', 'receipt_id');
        $this->supprimerOu($plan['titres'], 'revenue_documents', 'order_id');
        $this->supprimerOu($plan['recus'], 'revenue_documents', 'receipt_id');
        $this->supprimerIds('revenue_orders', $plan['titres']);
        $this->supprimerIds('revenue_receipts', $plan['recus']);
        $this->supprimerIds('revenue_forecasts', $plan['previsions']);

        $this->supprimerOu($plan['tiers'], 'tiers_bank_accounts', 'tiers_id');
        $this->supprimerIds('tiers', $plan['tiers']);

        DB::table('credit_movements')->where('acte', 'JEU-ESSAI-GEL-2026')->delete();
        DB::table('admin_delegations')
            ->where(function ($query): void {
                $query->where('motif', 'like', "Jeu d'essai%")->orWhere('motif', 'like', 'Jeu d’essai%');
            })
            ->delete();

        $tachesProtegees = DB::table('physical_achievements')->whereIn('status', ['valide', 'consolide'])->pluck('pap_task_id')->filter()->values();
        $taches = DB::table('pap_tasks')
            ->where(function ($query): void {
                $query->where('label', 'like', "Jeu d'essai%")->orWhere('label', 'like', 'Jeu d’essai%');
            });
        if ($tachesProtegees->isNotEmpty()) {
            $taches->whereNotIn('id', $tachesProtegees);
        }
        $taches = $taches->pluck('id');
        if ($taches->isNotEmpty()) {
            DB::table('pap_tasks')->whereIn('depends_on_id', $taches)->update(['depends_on_id' => null]);
            DB::table('pap_tasks')->whereIn('id', $taches)->update(['depends_on_id' => null]);
            DB::table('pap_tasks')->whereIn('id', $taches)->delete();
        }

        $mesuresProtegees = DB::table('indicator_measurements')->whereIn('status', ['valide', 'consolide'])->pluck('indicator_id');
        $indicateurs = DB::table('indicators')->where('code', 'like', 'IND-JEU-%');
        if ($mesuresProtegees->isNotEmpty()) {
            $indicateurs->whereNotIn('id', $mesuresProtegees);
        }
        $indicateurs = $indicateurs->pluck('id');
        $mesures = DB::table('indicator_measurements')->whereIn('indicator_id', $indicateurs)->pluck('id');
        DB::table('se_proofs')->where('path', 'like', '%jeu-essai%')->delete();
        $this->supprimerIds('indicators', $indicateurs->all());
        DB::table('physical_achievements')
            ->where('comment', 'Premier atelier tenu.')
            ->whereNotIn('status', ['valide', 'consolide'])
            ->delete();
        $ecarts = DB::table('performance_variances')
            ->where(function ($query): void {
                $query->where('comment', 'like', "Jeu d'essai%")->orWhere('comment', 'like', 'Jeu d’essai%');
            })
            ->pluck('id');
        $this->supprimerOu($ecarts->all(), 'corrective_actions', 'performance_variance_id');
        $this->supprimerIds('performance_variances', $ecarts->all());
        DB::table('se_recommendations')
            ->where(function ($query): void {
                $query->where('description', 'like', "Jeu d'essai%")->orWhere('description', 'like', 'Jeu d’essai%');
            })
            ->delete();
        DB::table('se_evaluations')
            ->where(function ($query): void {
                $query->where('subject', 'like', "Jeu d'essai%")->orWhere('subject', 'like', 'Jeu d’essai%');
            })
            ->delete();
        DB::table('se_risks')->where('description', 'Indisponibilité des formateurs au dernier trimestre.')->delete();
        DB::table('performance_reports')
            ->where(function ($query): void {
                $query->where('commentaire', 'like', "Jeu d'essai%")->orWhere('commentaire', 'like', 'Jeu d’essai%');
            })
            ->delete();

        $this->supprimerCampagne();
        $this->supprimerLignesDemo();
        $this->supprimerTachesOrphelines();
        $this->supprimerNotifications($plan);
    }

    private function supprimerCampagne(): void
    {
        $campagne = DB::table('budget_campaigns')->where('code', 'JEU-PREP-2027')->value('id');
        if ($campagne === null) {
            return;
        }
        DB::table('budget_arbitrages')->where('campaign_id', $campagne)->delete();
        DB::table('budget_versions')->where('campaign_id', $campagne)->delete();
        DB::table('budget_dossiers')->where('campaign_id', $campagne)->delete();
        DB::table('budget_campaigns')->where('id', $campagne)->delete();
    }

    private function supprimerLignesDemo(): void
    {
        $lignes = DB::table('budget_lines')->where('officiel', false)->whereIn('code', self::LIGNES_DEMO)->pluck('id');
        $occupees = DB::table('expression_besoins')->whereIn('budget_line_id', $lignes)->pluck('budget_line_id')
            ->merge(DB::table('engagements')->whereIn('budget_line_id', $lignes)->pluck('budget_line_id'))
            ->merge(DB::table('eb_imputations')->whereIn('budget_line_id', $lignes)->pluck('budget_line_id'));
        $libres = $lignes->diff($occupees)->values();
        if ($libres->isEmpty()) {
            return;
        }
        $activites = DB::table('pap_enrichments')->whereIn('budget_line_id', $libres)->pluck('id');
        $tachesPap = DB::table('pap_tasks')->whereIn('pap_enrichment_id', $activites)->pluck('id');
        if ($tachesPap->isNotEmpty()) {
            DB::table('pap_tasks')->whereIn('id', $tachesPap)->update(['depends_on_id' => null]);
            DB::table('pap_tasks')->whereIn('depends_on_id', $tachesPap)->update(['depends_on_id' => null]);
        }
        $indicateurs = DB::table('indicators')->whereIn('pap_enrichment_id', $activites)->pluck('id');
        $mesures = DB::table('indicator_measurements')->whereIn('indicator_id', $indicateurs)->pluck('id');
        $realisations = DB::table('physical_achievements')->whereIn('pap_enrichment_id', $activites)->pluck('id');
        if ($mesures->isNotEmpty()) {
            DB::table('se_proofs')->where('proofable_type', 'like', '%Measurement')->whereIn('proofable_id', $mesures)->delete();
        }
        if ($realisations->isNotEmpty()) {
            DB::table('se_proofs')->where('proofable_type', 'like', '%Achievement')->whereIn('proofable_id', $realisations)->delete();
        }
        $this->supprimerIds('indicators', $indicateurs->all());
        DB::table('credit_movements')->whereIn('budget_line_id', $libres)->orWhereIn('counterpart_line_id', $libres)->delete();
        $this->supprimerIds('budget_lines', $libres->all());
    }

    /**
     * @param  list<int>  $ids
     */
    /**
     * @param  Collection<int, int>|list<int>  $ids
     */
    private function sauf(string $table, string $colonne, mixed $ids): Builder
    {
        $ids = collect($ids)->filter(fn ($id): bool => $id !== null)->values();
        $requete = DB::table($table);
        if ($ids->isNotEmpty()) {
            $requete->whereNotIn($colonne, $ids);
        }

        return $requete;
    }

    /**
     * @param  list<int>  $ids
     */
    private function supprimerIds(string $table, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return;
        }
        foreach (array_chunk($ids, 500) as $lot) {
            DB::table($table)->whereIn('id', $lot)->delete();
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function supprimerOu(array $ids, string $table, string $colonne): void
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return;
        }
        foreach (array_chunk($ids, 500) as $lot) {
            DB::table($table)->whereIn($colonne, $lot)->delete();
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function chemins(string $table, string $colonne, string $cle, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $colonne)) {
            return [];
        }

        return DB::table($table)->whereIn($cle, $ids)->whereNotNull($colonne)->pluck($colonne)->all();
    }

    private function supprimerTachesOrphelines(): void
    {
        $liens = [
            'expression_besoin' => 'expression_besoins',
            'engagement' => 'engagements',
            'liquidation' => 'liquidations',
            'ordonnancement' => 'ordonnancements',
            'paiement' => 'paiements',
            'revenue_forecast' => 'revenue_forecasts',
            'revenue_order' => 'revenue_orders',
            'revenue_receipt' => 'revenue_receipts',
            'indicator_measurement' => 'indicator_measurements',
            'physical_achievement' => 'physical_achievements',
            'performance_report' => 'performance_reports',
        ];
        foreach ($liens as $type => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $ids = DB::table($table)->pluck('id');
            $requete = DB::table('workflow_tasks')->where('entity_type', $type);
            if ($ids->isEmpty()) {
                $requete->delete();

                continue;
            }
            $requete->whereNotIn('entity_id', $ids)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function supprimerNotifications(array $plan): void
    {
        $references = collect($plan['besoins_libelles'])
            ->map(fn (string $ligne): string => strtok($ligne, ' ') ?: '')
            ->filter()
            ->merge(DB::table('engagements')->whereIn('id', $plan['engagements'])->pluck('reference'))
            ->unique()
            ->values();
        if ($references->isEmpty()) {
            DB::table('notifications')
                ->where(function ($query): void {
                    $query->where('data', 'like', "%Jeu d'essai%")->orWhere('data', 'like', '%Jeu d’essai%');
                })
                ->delete();

            return;
        }

        $identifiants = [];
        foreach (DB::table('notifications')->get(['id', 'data']) as $notification) {
            $data = (string) $notification->data;
            $concerne = str_contains($data, "Jeu d'essai") || str_contains($data, 'Jeu d’essai');
            if (! $concerne) {
                foreach ($references as $reference) {
                    if ($reference !== '' && str_contains($data, (string) $reference)) {
                        $concerne = true;
                        break;
                    }
                }
            }
            if ($concerne) {
                $identifiants[] = $notification->id;
            }
        }
        $this->supprimerIds('notifications', $identifiants);
    }

    private function retirerFichiers(): int
    {
        $conserves = collect()
            ->merge(DB::table('generated_documents')->pluck('path'))
            ->merge(Schema::hasTable('eb_documents') ? DB::table('eb_documents')->pluck('path') : [])
            ->merge(Schema::hasTable('revenue_documents') ? DB::table('revenue_documents')->pluck('chemin') : [])
            ->merge(Schema::hasTable('se_proofs') ? DB::table('se_proofs')->pluck('path') : [])
            ->filter(fn ($chemin): bool => is_string($chemin) && $chemin !== '')
            ->flip();
        $retires = 0;
        foreach (array_unique($this->fichiers) as $chemin) {
            if ($chemin === '' || $conserves->has($chemin)) {
                continue;
            }
            if (Storage::disk('local')->exists($chemin)) {
                Storage::disk('local')->delete($chemin);
                $retires++;
            }
        }

        return $retires;
    }

    private function sauvegarder(): string
    {
        $config = config('database.connections.pgsql');
        $repertoire = storage_path('app/backups');
        if (! is_dir($repertoire) && ! mkdir($repertoire, 0755, true) && ! is_dir($repertoire)) {
            throw new RuntimeException('Répertoire de sauvegarde inaccessible.');
        }
        $fichier = $repertoire.DIRECTORY_SEPARATOR.'budget_ceeac_v8-avant-lot-'.now()->format('Ymd-His').'.dump';
        $binaire = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe';
        if (! is_file($binaire)) {
            throw new RuntimeException('pg_dump introuvable. Aucune suppression n’a été lancée.');
        }

        $commande = implode(' ', [
            escapeshellarg($binaire),
            '-h', escapeshellarg((string) $config['host']),
            '-p', escapeshellarg((string) $config['port']),
            '-U', escapeshellarg((string) $config['username']),
            '-d', escapeshellarg((string) $config['database']),
            '-Fc', '--no-owner', '--no-acl',
            '-f', escapeshellarg($fichier),
        ]);
        putenv('PGPASSWORD='.$config['password']);
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = proc_open($commande, $descripteurs, $tubes);
        if (! is_resource($processus)) {
            putenv('PGPASSWORD');
            throw new RuntimeException('Impossible de lancer la sauvegarde.');
        }
        stream_get_contents($tubes[1]);
        $erreur = str_replace((string) $config['password'], '***', (string) stream_get_contents($tubes[2]));
        fclose($tubes[1]);
        fclose($tubes[2]);
        $code = proc_close($processus);
        putenv('PGPASSWORD');
        if ($code !== 0 || ! is_file($fichier) || filesize($fichier) < 1000) {
            file_put_contents($repertoire.DIRECTORY_SEPARATOR.'sauvegarde-echec.txt', 'code='.$code.' taille='.(is_file($fichier) ? filesize($fichier) : 0)."\n".trim($erreur));
            throw new RuntimeException('Sauvegarde refusée. Le détail est dans storage/app/backups/sauvegarde-echec.txt.');
        }

        return $fichier;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, int|string|list<string>>  $avant
     * @param  array<string, int|string|list<string>>  $apres
     */
    private function journal(string $sauvegarde, array $plan, array $avant, array $apres, int $fichiers, int $generation, bool $conserve): void
    {
        $repertoire = storage_path('app/jeu-essai');
        if (! is_dir($repertoire)) {
            mkdir($repertoire, 0755, true);
        }
        $contenu = [
            'lot' => self::LOT,
            'sauvegarde' => $sauvegarde,
            'generation' => $generation,
            'officiel_conserve' => $conserve,
            'fichiers_retires' => $fichiers,
            'avant' => $avant,
            'apres' => $apres,
            'plan' => $plan,
            'volumes' => [
                'expressions' => DB::table('expression_besoins')->count(),
                'engagements' => DB::table('engagements')->count(),
                'liquidations' => DB::table('liquidations')->count(),
                'ordonnancements' => DB::table('ordonnancements')->count(),
                'paiements' => DB::table('paiements')->count(),
                'titres' => DB::table('revenue_orders')->count(),
                'encaissements' => DB::table('revenue_receipts')->count(),
                'tiers' => DB::table('tiers')->count(),
                'notifications' => DB::table('notifications')->count(),
                'taches' => DB::table('workflow_tasks')->count(),
                'campagne' => DB::table('budget_campaigns')->where('code', 'JEU-PREP-2027')->value('statut'),
            ],
        ];
        file_put_contents($repertoire.DIRECTORY_SEPARATOR.'lot-'.self::LOT.'.json', json_encode($contenu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
