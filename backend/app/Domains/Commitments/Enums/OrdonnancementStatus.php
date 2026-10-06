<?php

namespace App\Domains\Commitments\Enums;

enum OrdonnancementStatus: string
{
    case ASigner = 'a_signer';
    case Retourne = 'retourne';
    case Rejete = 'rejete';
    case Signe = 'signe';
    case TransmissionErreur = 'transmission_erreur';
    case TransformePaiement = 'transforme_paiement';

    public function label(): string
    {
        return match ($this) {
            self::ASigner => 'À signer',
            self::Retourne => 'Retourné',
            self::Rejete => 'Rejeté',
            self::Signe => 'Signé',
            self::TransmissionErreur => 'Transmission en erreur',
            self::TransformePaiement => 'Transformé en paiement',
        };
    }

    public function signed(): bool
    {
        return in_array($this, [self::Signe, self::TransmissionErreur, self::TransformePaiement], true);
    }
}
