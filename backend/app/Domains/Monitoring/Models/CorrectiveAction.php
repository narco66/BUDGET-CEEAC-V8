<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectiveAction extends Model
{
    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'performance_variance_id',
        'pap_enrichment_id',
        'description',
        'responsible_role',
        'decided_on',
        'due_on',
        'expected_result',
        'progress',
        'status',
        'last_comment',
        'closed_at',
        'created_by',
        'last_reminded_at',
        'reference',
        'anomaly',
        'cause',
        'responsible_label',
        'se_problem_id',
    ];

    /**
     * @var list<string>
     */
    public const STATUSES = ['ouverte', 'en_cours', 'realisee', 'cloturee', 'abandonnee'];

    /**
     * @var list<string>
     */
    public const FINAL = ['cloturee', 'abandonnee'];

    protected function casts(): array
    {
        return ['decided_on' => 'date', 'due_on' => 'date', 'closed_at' => 'datetime', 'last_reminded_at' => 'datetime'];
    }

    public function late(): bool
    {
        return ! in_array($this->status, self::FINAL, true) && $this->status !== 'realisee' && $this->due_on !== null && $this->due_on->lt(today());
    }
}
