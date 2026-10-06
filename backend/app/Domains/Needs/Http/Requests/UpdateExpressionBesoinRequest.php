<?php

namespace App\Domains\Needs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpressionBesoinRequest extends FormRequest
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
            'objet' => ['nullable', 'string', 'max:255'],
            'contexte' => ['nullable', 'string'],
            'justification' => ['nullable', 'string'],
            'urgence' => ['nullable', 'in:faible,normale,urgente'],
            'priorite' => ['nullable', 'in:basse,normale,haute'],
            'resultats_attendus' => ['nullable', 'string'],
            'lignes' => ['nullable', 'array'],
            'lignes.*.designation' => ['required_with:lignes', 'string', 'max:255'],
            'lignes.*.quantite' => ['required_with:lignes', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire' => ['required_with:lignes', 'integer', 'min:0'],
            'lignes.*.unite' => ['nullable', 'string', 'max:32'],
            'lignes.*.description' => ['nullable', 'string'],
            'lignes.*.pap_task_id' => ['nullable', 'integer', 'exists:pap_tasks,id'],
            'lignes.*.beneficiaire' => ['nullable', 'string', 'max:255'],
            'lignes.*.lieu' => ['nullable', 'string', 'max:255'],
            'lignes.*.periode' => ['nullable', 'string', 'max:255'],
            'lignes.*.observation' => ['nullable', 'string', 'max:255'],
            'imputations' => ['nullable', 'array'],
            'imputations.*.budget_line_id' => ['required_with:imputations', 'integer', 'exists:budget_lines,id'],
            'imputations.*.montant' => ['required_with:imputations', 'integer', 'min:0'],
        ];
    }
}
