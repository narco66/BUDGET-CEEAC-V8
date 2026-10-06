<?php

namespace App\Domains\Needs\Http\Resources;

use App\Domains\Needs\Models\EbLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EbLine */
class EbLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pap_task_id' => $this->pap_task_id,
            'designation' => $this->designation,
            'description' => $this->description,
            'quantite' => (float) $this->quantite,
            'unite' => $this->unite,
            'prix_unitaire' => $this->prix_unitaire,
            'montant' => $this->montant,
            'beneficiaire' => $this->beneficiaire,
            'lieu' => $this->lieu,
            'periode' => $this->periode,
            'observation' => $this->observation,
        ];
    }
}
