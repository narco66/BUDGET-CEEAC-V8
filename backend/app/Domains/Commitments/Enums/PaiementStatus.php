<?php

namespace App\Domains\Commitments\Enums;

enum PaiementStatus: string
{
    case Genere = 'genere';
    case EnPreparation = 'en_preparation';
    case AControler = 'a_controler';
    case ASigner = 'a_signer';
    case Autorise = 'autorise';
    case PayePartiel = 'paye_partiel';
    case ARapprocher = 'a_rapprocher';
    case Cloture = 'cloture';
    case Retourne = 'retourne';
    case Rejete = 'rejete';
    case RejeteBancaire = 'rejete_bancaire';
    case Suspendu = 'suspendu';

    public function label(): string
    {
        return match ($this) {
            self::Genere => 'À prendre en charge',
            self::EnPreparation => 'En préparation',
            self::AControler => 'En contrôle',
            self::ASigner => 'À signer',
            self::Autorise => 'À exécuter',
            self::PayePartiel => 'Payé partiellement',
            self::ARapprocher => 'À rapprocher',
            self::Cloture => 'Clôturé',
            self::Retourne => 'Retourné',
            self::Rejete => 'Rejeté',
            self::RejeteBancaire => 'Rejet bancaire',
            self::Suspendu => 'Suspendu',
        };
    }

    public function countsAsPaid(): bool
    {
        return in_array($this, [self::PayePartiel, self::ARapprocher, self::Cloture], true);
    }
}
