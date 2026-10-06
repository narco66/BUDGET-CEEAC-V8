<?php

namespace App\Domains\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationRequest extends FormRequest
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
            'subject' => ['required', 'string'],
            'scope' => ['nullable', 'string'],
            'monitoring_period_id' => ['nullable', 'integer', 'exists:monitoring_periods,id'],
            'pap_enrichment_id' => ['nullable', 'integer', 'exists:pap_enrichments,id'],
            'type' => ['required', 'string'],
            'evaluator_role' => ['required', 'string'],
            'criteria' => ['nullable', 'array'],
            'conclusions' => ['nullable', 'string'],
        ];
    }
}
