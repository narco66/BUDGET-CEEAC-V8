<?php

namespace App\Domains\Needs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnExpressionBesoinRequest extends FormRequest
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
            'motif' => ['required', 'string', 'max:255'],
            'observations' => ['required', 'string', 'max:2000'],
            'champs' => ['nullable', 'array'],
            'champs.*' => ['string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Le motif est obligatoire.',
            'observations.required' => 'Les observations sont obligatoires.',
        ];
    }
}
