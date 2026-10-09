<?php

namespace App\Console\Commands;

use App\Domains\Administration\Models\AdminDelegation;
use App\Domains\Administration\Services\AdministrationService;
use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\BudgetProposal;
use App\Domains\Budget\Models\CreditMovement;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Services\CreditMovementService;
use App\Domains\Budget\Services\PreparationModuleService;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Services\EngagementWorkflow;
use App\Domains\Commitments\Services\LiquidationWorkflow;
use App\Domains\Commitments\Services\OrdonnancementWorkflow;
use App\Domains\Commitments\Services\PaiementWorkflow;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Services\MonitoringService;
use App\Domains\Monitoring\Services\ReportingService;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Services\WorkflowActorResolver;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Domains\Planning\Models\GarVersion;
use App\Domains\Planning\Services\GarPlanService;
use App\Domains\Procurement\Models\Marche;
use App\Domains\Procurement\Services\MarcheService;
use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenueReceipt;
use App\Domains\Revenues\Services\RevenueCollectionService;
use App\Domains\Revenues\Services\RevenueCycleService;
use App\Domains\Suppliers\Models\Tiers;
use App\Domains\Suppliers\Services\TiersService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('demo:jeu-essai')]
#[Description('Ajoute un jeu d’essai sur les modules en place, sans modifier le budget voté')]
class JeuEssai extends Command
{
    private Exercice $exercice;

    private User $expert;

    private User $chef;

    private User $directeurBudget;

    private User $controleur;

    private User $secretaire;

    private User $ordonnateur;

    private User $comptable;

    private User $chefComptable;

    private User $agent;

    private User $clarisse;

    private User $directeurDati;

    private ?Tiers $titulaire = null;

    public function handle(): int
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        $exercice = Exercice::query()->where('annee', 2026)->first();
        if ($exercice === null || ! $exercice->isOpen()) {
            $this->error('L’exercice 2026 doit être ouvert. Le jeu d’essai ne crée pas d’exercice et ne clôture rien.');

            return self::FAILURE;
        }
        $this->exercice = $exercice;

        if (! $this->acteurs()) {
            return self::FAILURE;
        }

        $this->tiers();
        $this->chaine();
        $this->marche();
        $this->recettes();
        $this->credit();
        $this->gar();
        $this->suivi();
        $this->delegation();
        $this->preparation();

        $this->newLine();
        $this->call('actes:emettre');
        $this->info('Jeu d’essai terminé. Le budget voté, l’organigramme et l’exercice 2026 n’ont pas été remplacés. 2027 n’est pas adopté.');

        return self::SUCCESS;
    }

    private function acteurs(): bool
    {
        $attendus = [
            'expert' => 'blaise.essono@ceeac.int',
            'chef' => 'chef.budget@ceeac.int',
            'directeurBudget' => 'directeur.budget@ceeac.int',
            'controleur' => 'controleur.financier@ceeac.int',
            'secretaire' => 'aline.moussavou@ceeac.int',
            'ordonnateur' => 'ordonnateur@ceeac.int',
            'comptable' => 'rita.obame@ceeac.int',
            'chefComptable' => 'marc.ndzie@ceeac.int',
            'agent' => 'paul.nguema@ceeac.int',
            'clarisse' => 'clarisse.ndong@ceeac.int',
            'directeurDati' => 'jp.okombi@ceeac.int',
        ];
        foreach ($attendus as $propriete => $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->error('Acteur introuvable : '.$email);

                return false;
            }
            $this->{$propriete} = $user;
        }

        return true;
    }

    private function tiers(): void
    {
        $this->titulaire = $this->fournisseur(
            'JEU-NIF-001',
            'Société Équatoriale de Licences',
            'fournisseur',
            'GA100010001000100010001',
        );
        $this->fournisseur('JEU-NIF-002', 'Imprimerie du Golfe', 'fournisseur', 'GA100010001000100010002');
        $this->fournisseur('JEU-NIF-003', 'Cabinet Conseil Ogooué', 'consultant', null);
    }

    private function fournisseur(string $nif, string $nom, string $type, ?string $compte): ?Tiers
    {
        $existant = Tiers::query()->where('nif', $nif)->first();
        if ($existant !== null) {
            $this->line('Tiers déjà présent : '.$existant->code);

            return $existant;
        }

        return $this->tenter('Tiers '.$nom, function () use ($nif, $nom, $type, $compte): Tiers {
            $tiers = app(TiersService::class)->create($this->comptable, [
                'type' => $type,
                'raison_sociale' => $nom,
                'nif' => $nif,
                'pays' => 'Gabon',
                'adresse' => 'Libreville',
                'email' => strtolower($nif).'@example.test',
            ]);
            if ($compte !== null) {
                $account = app(TiersService::class)->addAccount($this->comptable, $tiers, [
                    'banque' => 'BGFIBANK',
                    'agence' => 'Libreville Centre',
                    'numero' => $compte,
                    'titulaire' => $nom,
                    'justificatif' => 'RIB jeu d’essai',
                ]);
                app(TiersService::class)->validateAccount($this->chefComptable, $account);
            }

            return $tiers;
        });
    }

    private function chaine(): void
    {
        $paye = $this->besoin('21324', 'Jeu d\'essai — licences bureautiques', 6_500_000, 'approuve');
        if ($paye instanceof ExpressionBesoin) {
            $this->depense($paye, 'rapproche', 'FAC-JEU-21324', 'VIR-JEU-2026-001');
        }

        $aSigner = $this->besoin('21321', 'Jeu d\'essai — renouvellement de postes', 3_200_000, 'approuve');
        if ($aSigner instanceof ExpressionBesoin) {
            $this->depense($aSigner, 'a_signer', 'FAC-JEU-21321', null);
        }

        $this->besoin('61431', 'Jeu d\'essai — prestation rejetée', 900_000, 'rejete');
        $this->besoin('66316', 'Jeu d\'essai — prime de stage', 1_500_000, 'soumis');
        $this->besoin('66315', 'Jeu d\'essai — heures supplémentaires', 400_000, 'brouillon');
        $this->besoin('203232', 'Jeu d\'essai — atelier énergie', 2_500_000, 'brouillon', $this->clarisse);
        $this->besoin('203222', 'Jeu d\'essai — mission énergie à corriger', 1_800_000, 'retourne', $this->clarisse);

        $annule = $this->besoin('66101', 'Jeu d\'essai — engagement à annuler', 750_000, 'approuve');
        if ($annule instanceof ExpressionBesoin) {
            $engagement = $annule->engagement ?? Engagement::query()->where('expression_besoin_id', $annule->id)->first();
            if ($engagement !== null && $engagement->status !== EngagementStatus::Annule) {
                $this->tenter('Annulation '.$engagement->reference, fn () => app(EngagementWorkflow::class)->annuler(
                    $engagement,
                    $this->directeurBudget,
                    'Annulation du jeu d’essai avant liquidation.',
                ));
            }
        }

        $caisse = $this->besoin('66102', 'Jeu d\'essai — menues dépenses de caisse', 200_000, 'approuve');
        if ($caisse instanceof ExpressionBesoin) {
            $this->depense($caisse, 'rapproche', 'FAC-JEU-66102', 'CAI-JEU-2026-001', 'caisse');
        }

        $cheque = $this->besoin('66103', 'Jeu d\'essai — règlement par chèque', 300_000, 'approuve');
        if ($cheque instanceof ExpressionBesoin) {
            $this->depense($cheque, 'rapproche', 'FAC-JEU-66103', 'CHQ-JEU-2026-001', 'cheque');
        }

        $partiel = $this->besoin('66105', 'Jeu d\'essai — acompte sur prestation', 2_000_000, 'approuve');
        if ($partiel instanceof ExpressionBesoin) {
            $this->depense($partiel, 'partiel', 'FAC-JEU-66105', 'VIR-JEU-2026-002', 'virement', 800_000);
        }
    }

    /**
     * Un besoin hors PAP s’initie au Service des Moyens généraux (règle du
     * circuit) ; un besoin PAP, par l’initiateur de la structure de la ligne.
     */
    private function initiateurPour(BudgetLine $ligne): ?User
    {
        if ($ligne->nature === BudgetNature::HorsPap) {
            $resolver = app(WorkflowActorResolver::class);

            return User::query()
                ->where('role', 'initiateur')
                ->orderBy('id')
                ->get()
                ->first(fn (User $user): bool => $resolver->rattacheAuSigle($user, 'DSG-DRHMG-SMG'));
        }

        return User::query()
            ->where('role', 'initiateur')
            ->where('organization_unit_id', $ligne->organization_unit_id)
            ->first();
    }

    private function besoin(string $code, string $objet, int $montant, string $cible, ?User $initiateur = null): ?ExpressionBesoin
    {
        $existant = ExpressionBesoin::query()->where('objet', $objet)->first();
        if ($existant !== null) {
            $auteurExistant = $existant->initiator ?? $this->expert;
            if ($auteurExistant instanceof User) {
                $this->piece($existant, $auteurExistant);
            }
            if ($cible === 'approuve' && $existant->status !== EbStatus::Transformee) {
                return $this->tenter('Reprise '.$objet, function () use ($existant): ExpressionBesoin {
                    $eb = $existant;
                    if (in_array($eb->status, [EbStatus::Brouillon, EbStatus::Retournee], true)) {
                        $eb = app(ExpressionBesoinWorkflow::class)->submit($eb, $eb->initiator);
                    }

                    return $this->approuver($eb);
                });
            }
            $this->line('Dossier déjà présent : '.$existant->reference.' · '.$objet);

            return $existant;
        }

        $ligne = BudgetLine::query()->where('exercice_id', $this->exercice->id)->where('code', $code)->where('officiel', true)->first();
        if ($ligne === null) {
            $this->warn('Ligne officielle absente, dossier ignoré : '.$code);

            return null;
        }

        $auteur = $initiateur ?? $this->initiateurPour($ligne);
        if ($auteur === null) {
            $this->warn($ligne->nature === BudgetNature::HorsPap ? 'Aucun initiateur au Service des Moyens généraux (hors PAP), ligne '.$code : 'Aucun initiateur sur la structure de la ligne '.$code);

            return null;
        }

        return $this->tenter($objet, function () use ($ligne, $objet, $montant, $cible, $auteur): ExpressionBesoin {
            $workflow = app(ExpressionBesoinWorkflow::class);
            $eb = $workflow->createDraft($auteur, $ligne);
            $workflow->syncDetails($eb, [
                'objet' => $objet,
                'justification' => 'Dossier de démonstration pour parcourir le circuit. Il n’engage pas le budget voté au-delà de ce montant.',
            ], [[
                'designation' => $objet,
                'quantite' => 1,
                'unite' => 'forfait',
                'prix_unitaire' => $montant,
                'beneficiaire' => $this->titulaire?->raison_sociale,
            ]], null);
            $this->piece($eb->fresh(), $auteur);

            if ($cible === 'brouillon') {
                return $eb->fresh();
            }

            $eb = $workflow->submit($eb->fresh(), $auteur);
            if ($cible === 'soumis') {
                return $eb;
            }
            if ($cible === 'retourne') {
                return $workflow->returnForCorrection(
                    $eb,
                    $this->acteurEtape($eb),
                    'Le devis détaillé est à joindre.',
                    'Retour du jeu d’essai.',
                    ['justification'],
                );
            }
            if ($cible === 'rejete') {
                return $workflow->reject($eb, $this->acteurEtape($eb), 'Prestation hors du programme de la direction.', 'Rejet du jeu d’essai.');
            }

            return $this->approuver($eb);
        });
    }

    private function approuver(ExpressionBesoin $eb): ExpressionBesoin
    {
        $workflow = app(ExpressionBesoinWorkflow::class);
        $garde = 0;
        while (! in_array($eb->status, [EbStatus::Transformee, EbStatus::Approuvee], true) && $garde < 8) {
            $eb = $workflow->validateStep($eb->fresh(), $this->acteurEtape($eb));
            $garde++;
        }

        return $eb->fresh('engagement');
    }

    private function acteurEtape(ExpressionBesoin $eb): User
    {
        $role = (string) $eb->workflow_step;

        return match ($role) {
            'directeur' => $this->utilisateurStructure('directeur', (int) $eb->organization_unit_id, 'directeur de la structure'),
            'commissaire' => $this->utilisateurStructure('commissaire', (int) $eb->organization_unit_id, 'commissaire de la structure'),
            'secretaire_general' => $this->secretaire,
            'ordonnateur' => $this->ordonnateur,
            default => throw ValidationException::withMessages(['action' => 'Étape inattendue : '.$role]),
        };
    }

    private function utilisateurStructure(string $role, int $uniteId, string $libelle): User
    {
        $user = User::query()->where('role', $role)->where('organization_unit_id', $uniteId)->first();
        if ($user === null) {
            throw ValidationException::withMessages(['action' => 'Aucun '.$libelle.' pour poursuivre ce dossier.']);
        }

        return $user;
    }

    private function piece(ExpressionBesoin $eb, User $auteur): void
    {
        $types = DB::table('document_types')
            ->where('operation', 'engagement')
            ->where('required', true)
            ->where('active', true)
            ->orderBy('label')
            ->pluck('label');
        if ($types->isEmpty()) {
            $types = collect(['Note justificative']);
        }

        foreach ($types as $type) {
            if ($eb->documents()->where('type', $type)->exists()) {
                continue;
            }
            $slug = Str::slug($type);
            $contenu = "DOCUMENT DE TEST\n{$type} — {$eb->reference}\nCe document n’a aucune valeur officielle.\n";
            $chemin = 'eb-documents/'.$eb->id.'/'.$slug.'.txt';
            Storage::disk('local')->put($chemin, $contenu);
            $eb->documents()->create([
                'uploaded_by' => $auteur->id,
                'type' => $type,
                'original_name' => $slug.'.txt',
                'path' => $chemin,
                'mime' => 'text/plain',
                'size' => strlen($contenu),
                'sha256' => hash('sha256', $contenu),
            ]);
        }
    }

    private function depense(ExpressionBesoin $eb, string $jusqua, string $facture, ?string $virement, string $mode = 'virement', ?int $montantRegle = null): void
    {
        $engagement = $eb->engagement ?? Engagement::query()->where('expression_besoin_id', $eb->id)->first();
        if ($engagement === null) {
            $this->warn('Pas d’engagement pour '.$eb->reference);

            return;
        }
        if ($jusqua === 'a_signer' && $engagement->liquidations()->whereNotNull('visa_reference')->exists()) {
            $this->line('Ordonnancement déjà ouvert pour '.$engagement->reference);

            return;
        }
        if ($jusqua === 'rapproche' && $engagement->liquidations()->whereHas('ordonnancement.paiement', fn ($query) => $query->where('status', 'cloture'))->exists()) {
            $this->line('Paiement déjà rapproché pour '.$engagement->reference);

            return;
        }
        if ($jusqua === 'partiel' && $engagement->liquidations()->whereHas('ordonnancement.paiement', fn ($query) => $query->where('montant_paye', '>', 0))->exists()) {
            $this->line('Paiement partiel déjà constaté pour '.$engagement->reference);

            return;
        }

        $this->tenter('Dépense '.$engagement->reference.' → '.$jusqua, function () use ($engagement, $jusqua, $facture, $virement, $mode, $montantRegle): void {
            $workflow = app(EngagementWorkflow::class);
            if ($this->titulaire !== null && $engagement->tiers_id === null) {
                $engagement = $workflow->updateBeneficiary($engagement, $this->expert, '', null, null, $this->titulaire->id);
            }
            if ($engagement->workflow_step === 'expert_budget') {
                $engagement = $workflow->transmit($engagement, $this->expert, 'Instruction du jeu d’essai');
            }
            if ($jusqua === 'chef') {
                return;
            }
            if ($engagement->workflow_step === 'chef_budget') {
                $engagement = $workflow->transmit($engagement, $this->chef, null);
            }
            if ($engagement->workflow_step === 'directeur_budget') {
                $engagement = $workflow->transmit($engagement, $this->directeurBudget, null);
            }
            if ($engagement->workflow_step === 'controleur_financier') {
                $engagement = $workflow->vise($engagement, $this->controleur, 'Visa du jeu d’essai');
            }

            $liquidation = Liquidation::query()->where('engagement_id', $engagement->id)->first();
            if ($liquidation === null) {
                return;
            }
            $this->liquider($liquidation, $facture);
            if ($jusqua === 'a_signer') {
                return;
            }
            $this->payer($liquidation->fresh('ordonnancement.paiement'), (string) $virement, $mode, $montantRegle, $jusqua !== 'partiel');
        });
    }

    private function liquider(Liquidation $liquidation, string $facture): void
    {
        if ($liquidation->visa_reference !== null) {
            return;
        }
        $auteur = $liquidation->engagement?->expressionBesoin?->initiator;
        if ($auteur === null) {
            throw ValidationException::withMessages(['action' => 'Liquidation sans initiateur.']);
        }
        $workflow = app(LiquidationWorkflow::class);
        $montant = (int) $liquidation->engagement?->montant;
        if ($liquidation->service_fait_at === null) {
            $liquidation = $workflow->certify($liquidation, $auteur, $montant, null, [
                'nature_prestation' => 'Fourniture',
                'bon_livraison' => 'BL-'.$facture,
                'date_service' => '2026-09-20',
            ]);
        }
        if (blank($liquidation->invoice_number)) {
            $liquidation = $workflow->saveInvoice($liquidation, $auteur, [
                'numero' => $facture,
                'date' => '2026-09-22',
                'echeance' => '2026-10-22',
                'montant_ht' => $montant,
                'taxes' => 0,
                'retenue' => 0,
                'penalite' => 0,
            ]);
        }
        if ($liquidation->workflow_step === 'initiateur') {
            $liquidation = $workflow->submit($liquidation, $auteur);
        }
        if ($liquidation->workflow_step === 'controleur_financier') {
            $workflow->vise($liquidation, $this->controleur, 'Service fait conforme.');
        }
    }

    private function payer(Liquidation $liquidation, string $virement, string $mode = 'virement', ?int $montantRegle = null, bool $rapprocher = true): void
    {
        $ordre = $liquidation->ordonnancement;
        if ($ordre === null) {
            throw ValidationException::withMessages(['action' => 'Ordonnancement absent.']);
        }
        $signataire = $ordre->ordonnateur_role === 'secretaire_general' ? $this->secretaire : $this->ordonnateur;
        if ($ordre->signed_at === null) {
            $ordre = app(OrdonnancementWorkflow::class)->sign($ordre, $signataire, true);
        }
        $paiement = $ordre->paiement ?? $ordre->fresh('paiement')->paiement;
        if ($paiement === null) {
            throw ValidationException::withMessages(['action' => 'Paiement non ouvert.']);
        }
        $paiements = app(PaiementWorkflow::class);
        if ($paiement->status?->value === 'genere') {
            $paiement = $paiements->prendreEnCharge($paiement, $this->comptable);
        }
        if (blank($paiement->mode)) {
            $compte = in_array($mode, ['virement', 'cheque'], true)
                ? $this->titulaire?->bankAccounts()->where('status', 'valide')->first()
                : null;
            $paiement = $paiements->preparer($paiement, $this->comptable, [
                'mode' => $mode,
                'compte_bancaire_id' => $compte?->id,
                'motif' => 'DOCUMENT DE TEST — règlement du jeu d’essai',
            ]);
        }
        if (in_array($paiement->status?->value, ['en_preparation', 'retourne'], true)) {
            $paiement = $paiements->soumettre($paiement, $this->comptable);
        }
        if ($paiement->status?->value === 'a_controler') {
            $paiement = $paiements->valider($paiement, $this->chefComptable);
        }
        if ($paiement->status?->value === 'a_signer') {
            $paiement = $paiements->signer($paiement, $this->agent, true);
        }
        if (in_array($paiement->status?->value, ['autorise', 'paye_partiel'], true)) {
            $preuve = UploadedFile::fake()->create('avis-'.$virement.'.pdf', 12, 'application/pdf');
            $montant = $montantRegle ?? (int) $paiement->montant;
            $paiement = $paiements->executer($paiement, $this->comptable, $montant, $virement, '2026-10-01', $preuve);
        }
        if ($rapprocher && $paiement->status?->value === 'a_rapprocher') {
            $paiements->rapprocher($paiement, $this->comptable, 'RAPP-'.$virement);
        }
    }

    private function marche(): void
    {
        $objet = 'Jeu d\'essai — contrat de licences bureautiques';
        if (Marche::query()->where('objet', $objet)->exists()) {
            $this->line('Marché déjà présent.');

            return;
        }
        $engagement = Engagement::query()->whereHas('expressionBesoin', fn ($query) => $query->where('objet', 'Jeu d\'essai — licences bureautiques'))->first();
        if ($engagement === null || $this->titulaire === null) {
            $this->warn('Marché non créé : l’engagement de licences est absent.');

            return;
        }
        $this->tenter('Marché', function () use ($objet, $engagement): void {
            $marche = app(MarcheService::class)->creer($this->expert, [
                'exercice_id' => $this->exercice->id,
                'objet' => $objet,
                'tiers_id' => $this->titulaire?->id,
                'montant' => (int) $engagement->montant,
                'procedure' => 'consultation',
            ]);
            app(MarcheService::class)->rattacher($marche, $engagement, $this->expert);
        });
    }

    private function recettes(): void
    {
        $libelle = 'Jeu d\'essai — produits de documentation';
        if (! RevenueForecast::query()->where('label', $libelle)->exists()) {
            $this->tenter('Prévision de recette', function () use ($libelle): void {
                $categorie = RevenueCategory::query()->where('code', 'VTE')->where('active', true)->firstOrFail();
                $prevision = app(RevenueCycleService::class)->creerPrevision($this->comptable, [
                    'exercice_id' => $this->exercice->id,
                    'category_id' => $categorie->id,
                    'label' => $libelle,
                    'montant' => 12_000_000,
                    'source_label' => 'Vente de publications',
                    'periode' => '2026',
                    'date_prevue' => '2026-11-30',
                ]);
                $prevision = app(RevenueCycleService::class)->soumettrePrevision($this->comptable, $prevision);
                app(RevenueCycleService::class)->validerPrevision($this->directeurBudget, $prevision);
            });
        }

        $motif = 'Jeu d\'essai — vente de publications';
        if (! RevenueOrder::query()->where('motif', $motif)->exists()) {
            $this->tenter('Titre de recette', function () use ($motif): void {
                $categorie = RevenueCategory::query()->where('code', 'VTE')->where('active', true)->firstOrFail();
                $prevision = RevenueForecast::query()->where('label', 'Jeu d\'essai — produits de documentation')->first();
                $cycle = app(RevenueCycleService::class);
                $titre = $cycle->creerTitre($this->comptable, [
                    'exercice_id' => $this->exercice->id,
                    'category_id' => $categorie->id,
                    'forecast_id' => $prevision?->id,
                    'debtor_type' => 'tiers',
                    'tiers_id' => $this->titulaire?->id,
                    'montant' => 8_000_000,
                    'echeance' => '2026-11-30',
                    'motif' => $motif,
                    'description' => 'Titre de démonstration, distinct des contributions des États.',
                ]);
                $titre = $cycle->soumettre($this->comptable, $titre);
                $titre = $cycle->verifier($this->expert, $titre);
                $titre = $cycle->valider($this->directeurBudget, $titre);
                $titre = $cycle->prendreEnCharge($this->agent, $titre);
                $recu = app(RevenueCollectionService::class)->encaisser($this->chefComptable, [
                    'recu_le' => '2026-10-02',
                    'montant' => 3_000_000,
                    'mode' => 'virement',
                    'reference_bancaire' => 'VIR-REC-JEU-001',
                    'banque' => 'BGFIBANK',
                    'commentaire' => 'Acompte du jeu d’essai',
                    'allocations' => [['order_id' => $titre->id, 'montant' => 3_000_000]],
                ]);
                app(RevenueCollectionService::class)->rapprocher($this->directeurBudget, $recu, 'rapproche', null);
                $cycle->relancer($this->expert, $titre->fresh(), [
                    'kind' => 'premiere',
                    'canal' => 'email',
                    'destinataire' => 'facturation@example.test',
                    'resultat' => 'Accusé de réception',
                    'prochaine_action' => '2026-11-15',
                ]);
            });
        }

        $motifComplet = 'Jeu d\'essai — cession de documentation archivée';
        if (! RevenueOrder::query()->where('motif', $motifComplet)->exists()) {
            $this->tenter('Titre entièrement encaissé', function () use ($motifComplet): void {
                $categorie = RevenueCategory::query()->where('code', 'VTE')->where('active', true)->firstOrFail();
                $cycle = app(RevenueCycleService::class);
                $titre = $cycle->creerTitre($this->comptable, [
                    'exercice_id' => $this->exercice->id,
                    'category_id' => $categorie->id,
                    'debtor_type' => 'tiers',
                    'tiers_id' => $this->titulaire?->id,
                    'montant' => 1_500_000,
                    'echeance' => '2026-10-20',
                    'motif' => $motifComplet,
                    'description' => 'DOCUMENT DE TEST. Titre soldé, distinct des contributions des États.',
                ]);
                $titre = $cycle->soumettre($this->comptable, $titre);
                $titre = $cycle->verifier($this->expert, $titre);
                $titre = $cycle->valider($this->directeurBudget, $titre);
                $titre = $cycle->prendreEnCharge($this->agent, $titre);
                $recu = app(RevenueCollectionService::class)->encaisser($this->chefComptable, [
                    'recu_le' => '2026-10-03',
                    'montant' => 1_500_000,
                    'mode' => 'virement',
                    'reference_bancaire' => 'VIR-REC-JEU-003',
                    'banque' => 'BGFIBANK',
                    'commentaire' => 'DOCUMENT DE TEST — solde du titre archivé',
                    'allocations' => [['order_id' => $titre->id, 'montant' => 1_500_000]],
                ]);
                app(RevenueCollectionService::class)->rapprocher($this->directeurBudget, $recu, 'rapproche', null);
            });
        }

        if (! RevenueReceipt::query()->where('reference_bancaire', 'VIR-REC-JEU-002')->exists()) {
            $this->tenter('Encaissement non identifié', function (): void {
                app(RevenueCollectionService::class)->encaisser($this->agent, [
                    'recu_le' => '2026-10-02',
                    'montant' => 500_000,
                    'mode' => 'virement',
                    'reference_bancaire' => 'VIR-REC-JEU-002',
                    'banque' => 'BGFIBANK',
                    'commentaire' => 'Jeu d’essai — virement sans titre VIR-REC-JEU-002',
                ]);
            });
        }
    }

    private function credit(): void
    {
        if (CreditMovement::query()->where('acte', 'JEU-ESSAI-GEL-2026')->exists()) {
            $this->line('Gel de démonstration déjà enregistré.');

            return;
        }
        $ligne = BudgetLine::query()->where('exercice_id', $this->exercice->id)->where('code', '21323')->where('officiel', true)->first();
        if ($ligne === null) {
            $this->warn('Gel ignoré : ligne 21323 absente.');

            return;
        }
        $this->tenter('Gel de crédit', function () use ($ligne): void {
            app(CreditMovementService::class)->record(
                $ligne,
                $this->directeurBudget,
                'gel',
                1_000_000,
                'Réserve de démonstration sur le renouvellement informatique.',
                'JEU-ESSAI-GEL-2026',
            );
        });
    }

    private function gar(): void
    {
        if (GarVersion::query()->where('exercice_id', $this->exercice->id)->exists()) {
            $this->line('Chaîne GAR déjà présente pour 2026.');

            return;
        }
        $this->tenter('Chaîne GAR', function (): void {
            app(GarPlanService::class)->initialiser($this->exercice, $this->expert);
        });
    }

    private function suivi(): void
    {
        foreach ([
            ['2026-T2', 'Deuxième trimestre 2026', '2026-04-01', '2026-06-30'],
            ['2026-T3', 'Troisième trimestre 2026', '2026-07-01', '2026-09-30'],
            ['2026-T4', 'Quatrième trimestre 2026', '2026-10-01', '2026-12-31'],
        ] as [$code, $label, $debut, $fin]) {
            MonitoringPeriod::query()->firstOrCreate(['code' => $code], [
                'exercice_year' => 2026,
                'label' => $label,
                'frequency' => 'trimestrielle',
                'opens_on' => $debut,
                'closes_on' => $fin,
                'status' => 'ouverte',
            ]);
        }

        $activite = PapEnrichment::query()->whereHas('budgetLine', fn ($query) => $query->where('code', '203232')->where('exercice_id', $this->exercice->id))->first();
        if ($activite === null) {
            $this->warn('Suivi ignoré : l’activité de la ligne 203232 est absente.');

            return;
        }

        $this->taches($activite);
        $periode = MonitoringPeriod::query()->where('code', '2026-T3')->first()
            ?? MonitoringPeriod::query()->where('exercice_year', 2026)->first();
        if ($periode === null) {
            return;
        }

        if (! Indicator::query()->where('code', 'IND-JEU-203232')->exists()) {
            $this->tenter('Indicateur et mesure', function () use ($activite, $periode): void {
                $suivi = app(MonitoringService::class);
                $indicateur = $suivi->indicator($this->clarisse, [
                    'pap_enrichment_id' => $activite->id,
                    'code' => 'IND-JEU-203232',
                    'label' => 'Agents formés à la maîtrise de l’énergie',
                    'type' => 'realisation',
                    'gar_level' => 'activite',
                    'unit' => 'agent',
                    'direction' => 'croissant',
                    'aggregation' => 'last_value',
                    'baseline_value' => 0,
                    'source' => 'Feuille de présence',
                    'responsible_role' => 'directeur',
                ]);
                $suivi->target($this->clarisse, $indicateur, $periode->id, 20);
                $mesure = $suivi->measure($this->clarisse, [
                    'indicator_id' => $indicateur->id,
                    'monitoring_period_id' => $periode->id,
                    'value' => 12,
                    'justification' => 'Douze agents ont suivi l’atelier de démonstration.',
                    'source' => 'Feuille de présence',
                ]);
                $preuve = UploadedFile::fake()->create('presence-jeu-essai.pdf', 8, 'application/pdf');
                $suivi->proof($this->clarisse, $mesure->getMorphClass(), $mesure->id, 'releve', $preuve);
                $mesure = $suivi->transitionMeasurement($this->clarisse, $mesure, 'soumettre');
                $this->validerSaisie($suivi, $activite, $mesure);
            });
        }

        if (PhysicalAchievement::query()->where('pap_enrichment_id', $activite->id)->where('monitoring_period_id', $periode->id)->doesntExist()) {
            $this->tenter('Réalisation physique', function () use ($activite, $periode): void {
                $suivi = app(MonitoringService::class);
                $realisation = $suivi->achieve($this->clarisse, [
                    'pap_enrichment_id' => $activite->id,
                    'monitoring_period_id' => $periode->id,
                    'method' => 'quantitative',
                    'quantity' => 1,
                    'planned' => 2,
                    'comment' => 'Premier atelier tenu.',
                ]);
                $preuve = UploadedFile::fake()->create('atelier-jeu-essai.pdf', 8, 'application/pdf');
                $suivi->proof($this->clarisse, $realisation->getMorphClass(), $realisation->id, 'livrable', $preuve);
                $realisation = $suivi->transitionAchievement($this->clarisse, $realisation, 'soumettre');
                $this->validerSaisie($suivi, $activite, $realisation);
            });
        }

        if (PerformanceVariance::query()->where('comment', 'Jeu d’essai — décalage de l’atelier')->doesntExist()) {
            $this->tenter('Écart, risque et recommandation', function () use ($activite): void {
                $suivi = app(MonitoringService::class);
                $ecart = $suivi->variance($this->directeurBudget, [
                    'pap_enrichment_id' => $activite->id,
                    'kind' => 'physique',
                    'cause_category' => 'logistique',
                    'cause' => 'Report de la salle de formation.',
                    'consequence' => 'La cible trimestrielle n’est pas atteinte.',
                    'comment' => 'Jeu d’essai — décalage de l’atelier',
                    'responsible_role' => 'directeur',
                    'due_on' => '2026-11-15',
                ]);
                $suivi->corrective($this->directeurBudget, [
                    'performance_variance_id' => $ecart->id,
                    'description' => 'Reprogrammer l’atelier avant la mi-novembre.',
                    'responsible_role' => 'directeur',
                    'due_on' => '2026-11-15',
                    'expected_result' => 'Vingt agents formés.',
                ]);
                $suivi->risk($this->directeurBudget, [
                    'pap_enrichment_id' => $activite->id,
                    'description' => 'Indisponibilité des formateurs au dernier trimestre.',
                    'category' => 'operationnel',
                    'probability' => 2,
                    'impact' => 3,
                    'responsible_role' => 'directeur',
                    'prevention' => 'Prévoir un formateur suppléant.',
                ]);
                $suivi->recommendation($this->directeurBudget, [
                    'origin' => 'suivi',
                    'pap_enrichment_id' => $activite->id,
                    'description' => 'Jeu d’essai — confirmer le calendrier des ateliers 2026.',
                    'responsible_role' => 'directeur',
                    'due_on' => '2026-11-30',
                    'priority' => 'normale',
                ]);
                $suivi->evaluation([
                    'subject' => 'Jeu d’essai — atelier énergie',
                    'scope' => 'DATI-DENER',
                    'monitoring_period_id' => MonitoringPeriod::query()->where('code', '2026-T3')->value('id'),
                    'pap_enrichment_id' => $activite->id,
                    'type' => 'mi_parcours',
                    'evaluator_role' => 'directeur_budget',
                    'criteria' => ['pertinence', 'efficacite'],
                    'conclusions' => 'L’atelier a démarré ; la cible d’agents formés reste ouverte.',
                ]);
            });
        }

        if (! PerformanceReport::query()->where('commentaire', 'Jeu d’essai — rapport trimestriel DATI')->exists()) {
            $this->tenter('Rapport de performance', function () use ($periode): void {
                $rapports = app(ReportingService::class);
                $rapport = $rapports->generate($this->clarisse, 'trimestriel', $periode->id, 'Jeu d’essai — rapport trimestriel DATI');
                $rapport = $rapports->transition($this->clarisse, $rapport, 'soumettre');
                $rapports->transition($this->directeurBudget, $rapport, 'valider');
            });
        }
    }

    private function taches(PapEnrichment $activite): void
    {
        if ($activite->tasks()->where('label', 'Jeu d\'essai — cadrage')->exists()) {
            return;
        }
        $cadrage = PapTask::query()->create([
            'pap_enrichment_id' => $activite->id,
            'position' => (int) $activite->tasks()->max('position') + 1,
            'label' => 'Jeu d\'essai — cadrage',
            'proposed' => false,
            'validated' => true,
            'weight' => 40,
            'progress_percent' => 100,
            'code' => 'JEU-CAD',
            'unit' => 'atelier',
            'planned_quantity' => 1,
        ]);
        $tenue = PapTask::query()->create([
            'pap_enrichment_id' => $activite->id,
            'position' => (int) $activite->tasks()->max('position') + 1,
            'label' => 'Jeu d\'essai — tenue de l’atelier',
            'proposed' => false,
            'validated' => true,
            'weight' => 60,
            'progress_percent' => 50,
            'code' => 'JEU-ATL',
            'unit' => 'atelier',
            'planned_quantity' => 1,
            'depends_on_id' => $cadrage->id,
        ]);
        $suivi = app(MonitoringService::class);
        $suivi->schedule($this->clarisse, $cadrage, [
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
            'actual_start' => '2026-07-06',
            'actual_end' => '2026-07-28',
        ]);
        $suivi->schedule($this->clarisse, $tenue, [
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-11-15',
            'actual_start' => '2026-09-10',
            'depends_on_id' => $cadrage->id,
        ]);
    }

    private function validerSaisie(MonitoringService $suivi, PapEnrichment $activite, IndicatorMeasurement|PhysicalAchievement $saisie): void
    {
        $garde = 0;
        while (in_array($saisie->status, ['soumis', 'valide_responsable'], true) && $garde < 3) {
            $validateur = $saisie->status === 'soumis'
                ? ($activite->responsible_user_id !== null && (int) $activite->responsible_user_id !== (int) $saisie->author_id
                    ? User::query()->findOrFail($activite->responsible_user_id)
                    : $this->directeurDati)
                : $this->directeurBudget;
            if ((int) $validateur->id === (int) $saisie->author_id || (int) $validateur->id === (int) $saisie->responsible_validator_id) {
                $validateur = $this->directeurBudget;
            }
            $saisie = $saisie instanceof PhysicalAchievement
                ? $suivi->transitionAchievement($validateur, $saisie, 'valider')
                : $suivi->transitionMeasurement($validateur, $saisie, 'valider');
            $garde++;
        }
    }

    private function delegation(): void
    {
        $motif = 'Jeu d\'essai — intérim de visa budgétaire';
        if (AdminDelegation::query()->where('motif', $motif)->exists()) {
            $this->line('Délégation de recette déjà présente.');

            return;
        }

        $this->tenter('Délégation temporaire', function () use ($motif): void {
            app(AdministrationService::class)->delegate($this->directeurBudget, [
                'delegant_id' => $this->directeurBudget->id,
                'delegataire_id' => $this->expert->id,
                'fonction' => 'Visa budgétaire',
                'perimetre' => 'DSG-DPPB-SB',
                'starts_on' => '2026-10-04',
                'ends_on' => '2026-12-31',
                'motif' => $motif,
                'document' => 'DOCUMENT DE TEST',
            ]);
        });
    }

    private function preparation(): void
    {
        $propositions = BudgetProposal::query()->whereHas('exercice', fn ($query) => $query->where('annee', 2027))->count();
        $this->line('Préparation 2027 : '.$propositions.' proposition(s) déjà en base. Aucune adoption, aucune clôture de 2026.');

        if (BudgetCampaign::query()->where('code', 'JEU-PREP-2027')->exists()) {
            $this->line('Campagne JEU-PREP-2027 déjà en brouillon. Elle n’est ni ouverte ni adoptée.');

            return;
        }

        $exercice = Exercice::query()->where('annee', 2027)->where('statut', 'preparation')->first();
        if ($exercice === null) {
            $this->warn('Campagne non créée : l’exercice 2027 n’est pas en préparation.');

            return;
        }

        $structure = OrganizationUnit::query()->where('sigle', 'DSG-DPPB-SB')->first();
        $this->tenter('Campagne JEU-PREP-2027', function () use ($exercice, $structure): void {
            app(PreparationModuleService::class)->creerCampagne($this->directeurBudget, [
                'exercice_id' => $exercice->id,
                'code' => 'JEU-PREP-2027',
                'label' => 'Jeu d\'essai — campagne de préparation 2027',
                'description' => 'DOCUMENT DE TEST. Campagne de recette laissée en brouillon. Elle ne modifie pas le budget 2026 et n’adopte pas 2027.',
                'structures' => $structure !== null ? [$structure->id] : [],
                'responsable_id' => $this->directeurBudget->id,
                'date_ouverture' => '2026-10-04',
                'date_cloture' => '2026-12-15',
            ]);
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|null
     */
    private function tenter(string $etape, callable $action): mixed
    {
        try {
            $resultat = $action();
            $this->info($etape);

            return $resultat;
        } catch (ValidationException $exception) {
            $this->warn($etape.' — '.collect($exception->errors())->flatten()->implode(' '));

            return null;
        } catch (\Throwable $exception) {
            $this->warn($etape.' — '.$exception->getMessage());

            return null;
        }
    }
}
