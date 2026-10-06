<?php

namespace App\Domains\Needs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpressionBesoinRequest extends FormRequest
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
            'budget_line_id' => ['required', 'integer', 'exists:budget_lines,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'budget_line_id.required' => 'La sélection d’une ligne budgétaire est obligatoire.',
        ];
    }
}
