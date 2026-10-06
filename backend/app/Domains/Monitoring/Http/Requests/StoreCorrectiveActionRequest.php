<?php

namespace App\Domains\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorrectiveActionRequest extends FormRequest
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
            'performance_variance_id' => ['nullable', 'integer', 'exists:performance_variances,id'],
            'pap_enrichment_id' => ['nullable', 'integer', 'exists:pap_enrichments,id'],
            'description' => ['required', 'string'],
            'responsible_role' => ['required', 'string'],
            'decided_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'expected_result' => ['nullable', 'string'],
        ];
    }
}
