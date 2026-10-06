<?php

namespace App\Domains\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPhysicalAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pap_enrichment_id' => ['required', 'integer', 'exists:pap_enrichments,id'],
            'pap_task_id' => ['nullable', 'integer', 'exists:pap_tasks,id'],
            'monitoring_period_id' => ['required', 'integer', 'exists:monitoring_periods,id'],
            'method' => ['required', 'in:quantitative,ponderee,jalon,livrable,binaire'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'planned' => ['required', 'numeric', 'min:0'],
            'atteint' => ['sometimes', 'boolean'],
            'comment' => ['nullable', 'string'],
            'difficulties' => ['nullable', 'string'],
            'exception_motif' => ['nullable', 'string'],
            'montant_paye' => ['prohibited'],
            'engage' => ['prohibited'],
            'liquide' => ['prohibited'],
            'ordonnance' => ['prohibited'],
            'budget_revise' => ['prohibited'],
        ];
    }
}
