<?php

namespace App\Domains\Monitoring\Services;

use App\Domains\Monitoring\Models\SeReferential;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferentialService
{
    /**
     * @return Collection<int, SeReferential>
     */
    public function list(string $kind): Collection
    {
        return SeReferential::query()->where('kind', $kind)->orderBy('label')->get();
    }

    public function assertKeeper(User $user): void
    {
        abort_unless($user->holds('directeur_budget', 'administrateur_fonctionnel'), 403);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(string $kind, array $data): SeReferential
    {
        return SeReferential::query()->create([
            'kind' => $kind,
            'code' => $data['code'],
            'label' => $data['label'],
            'weight' => $data['weight'] ?? null,
            'active' => $data['active'] ?? true,
            'effective_on' => now()->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SeReferential $row, array $data): SeReferential
    {
        $row->fill([
            'label' => $data['label'] ?? $row->label,
            'active' => $data['active'] ?? $row->active,
            'weight' => array_key_exists('weight', $data) ? $data['weight'] : $row->weight,
        ])->save();

        return $row;
    }

    /**
     * @param  list<array{code: string, label: string, weight: float|int}>  $components
     */
    public function replaceScore(array $components): void
    {
        $sum = round(array_sum(array_column($components, 'weight')), 2);
        if ($sum !== 100.0) {
            throw ValidationException::withMessages(['weight' => 'Les poids du score doivent totaliser 100.']);
        }

        DB::transaction(function () use ($components) {
            $version = ((int) SeReferential::query()->where('kind', 'score')->max('formula_version')) + 1;
            $codes = [];
            foreach ($components as $component) {
                $codes[] = $component['code'];
                SeReferential::query()->updateOrCreate(
                    ['kind' => 'score', 'code' => $component['code']],
                    [
                        'label' => $component['label'],
                        'weight' => $component['weight'],
                        'active' => true,
                        'formula_version' => $version,
                        'effective_on' => now()->toDateString(),
                    ],
                );
            }
            SeReferential::query()->where('kind', 'score')->whereNotIn('code', $codes)->update([
                'active' => false,
                'formula_version' => $version,
            ]);
        });
    }

    public function assertActive(string $kind, ?string $code): void
    {
        if (blank($code)) {
            return;
        }
        $exists = SeReferential::query()->where('kind', $kind)->where('code', $code)->where('active', true)->exists();
        if (! $exists) {
            throw ValidationException::withMessages([$kind => 'Cette valeur ne figure pas dans le référentiel actif.']);
        }
    }
}
