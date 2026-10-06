<?php

namespace App\Domains\Needs\Enums;

enum EbStatus: string
{
    case Brouillon = 'brouillon';
    case ACompleter = 'a_completer';
    case Soumise = 'soumise';
    case EnValidation = 'en_validation';
    case Retournee = 'retournee';
    case ACorriger = 'a_corriger';
    case Validee = 'validee';
    case EnApprobation = 'en_approbation';
    case Approuvee = 'approuvee';
    case Rejetee = 'rejetee';
    case Annulee = 'annulee';
    case Transformee = 'transformee_engagement';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::ACompleter => 'À compléter',
            self::Soumise => 'Soumise',
            self::EnValidation => 'En validation',
            self::Retournee => 'Retournée',
            self::ACorriger => 'À corriger',
            self::Validee => 'Validée',
            self::EnApprobation => 'En approbation',
            self::Approuvee => 'Approuvée',
            self::Rejetee => 'Rejetée',
            self::Annulee => 'Annulée',
            self::Transformee => 'Transformée en ENG',
        };
    }

    /**
     * @return list<string>
     */
    public static function reserving(): array
    {
        return [
            self::Soumise->value,
            self::EnValidation->value,
            self::Validee->value,
            self::EnApprobation->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function awaiting(): array
    {
        return [
            self::Soumise->value,
            self::EnValidation->value,
            self::Validee->value,
            self::EnApprobation->value,
        ];
    }
}
