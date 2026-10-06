<?php

namespace App\Domains\Commitments\Enums;

enum LiquidationStatus: string
{
    case Generee = 'generee';
    case EnPreparation = 'en_preparation';
    case EnControle = 'en_controle';
    case Complement = 'complement';
    case Retournee = 'retournee';
    case Visee = 'visee';
    case Rejetee = 'rejetee';
    case Annulee = 'annulee';
    case TransformeeOrdonnancement = 'transformee_ordonnancement';

    public function label(): string
    {
        return match ($this) {
            self::Generee => 'Générée',
            self::EnPreparation => 'En préparation',
            self::EnControle => 'En contrôle',
            self::Complement => 'Complément demandé',
            self::Retournee => 'Retournée',
            self::Visee => 'Visée',
            self::Rejetee => 'Rejetée',
            self::Annulee => 'Annulée',
            self::TransformeeOrdonnancement => 'Transformée en ORD',
        };
    }

    public function countsAsLiquidated(): bool
    {
        return ! in_array($this, [self::Rejetee, self::Annulee], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Rejetee, self::Annulee, self::TransformeeOrdonnancement, self::Visee], true);
    }
}
