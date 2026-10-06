<?php

namespace App\Domains\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectiveAction extends Model
{
    protected $guarded = [];

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
