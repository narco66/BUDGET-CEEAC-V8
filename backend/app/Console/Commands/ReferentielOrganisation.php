<?php

namespace App\Console\Commands;

use App\Domains\Organization\Services\OrganizationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organisation:referentiel')]
#[Description('Publie l’organigramme officiel 2026 comme source unique, sans toucher au budget')]
class ReferentielOrganisation extends Command
{
    public function handle(OrganizationService $organization): int
    {
        $version = $organization->publierSource();
        $this->info('Référentiel '.$version->code.' publié.');

        return self::SUCCESS;
    }
}
