<?php

namespace App\Domains\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIndicatorMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * La valeur est saisie directement, ou calculée côté serveur à partir du
     * numérateur et du dénominateur pour un indicateur ratio.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'indicator_id' => ['required', 'integer', 'exists:indicators,id'],
            'monitoring_period_id' => ['required', 'integer', 'exists:monitoring_periods,id'],
            'value' => ['required_without:numerator', 'nullable', 'numeric'],
            'numerator' => ['nullable', 'numeric', 'min:0'],
            'denominator' => ['required_with:numerator', 'nullable', 'numeric'],
            'comment' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'justification' => ['nullable', 'string', 'max:255'],
            'montant_paye' => ['prohibited'],
            'engage' => ['prohibited'],
            'liquide' => ['prohibited'],
            'ordonnance' => ['prohibited'],
            'budget_revise' => ['prohibited'],
        ];
    }
}
