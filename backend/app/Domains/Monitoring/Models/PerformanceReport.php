<?php

namespace App\Domains\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Rapport de performance figé : le snapshot capturé à la génération n’est
 * jamais recalculé ; un rapport validé ou publié n’est plus modifiable et se
 * corrige par une nouvelle version (description S&E §76-77).
 */
class PerformanceReport extends Model
{
    public const KINDS = ['mensuel', 'trimestriel', 'semestriel', 'annuel', 'pap', 'performance', 'physique_financier'];

    /**
     * @var array<string, array{from: list<string>, to: string}>
     */
    public const TRANSITIONS = [
        'soumettre' => ['from' => ['brouillon'], 'to' => 'en_revue'],
        'retourner' => ['from' => ['en_revue'], 'to' => 'brouillon'],
        'valider' => ['from' => ['en_revue'], 'to' => 'valide'],
        'publier' => ['from' => ['valide'], 'to' => 'publie'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'situation_au' => 'date',
            'validated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $report): void {
            if ($report->isDirty('snapshot') && $report->getOriginal('snapshot') !== null) {
                throw new LogicException('Le snapshot d’un rapport ne se recalcule pas : générez une nouvelle version.');
            }
            if (in_array($report->getOriginal('status'), ['valide', 'publie'], true)) {
                $allowed = ['status', 'published_at', 'updated_at'];
                if (array_diff(array_keys($report->getDirty()), $allowed) !== [] || $report->getOriginal('status') === 'publie') {
                    throw new LogicException('Un rapport validé ou publié n’est plus modifiable.');
                }
            }
        });

        static::deleting(function (self $report): void {
            if ($report->status !== 'brouillon') {
                throw new LogicException('Seul un rapport en brouillon peut être supprimé.');
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MonitoringPeriod::class, 'monitoring_period_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
