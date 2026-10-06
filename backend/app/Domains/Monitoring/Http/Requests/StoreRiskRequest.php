<?php

namespace App\Domains\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRiskRequest extends FormRequest
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
            'description' => ['required', 'string'],
            'category' => ['required', 'string'],
            'probability' => ['required', 'integer', 'between:1,4'],
            'impact' => ['required', 'integer', 'between:1,4'],
            'responsible_role' => ['required', 'string'],
            'prevention' => ['nullable', 'string'],
            'mitigation' => ['nullable', 'string'],
        ];
    }
}
