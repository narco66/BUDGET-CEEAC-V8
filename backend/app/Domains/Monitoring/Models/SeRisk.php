<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeRisk extends Model
{
    protected $table = 'se_risks';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'reference',
        'pap_enrichment_id',
        'description',
        'category',
        'probability',
        'impact',
        'responsible_role',
        'prevention',
        'mitigation',
        'status',
        'reviewed_on',
        'last_comment',
        'closed_at',
    ];

    /**
     * @var list<string>
     */
    public const STATUSES = ['ouvert', 'en_traitement', 'maitrise', 'survenu', 'clos'];

    protected function casts(): array
    {
        return ['reviewed_on' => 'date', 'closed_at' => 'datetime'];
    }

    /**
     * Échelles de la matrice de la maquette S&E (écran 6).
     *
     * @var array<int, string>
     */
    public const PROBABILITIES = [1 => 'Rare', 2 => 'Possible', 3 => 'Probable', 4 => 'Très probable'];

    /**
     * @var array<int, string>
     */
    public const IMPACTS = [1 => 'Faible', 2 => 'Modéré', 3 => 'Fort', 4 => 'Majeur'];

    /**
     * Niveau de chaque case probabilité × impact (description S&E §32).
     *
     * @var array<int, array<int, string>>
     */
    public const MATRIX = [
        1 => [1 => 'faible', 2 => 'faible', 3 => 'modere', 4 => 'modere'],
        2 => [1 => 'faible', 2 => 'modere', 3 => 'modere', 4 => 'eleve'],
        3 => [1 => 'modere', 2 => 'modere', 3 => 'eleve', 4 => 'critique'],
        4 => [1 => 'modere', 2 => 'eleve', 3 => 'critique', 4 => 'critique'],
    ];

    public function criticite(): string
    {
        $probability = max(1, min(4, (int) $this->probability));
        $impact = max(1, min(4, (int) $this->impact));

        return self::MATRIX[$probability][$impact];
    }
}
