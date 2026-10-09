<?php

use App\Domains\Monitoring\Http\Controllers\MonitoringController;
use App\Domains\Monitoring\Http\Controllers\SePagesController;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\SeDecision;
use App\Domains\Monitoring\Models\SeMilestone;
use App\Domains\Monitoring\Models\SePlanningRevision;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\PAP\Models\PapEnrichment;
use Illuminate\Support\Facades\Route;

Route::get('/suivi/tableau-de-bord', [MonitoringController::class, 'dashboard']);
Route::get('/suivi/activites', [MonitoringController::class, 'activities']);
Route::get('/suivi/activites/{papEnrichment}', [MonitoringController::class, 'activity'])->whereNumber('papEnrichment');
Route::get('/suivi/gantt', [MonitoringController::class, 'gantt']);
Route::get('/suivi/gantt/portefeuille', [MonitoringController::class, 'portfolioGantt']);
Route::get('/suivi/consolidation', [MonitoringController::class, 'consolidate']);
Route::get('/suivi/periodes', [MonitoringController::class, 'periods']);
Route::post('/suivi/periodes/{period}/consolider', [MonitoringController::class, 'closePeriod']);
Route::get('/suivi/indicateurs/agregation', [MonitoringController::class, 'aggregateIndicators']);
Route::get('/suivi/indicateurs', [MonitoringController::class, 'indicators']);
Route::post('/suivi/indicateurs', [MonitoringController::class, 'storeIndicator']);
Route::post('/suivi/indicateurs/{indicator}/cibles', [MonitoringController::class, 'storeTarget']);
Route::get('/suivi/indicateurs/{indicator}/evolution', [MonitoringController::class, 'indicatorEvolution']);
Route::patch('/suivi/taches/{papTask}', [MonitoringController::class, 'schedule']);
Route::put('/suivi/referentiels/score', [MonitoringController::class, 'updateScore']);
Route::get('/suivi/referentiels/{kind}', [MonitoringController::class, 'referentials']);
Route::post('/suivi/referentiels/{kind}', [MonitoringController::class, 'storeReferential']);
Route::patch('/suivi/referentiels/{referential}', [MonitoringController::class, 'updateReferential']);
Route::get('/suivi/saisies', [MonitoringController::class, 'workQueue']);
Route::post('/suivi/mesures', [MonitoringController::class, 'storeMeasurement']);
Route::post('/suivi/mesures/{measurement}/soumettre', [MonitoringController::class, 'submitMeasurement']);
Route::post('/suivi/mesures/{measurement}/valider', [MonitoringController::class, 'validateMeasurement']);
Route::post('/suivi/mesures/{measurement}/rectifier', [MonitoringController::class, 'correctMeasurement']);
Route::post('/suivi/mesures/{measurement}/{decision}', [MonitoringController::class, 'decideMeasurement'])->whereIn('decision', ['rejeter', 'corriger', 'consolider']);
Route::post('/suivi/realisations', [MonitoringController::class, 'storeAchievement']);
Route::post('/suivi/realisations/{achievement}/{decision}', [MonitoringController::class, 'transitionAchievement'])->whereIn('decision', ['soumettre', 'valider', 'rejeter', 'corriger', 'consolider']);
Route::get('/suivi/ecarts', [MonitoringController::class, 'variances']);
Route::post('/suivi/ecarts', [MonitoringController::class, 'storeVariance']);
Route::get('/suivi/mesures-correctives', [MonitoringController::class, 'correctives']);
Route::post('/suivi/mesures-correctives', [MonitoringController::class, 'storeCorrective']);
Route::patch('/suivi/mesures-correctives/{correctiveAction}', [MonitoringController::class, 'updateCorrective']);
Route::get('/suivi/risques', [MonitoringController::class, 'risks']);
Route::post('/suivi/risques', [MonitoringController::class, 'storeRisk']);
Route::patch('/suivi/risques/{risk}', [MonitoringController::class, 'reviewRisk']);
Route::get('/suivi/recommandations', [MonitoringController::class, 'recommendations']);
Route::post('/suivi/recommandations', [MonitoringController::class, 'storeRecommendation']);
Route::patch('/suivi/recommandations/{recommendation}', [MonitoringController::class, 'updateRecommendation']);
Route::get('/suivi/{type}/{id}/historique', [MonitoringController::class, 'followUpHistory'])
    ->whereIn('type', ['mesures-correctives', 'risques', 'recommandations'])
    ->whereNumber('id');
Route::get('/suivi/evaluations', [MonitoringController::class, 'evaluations']);
Route::post('/suivi/evaluations', [MonitoringController::class, 'storeEvaluation']);
Route::post('/suivi/preuves', [MonitoringController::class, 'proof']);
Route::get('/suivi/rapports', [MonitoringController::class, 'report']);
Route::get('/suivi/rapports-performance', [MonitoringController::class, 'performanceReports']);
Route::post('/suivi/rapports-performance', [MonitoringController::class, 'storePerformanceReport']);
Route::get('/suivi/rapports-performance/{report}', [MonitoringController::class, 'showPerformanceReport'])->whereNumber('report');
Route::get('/suivi/rapports-performance/{report}/pdf', [MonitoringController::class, 'performanceReportPdf'])->whereNumber('report');
Route::post('/suivi/rapports-performance/{report}/nouvelle-version', [MonitoringController::class, 'revisePerformanceReport'])->whereNumber('report');
Route::post('/suivi/rapports-performance/{report}/{etape}', [MonitoringController::class, 'transitionPerformanceReport'])
    ->whereNumber('report')
    ->whereIn('etape', ['soumettre', 'retourner', 'valider', 'publier']);

Route::bind('papEnrichment', fn (string $value) => PapEnrichment::query()->findOrFail($value));
Route::bind('correctiveAction', fn (string $value) => CorrectiveAction::query()->findOrFail($value));
Route::bind('risk', fn (string $value) => SeRisk::query()->findOrFail($value));
Route::bind('recommendation', fn (string $value) => SeRecommendation::query()->findOrFail($value));
Route::bind('report', fn (string $value) => PerformanceReport::query()->findOrFail($value));

// Écrans de la maquette docs/maquette-SE.
Route::get('/suivi/pilotage', [SePagesController::class, 'dashboard']);
Route::get('/suivi/activites/{papEnrichment}/fiche', [SePagesController::class, 'sheet'])->whereNumber('papEnrichment');
Route::patch('/suivi/activites/{papEnrichment}/pilotage', [SePagesController::class, 'steer'])->whereNumber('papEnrichment');
Route::get('/suivi/activites/{papEnrichment}/responsables', [SePagesController::class, 'responsibles'])->whereNumber('papEnrichment');
Route::get('/suivi/activites/{papEnrichment}/gantt', [SePagesController::class, 'gantt'])->whereNumber('papEnrichment');
Route::post('/suivi/activites/{papEnrichment}/plannings', [SePagesController::class, 'proposePlanning'])->whereNumber('papEnrichment');
Route::post('/suivi/plannings/{planningRevision}/{choix}', [SePagesController::class, 'decidePlanning'])->whereIn('choix', ['valider', 'rejeter']);
Route::post('/suivi/activites/{papEnrichment}/jalons', [SePagesController::class, 'storeMilestone'])->whereNumber('papEnrichment');
Route::patch('/suivi/jalons/{milestone}', [SePagesController::class, 'updateMilestone']);
Route::get('/suivi/ecarts/{variance}/dossier', [SePagesController::class, 'variance'])->whereNumber('variance');
Route::post('/suivi/ecarts/{variance}/{operation}', [SePagesController::class, 'varianceAction'])
    ->whereNumber('variance')
    ->whereIn('operation', ['relancer', 'escalader', 'explication', 'probleme', 'action-corrective']);
Route::get('/suivi/synthese-executive', [SePagesController::class, 'synthese']);
Route::post('/suivi/decisions', [SePagesController::class, 'storeDecision']);
Route::post('/suivi/decisions/{seDecision}/{operation}', [SePagesController::class, 'decide'])
    ->whereIn('operation', ['decider', 'ajourner', 'mettre_en_oeuvre']);
Route::get('/suivi/indicateurs/{indicator}/saisie', [SePagesController::class, 'entry']);
Route::patch('/suivi/indicateurs/{indicator}/responsable', [SePagesController::class, 'assignIndicator']);
Route::post('/suivi/indicateurs/{indicator}/apercu', [SePagesController::class, 'preview']);
Route::patch('/suivi/mesures/{measurement}', [SePagesController::class, 'updateMeasurement']);

Route::bind('planningRevision', fn (string $value) => SePlanningRevision::query()->findOrFail($value));
Route::bind('milestone', fn (string $value) => SeMilestone::query()->findOrFail($value));
Route::bind('variance', fn (string $value) => PerformanceVariance::query()->findOrFail($value));
Route::bind('seDecision', fn (string $value) => SeDecision::query()->findOrFail($value));
