<?php

use App\Domains\Administration\Models\AuditHold;
use App\Domains\Administration\Services\ContinuityRegisterService;
use App\Domains\Administration\Services\GestionHabilitations;
use App\Domains\Administration\Services\SauvegardeVerifier;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Services\PeriodeBudgetaireService;
use App\Domains\Budget\Services\PreparationModuleService;
use App\Domains\Ged\Services\GedService;
use App\Domains\Monitoring\Services\CollectionCampaignService;
use App\Domains\Monitoring\Services\FollowUpService;
use App\Domains\Monitoring\Services\VarianceDossierService;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Services\TaskProjector;
use App\Domains\Tasks\Services\TaskReminderService;
use App\Shared\Audit\AuditService;
use App\Shared\Notifications\RecapitulatifService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('suivi:relances', function (FollowUpService $followUp, CollectionCampaignService $campaigns) {
    $counts = $followUp->remindOverdue();
    $indicateurs = $campaigns->relancerIndicateurs();
    $this->info("Relances envoyées : {$counts['mesures']} mesure(s) corrective(s), {$counts['recommandations']} recommandation(s), {$indicateurs['j7']} indicateur(s) à J-7, {$indicateurs['j3']} à J-3.");
})->purpose('Relance les mesures échues et les indicateurs à renseigner à J-7 et J-3');

Schedule::command('suivi:relances')->dailyAt('07:30')->withoutOverlapping(15);

Artisan::command('suivi:alertes', function (VarianceDossierService $dossiers) {
    $this->info('Alertes d’écart créées : '.$dossiers->generateAlerts());
})->purpose('Crée une alerte et demande une explication pour chaque activité en écart critique (S&E §23-25, §90)');

Schedule::command('suivi:alertes')->dailyAt('07:00')->withoutOverlapping(15);

Artisan::command('taches:projeter', function (TaskProjector $projector) {
    $projector->sync();
    $this->info('Tâches reprojetées : '.WorkflowTask::query()->where('status', '!=', 'terminee')->count().' ouverte(s).');
})->purpose('Recalcule toutes les tâches ouvertes (priorités, échéances, mesures et recommandations à J-7)');

Schedule::command('taches:projeter')->dailyAt('07:05')->withoutOverlapping(15);

Artisan::command('taches:relances', function (TaskReminderService $reminders) {
    $counts = $reminders->remind();
    $this->info("Relances de tâches : {$counts['relances']} échéance(s), {$counts['escalades']} escalade(s).");
})->purpose('Relance et escalade les tâches ouvertes selon les seuils de config/gesbudep.php');

Schedule::command('taches:relances')->dailyAt('07:15')->withoutOverlapping(15);

Artisan::command('notifications:recapitulatif', function (RecapitulatifService $recapitulatif) {
    $this->info('Récapitulatifs envoyés : '.$recapitulatif->envoyer().'.');
})->purpose('Envoie par courriel le récapitulatif quotidien des tâches urgentes aux comptes qui l’ont activé');

Schedule::command('notifications:recapitulatif')->dailyAt('07:35')->withoutOverlapping(15);

Artisan::command('notifications:purger', function () {
    $jours = max(30, (int) config('gesbudep.notifications.conservation_lues_jours', 90));
    $supprimees = DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays($jours))
        ->delete();
    app(AuditService::class)->enregistrer(null, 'notifications.purge', 'notification', null, null, ['supprimees' => $supprimees, 'lues_depuis_jours' => $jours], null, 'succes');
    $this->info("Notifications lues depuis plus de {$jours} jours supprimées : {$supprimees}.");
})->purpose('Supprime les notifications lues au-delà de la durée de conservation ; les non lues sont conservées');

Schedule::command('notifications:purger')->dailyAt('06:30')->withoutOverlapping(15);

Schedule::command('recettes:alertes')->dailyAt('07:20')->withoutOverlapping(15);

Artisan::command('preparation:echeances', function (PreparationModuleService $preparation) {
    $this->info('Échéances de préparation signalées : '.$preparation->relancerEcheances().'.');
})->purpose('Signale les étapes de campagne dont l’échéance tombe dans les sept jours');

Schedule::command('preparation:echeances')->dailyAt('07:25')->withoutOverlapping(15);

Artisan::command('rapports:execution', function (ContinuityRegisterService $registres) {
    $this->info('Instantané écrit : '.$registres->photographierExecution());
})->purpose('Écrit un instantané des volumes de la chaîne de la dépense');

Schedule::command('rapports:execution')->dailyAt('07:40')->withoutOverlapping(15);

Artisan::command('habilitations:expirer', function (GestionHabilitations $habilitations) {
    $this->info('Habilitations expirées : '.$habilitations->expirer().'.');
})->purpose('Passe en expirée les habilitations actives dont la date de fin est dépassée');

Schedule::command('habilitations:expirer')->dailyAt('07:45')->withoutOverlapping(15);

Artisan::command('ged:reprendre', function (GedService $ged) {
    $rapport = $ged->reprendrePieces();
    $this->info('Pièces reprises : '.$rapport['repris'].'.');
    foreach ($rapport['anomalies'] as $anomalie) {
        $this->warn($anomalie);
    }
})->purpose('Reprend les pièces eb_documents déjà stockées, sans supprimer les fichiers sources');

Artisan::command('ged:integrite', function (GedService $ged) {
    $this->info('Anomalies d’intégrité : '.$ged->integrite().'.');
})->purpose('Contrôle la présence et l’empreinte des versions courantes');

Schedule::command('ged:integrite')->dailyAt('07:50')->withoutOverlapping(15);

Artisan::command('ged:indexer-texte', function (GedService $ged) {
    $this->info('Textes indexés : '.$ged->indexerTextes().'. Les PDF et les images ne sont pas lus.');
})->purpose('Extrait le texte des versions txt et csv dont l’empreinte correspond au fichier');

Schedule::command('ged:indexer-texte')->dailyAt('07:55')->withoutOverlapping(15);

Artisan::command('periodes:assurer', function (PeriodeBudgetaireService $periodes) {
    $nombre = 0;
    Exercice::query()->orderBy('annee')->each(function (Exercice $exercice) use ($periodes, &$nombre) {
        $periodes->assurer($exercice);
        $nombre++;
    });
    $this->info('Exercices préparés : '.$nombre.'. Les périodes nouvelles sont ouvertes.');
})->purpose('Crée les mois ouverts manquants, sans fermer de période');

Artisan::command('continuite:verifier-sauvegarde', function (SauvegardeVerifier $verificateur) {
    $rapport = $verificateur->verifier();
    if (! $rapport['valide']) {
        $this->error('Aucune sauvegarde PostgreSQL lisible. Aucune restauration n’est lancée.');

        return 1;
    }
    $this->info('En-tête PostgreSQL reconnu : '.$rapport['fichier'].' ('.$rapport['octets'].' octets). Ce contrôle ne restaure rien et ne fixe pas de RPO ni de RTO.');

    return 0;
})->purpose('Vérifie l’en-tête du dump le plus récent sans le restaurer');

Schedule::command('continuite:verifier-sauvegarde')->weeklyOn(1, '06:00')->withoutOverlapping(15);

Artisan::command('continuite:inventorier-sauvegarde', function (SauvegardeVerifier $verificateur) {
    $rapport = $verificateur->inventorier();
    if (! $rapport['inventorie']) {
        $this->error('Le catalogue du dump n’a pas pu être lu. Aucune restauration n’est lancée.');

        return 1;
    }
    $this->info('Catalogue lu : '.$rapport['tables'].' section(s) de données. Aucune base n’a été restaurée.');

    return 0;
})->purpose('Lit le catalogue du dump sans restaurer de base');

Schedule::command('continuite:inventorier-sauvegarde')->weeklyOn(1, '06:10')->withoutOverlapping(20);

Artisan::command('audit:reprendre', function (AuditService $audit) {
    $rapport = $audit->reprendreHistoriques();
    $this->info('Événements repris : '.$rapport['repris'].'. Déjà présents : '.$rapport['ignores'].'.');
})->purpose('Recopie les historiques de chaîne dans le journal central, sans inventer de rôle');

Artisan::command('audit:purger', function (AuditService $audit) {
    if (AuditHold::query()->whereNull('lifted_at')->exists()) {
        $this->error('Un gel est actif. Aucune purge n’est exécutée.');

        return 1;
    }
    if (! $audit->purgeAutorisee()) {
        $this->error('Le journal est en ajout seul. Aucune suppression n’est exécutée.');

        return 1;
    }

    return 0;
})->purpose('Refuse la purge du journal, et la refuse aussi lorsqu’un gel est actif');
