<?php

namespace App\Console\Commands;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinActePublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Rattrapage des actes officiels : un dossier franchi hors de l’interface
 * (commande, import, reprise) reçoit l’acte que l’API aurait émis, archivé,
 * empreinté et versé à la GED. Un acte déjà présent n’est jamais réémis.
 */
#[Signature('actes:emettre')]
#[Description('Émet les actes officiels manquants de la chaîne de dépense (EB, ENG, LIQ, ORD, PAI)')]
class EmettreActesManquants extends Command
{
    public function handle(ExpressionBesoinActePublisher $besoins, ChainDocumentPublisher $chaine): int
    {
        $emis = 0;

        ExpressionBesoin::query()->whereIn('status', [EbStatus::Approuvee->value, EbStatus::Transformee->value])->orderBy('id')
            ->each(function (ExpressionBesoin $eb) use ($besoins, &$emis): void {
                $emis += $besoins->emettre($eb, 'rattrapage', null) !== null ? 1 : 0;
            });

        $cibles = [
            'engagement' => Engagement::query()->whereNotNull('vised_at'),
            'liquidation' => Liquidation::query()->whereNotNull('vised_at'),
            'ordonnancement' => Ordonnancement::query()->whereNotNull('signed_at'),
            'paiement' => Paiement::query()->where('montant_paye', '>', 0),
        ];
        foreach ($cibles as $kind => $requete) {
            $requete->orderBy('id')->each(function (Model $dossier) use ($chaine, $kind, &$emis): void {
                if ($chaine->current($dossier, $kind) !== null) {
                    return;
                }
                $emis += $chaine->emit($dossier, $kind, (string) $dossier->getAttribute('reference'), 'rattrapage', null) !== null ? 1 : 0;
            });
        }

        $this->info($emis.' acte(s) officiel(s) émis et versé(s) à la GED.');

        return self::SUCCESS;
    }
}
