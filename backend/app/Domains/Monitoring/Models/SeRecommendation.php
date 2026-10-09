<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class SeRecommendation extends Model
{
    protected $table = 'se_recommendations';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'reference',
        'origin',
        'description',
        'responsible_role',
        'due_on',
        'priority',
        'progress',
        'status',
        'pap_enrichment_id',
        'last_comment',
        'closed_at',
        'last_reminded_at',
    ];

    /**
     * Statuts de la description S&E §43. « En retard » n’est pas stocké : il
     * se déduit de l’échéance d’une recommandation non terminée.
     *
     * @var list<string>
     */
    public const STATUSES = ['ouverte', 'acceptee', 'en_cours', 'partiellement_realisee', 'realisee', 'rejetee', 'cloturee'];

    /**
     * @var list<string>
     */
    public const FINAL = ['realisee', 'rejetee', 'cloturee'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'closed_at' => 'datetime', 'last_reminded_at' => 'datetime'];
    }

    public function late(): bool
    {
        return ! in_array($this->status, self::FINAL, true) && $this->due_on !== null && $this->due_on->lt(today());
    }
}
