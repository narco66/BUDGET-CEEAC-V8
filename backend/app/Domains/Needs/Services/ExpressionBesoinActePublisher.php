<?php

namespace App\Domains\Needs\Services;

use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;

/**
 * Acte officiel d’une expression de besoin approuvée : partagé par l’API et
 * par la commande de rattrapage, pour qu’un dossier franchi hors de l’interface
 * reçoive le même acte archivé et versé à la GED.
 */
class ExpressionBesoinActePublisher
{
    /** Relations nécessaires à la fiche et à l’acte. */
    public const RELATIONS = [
        'exercice',
        'organizationUnit.parent',
        'initiator',
        'budgetLine.enrichment.tasks',
        'lines',
        'imputations.budgetLine',
        'documents',
        'events.actor',
        'engagement',
    ];

    public function __construct(
        private readonly OfficialDocumentService $documents,
        private readonly ExpressionBesoinFichePresenter $presenter,
    ) {}

    public function emettre(ExpressionBesoin $eb, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        if (! in_array($eb->status, [EbStatus::Approuvee, EbStatus::Transformee], true) || $this->documents->current($eb, 'expression_besoin') !== null) {
            return null;
        }
        $arguments = [$eb, 'expression_besoin', $eb->reference, 'pdf.expression-besoin', ['fiche' => $this->presenter->present($eb->load(self::RELATIONS))], $event, $actor];

        return $quietly ? $this->documents->archiveQuietly(...$arguments) : $this->documents->archive(...$arguments);
    }
}
