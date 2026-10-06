<?php

namespace App\Shared\Notifications;

use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetDossier;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Services\MonitoringService;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Services\RevenueAccess;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Services\TaskAudience;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/**
 * Résout la cible d’une notification à partir de données structurées.
 * Le texte du message n’est jamais interprété.
 */
class NotificationTargetResolver
{
    public function __construct(private readonly RevenueAccess $recettes) {}

    /**
     * @return array<string, mixed>
     */
    public function carte(User $user, DatabaseNotification $notification): array
    {
        $resolution = $this->resoudre($user, $notification->data ?? []);

        return [
            'id' => $notification->id,
            'message' => (string) ($notification->data['message'] ?? ''),
            'reference' => (string) ($notification->data['reference'] ?? ''),
            'module' => $resolution['module'],
            'type' => $resolution['type'],
            'lue' => $notification->read_at !== null,
            'date' => $notification->created_at?->format('d/m/Y H:i'),
            'lue_le' => $notification->read_at?->format('d/m/Y H:i'),
            'ouverture' => $resolution['ouverture'],
            'cible' => $resolution['type'] === null ? null : [
                'type' => $resolution['type'],
                'id' => $resolution['id'],
                'chemin' => $resolution['chemin'],
                'accessible' => $resolution['accessible'],
                'motif' => $resolution['motif'],
                'liste' => $resolution['liste'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: ?string, id: ?int, module: ?string, chemin: ?string, accessible: bool, motif: ?string, liste: ?string, ouverture: string}
     */
    public function resoudre(User $user, array $data): array
    {
        $cible = $this->identifier($data);
        if ($cible === null) {
            return [
                'type' => null,
                'id' => null,
                'module' => null,
                'chemin' => null,
                'accessible' => true,
                'motif' => null,
                'liste' => null,
                'ouverture' => 'detail',
            ];
        }

        $type = $cible['type'];
        $definition = NotificationCatalog::TYPES[$type];
        $liste = $this->moduleAutorise($user, $definition['module']) ? $definition['liste'] : null;
        $id = $cible['id'];

        if (NotificationCatalog::exigeIdentifiant($type) && ($id === null || $id < 1)) {
            return $this->refus($type, $id, $definition['module'], 'La cible de cette notification est incomplète.', $liste, false);
        }

        $acces = $this->autoriser($user, $type, $id);
        if ($acces === 'absent') {
            return $this->refus($type, $id, $definition['module'], 'Le dossier lié à cette notification n’existe plus.', $liste, false);
        }
        if ($acces === 'interdit') {
            return $this->refus($type, $id, $definition['module'], 'Vous n’avez plus accès à ce dossier.', $liste, false);
        }

        $chemin = NotificationCatalog::chemin($type, $id);
        if ($chemin === null) {
            return $this->refus(
                $type,
                $id,
                $definition['module'],
                'Cette alerte n’a pas de fiche individuelle. La liste du module reste accessible.',
                $liste,
                false,
            );
        }

        return [
            'type' => $type,
            'id' => $id,
            'module' => $definition['module'],
            'chemin' => $chemin,
            'accessible' => true,
            'motif' => null,
            'liste' => $liste,
            'ouverture' => 'dossier',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, id: ?int}|null
     */
    private function identifier(array $data): ?array
    {
        $cible = $data['cible'] ?? null;
        if (is_array($cible) && is_string($cible['type'] ?? null) && NotificationCatalog::connait($cible['type'])) {
            return ['type' => $cible['type'], 'id' => $this->entier($cible['id'] ?? null)];
        }

        foreach (NotificationCatalog::CLES_IDENTIFIANT as $cle => $type) {
            if (array_key_exists($cle, $data) && $data[$cle] !== null && $data[$cle] !== '') {
                return ['type' => $type, 'id' => $this->entier($data[$cle])];
            }
        }

        $lien = $data['lien'] ?? null;
        if (! is_string($lien) || ! $this->lienInterne($lien)) {
            return null;
        }

        foreach (NotificationCatalog::ANCIENS_CHEMINS as $motif => $type) {
            if (preg_match($motif, $lien, $correspondance) === 1) {
                return ['type' => $type, 'id' => isset($correspondance[1]) ? (int) $correspondance[1] : null];
            }
        }

        return null;
    }

    private function lienInterne(string $lien): bool
    {
        return str_starts_with($lien, '/')
            && ! str_starts_with($lien, '//')
            && ! str_contains($lien, '://')
            && ! str_contains($lien, '\\')
            && ! str_contains($lien, '?')
            && ! str_contains($lien, '#')
            && ! str_contains($lien, '..');
    }

    private function entier(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur;
        }
        if (is_string($valeur) && preg_match('/^\d+$/', $valeur) === 1) {
            return (int) $valeur;
        }

        return null;
    }

    private function moduleAutorise(User $user, string $module): bool
    {
        if ($module === 'recettes') {
            return $this->recettes->voir($user);
        }

        return $user->holdsAny();
    }

    /**
     * @return 'ok'|'absent'|'interdit'
     */
    private function autoriser(User $user, string $type, ?int $id): string
    {
        if (! $this->moduleAutorise($user, NotificationCatalog::TYPES[$type]['module'])) {
            return 'interdit';
        }

        if (! NotificationCatalog::exigeIdentifiant($type)) {
            return $id === null || $this->existe($type, $id) ? 'ok' : 'absent';
        }

        if ($id === null) {
            return 'absent';
        }

        $modele = $this->modele($type, $id);
        if ($modele === null) {
            return 'absent';
        }

        return $this->peutVoir($user, $type, $modele) ? 'ok' : 'interdit';
    }

    private function existe(string $type, int $id): bool
    {
        return $this->modele($type, $id) !== null;
    }

    private function modele(string $type, int $id): ?Model
    {
        $classe = match ($type) {
            'expression_besoin' => ExpressionBesoin::class,
            'engagement' => Engagement::class,
            'liquidation' => Liquidation::class,
            'ordonnancement' => Ordonnancement::class,
            'paiement' => Paiement::class,
            'tache' => WorkflowTask::class,
            'prevision' => RevenueForecast::class,
            'titre' => RevenueOrder::class,
            'ecart' => PerformanceVariance::class,
            'activite', 'activite_gantt' => PapEnrichment::class,
            'indicateur' => Indicator::class,
            'ligne' => BudgetLine::class,
            'campagne' => BudgetCampaign::class,
            'dossier_budget' => BudgetDossier::class,
            default => null,
        };

        if ($classe === null) {
            return null;
        }

        return $classe::query()->find($id);
    }

    private function peutVoir(User $user, string $type, Model $modele): bool
    {
        if ($type === 'tache' && $modele instanceof WorkflowTask) {
            return $this->tacheVisible($user, $modele);
        }

        if (in_array($type, ['activite', 'activite_gantt'], true) && $modele instanceof PapEnrichment) {
            return app(MonitoringService::class)->visible($user)->whereKey($modele->id)->exists();
        }

        if (in_array($type, ['prevision', 'titre'], true)) {
            return $this->recettes->voir($user);
        }

        if (in_array($type, ['ligne', 'campagne', 'dossier_budget'], true)) {
            return $user->holdsAny();
        }

        return Gate::forUser($user)->allows('view', $modele);
    }

    /**
     * Une tâche déjà traitée reste consultable pour son destinataire.
     * Ouvrir la notification ne la valide pas.
     */
    private function tacheVisible(User $user, WorkflowTask $tache): bool
    {
        return app(TaskAudience::class)->covers($user, $tache, $user->heldRoleCodes());
    }

    /**
     * @return array{type: string, id: ?int, module: string, chemin: null, accessible: bool, motif: string, liste: ?string, ouverture: string}
     */
    private function refus(string $type, ?int $id, string $module, string $motif, ?string $liste, bool $accessible): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'module' => $module,
            'chemin' => null,
            'accessible' => $accessible,
            'motif' => $motif,
            'liste' => $liste,
            'ouverture' => 'refusee',
        ];
    }
}
