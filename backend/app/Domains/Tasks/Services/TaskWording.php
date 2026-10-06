<?php

namespace App\Domains\Tasks\Services;

use App\Domains\Tasks\Models\WorkflowTask;
use Illuminate\Support\Str;

/**
 * Formulation des avis de tâche : l’action attendue, nommée précisément,
 * et ce qui permet de reconnaître le dossier sans l’ouvrir.
 */
final class TaskWording
{
    private const NOUNS = [
        'valider' => 'validation',
        'approuver' => 'approbation',
        'viser' => 'visa',
        'signer' => 'signature',
        'certifier' => 'certification du service fait',
        'corriger' => 'correction',
        'completer' => 'complément',
        'reprendre' => 'reprise de la transmission',
        'executer' => 'exécution du règlement',
        'rapprocher' => 'rapprochement',
        'preparer' => 'préparation du règlement',
        'prendre_en_charge' => 'prise en charge',
        'verifier' => 'vérification',
        'saisir' => 'saisie',
        'justifier' => 'justification',
        'mettre_a_jour' => 'mise à jour',
        'publier' => 'publication',
        'consolider' => 'consolidation',
    ];

    private const ACTIONS = [
        'completer' => 'Compléter',
        'corriger' => 'Corriger',
        'valider' => 'Valider',
        'approuver' => 'Approuver',
        'viser' => 'Viser',
        'certifier' => 'Certifier le service fait',
        'signer' => 'Signer',
        'reprendre' => 'Reprendre la transmission',
        'executer' => 'Exécuter le règlement',
        'rapprocher' => 'Rapprocher',
        'preparer' => 'Préparer le règlement',
        'prendre_en_charge' => 'Prendre en charge',
        'verifier' => 'Vérifier',
        'saisir' => 'Saisir',
        'justifier' => 'Justifier',
        'mettre_a_jour' => 'Mettre à jour',
        'publier' => 'Publier',
        'consolider' => 'Consolider',
    ];

    private const ROLES = [
        'initiateur' => 'Initiateur',
        'directeur' => 'Directeur',
        'commissaire' => 'Commissaire',
        'secretaire_general' => 'Secrétaire général',
        'ordonnateur' => 'Ordonnateur',
        'expert_budget' => 'Expert Budget',
        'chef_budget' => 'Chef de service Budget',
        'directeur_budget' => 'Directeur du Budget',
        'controleur_financier' => 'Contrôleur financier',
        'comptable' => 'Comptable',
        'chef_comptable' => 'Chef comptable',
        'agent_comptable' => 'Agent comptable',
        'responsable_activite' => 'Responsable d’activité',
        'responsable_se' => 'Responsable du suivi-évaluation',
        'administrateur_fonctionnel' => 'Administrateur fonctionnel',
    ];

    /** Étapes qui ne sont pas des rôles : statuts de saisie, de titre ou de dossier. */
    private const ETAPES = [
        'brouillon' => 'Brouillon',
        'soumis' => 'Soumis à validation',
        'a_corriger' => 'À corriger',
        'valide_responsable' => 'Validé par le responsable',
        'valide' => 'Validé',
        'verifie' => 'Vérifié',
        'retourne' => 'Retourné',
        'en_revue' => 'En revue',
        'rapprochement' => 'Rapprochement',
        'non_rapproche' => 'À rapprocher',
        'clos' => 'Clos',
    ];

    private const PRIORITES = [
        'critique' => 'Critique',
        'haute' => 'Haute',
        'normale' => 'Normale',
        'faible' => 'Faible',
    ];

    public static function action(?string $action): string
    {
        return self::ACTIONS[(string) $action] ?? self::humaniser($action);
    }

    public static function role(?string $role): string
    {
        return self::ROLES[(string) $role] ?? self::humaniser($role);
    }

    public static function etape(?string $etape): string
    {
        return self::ROLES[(string) $etape] ?? self::ETAPES[(string) $etape] ?? self::humaniser($etape);
    }

    public static function priorite(?string $priorite): string
    {
        return self::PRIORITES[(string) $priorite] ?? self::humaniser($priorite);
    }

    private static function humaniser(?string $code): string
    {
        return $code === null || $code === '' ? '—' : Str::ucfirst(str_replace('_', ' ', $code));
    }

    public static function noun(WorkflowTask $task): string
    {
        return self::NOUNS[(string) $task->action] ?? 'intervention';
    }

    /**
     * « Atelier régional · 1 200 000 FCFA · échéance le 10/10/2026 »
     */
    public static function context(WorkflowTask $task, bool $withDue = true): string
    {
        $parts = [];
        if (filled($task->objet)) {
            $parts[] = Str::limit(trim((string) $task->objet), 70);
        }
        if ((int) $task->amount > 0) {
            $parts[] = number_format((int) $task->amount, 0, ',', ' ').' FCFA';
        }
        if ($withDue && $task->due_on !== null) {
            $parts[] = 'échéance le '.$task->due_on->format('d/m/Y');
        }

        return implode(' · ', $parts);
    }

    public static function assigned(WorkflowTask $task, ?string $returnMotif = null): string
    {
        $message = $task->dossier_reference.' nécessite votre '.self::noun($task);
        $context = self::context($task);
        if ($context !== '') {
            $message .= ' : '.$context;
        }
        $message .= '.';
        if ($returnMotif !== null) {
            $message .= ' Motif du retour : '.$returnMotif;
        }

        return $message;
    }
}
