<?php

namespace App\Domains\Commitments\Enums;

enum EngagementStatus: string
{
    case EnInstruction = 'en_instruction';
    case AValider = 'a_valider';
    case EnControle = 'en_controle';
    case Retourne = 'retourne';
    case Rejete = 'rejete';
    case Vise = 'vise';
    case TransformeLiquidation = 'transforme_liquidation';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnInstruction => 'En instruction Budget',
            self::AValider => 'À valider',
            self::EnControle => 'En contrôle CF',
            self::Retourne => 'Retourné',
            self::Rejete => 'Rejeté',
            self::Vise => 'Visé',
            self::TransformeLiquidation => 'Transformé en LIQ',
            self::Annule => 'Annulé',
        };
    }

    public function reservesCredit(): bool
    {
        return ! in_array($this, [self::Rejete, self::Annule], true);
    }
}
