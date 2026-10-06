<?php

namespace App\Domains\Administration\Services;

use App\Shared\Audit\AuditService;
use Symfony\Component\Process\Process;

class SauvegardeVerifier
{
    /**
     * Contrôle l’en-tête du dump PostgreSQL le plus récent. Ne restaure rien
     * et ne fixe aucun objectif de perte ou de délai de reprise.
     *
     * @return array{valide: bool, fichier: ?string, octets: int}
     */
    public function verifier(?string $repertoire = null): array
    {
        $repertoire ??= storage_path('app/backups');
        $fichiers = is_dir($repertoire) ? glob($repertoire.DIRECTORY_SEPARATOR.'*.dump') : [];
        $fichiers = $fichiers === false ? [] : $fichiers;
        usort($fichiers, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $fichier = $fichiers[0] ?? null;
        $octets = $fichier !== null ? (int) filesize($fichier) : 0;
        $entete = $fichier !== null ? (string) file_get_contents($fichier, false, null, 0, 5) : '';
        $valide = $entete === 'PGDMP';
        app(AuditService::class)->enregistrer(
            null,
            'continuite.sauvegarde_verifiee',
            'sauvegarde',
            $fichier !== null ? basename($fichier) : null,
            null,
            ['valide' => $valide, 'octets' => $octets],
            $valide ? null : 'Aucun dump PostgreSQL lisible.',
            $valide ? 'succes' : 'echec',
        );

        return ['valide' => $valide, 'fichier' => $fichier !== null ? basename($fichier) : null, 'octets' => $octets];
    }

    /**
     * Lit le catalogue du dump avec pg_restore --list. N’écrit dans aucune base.
     *
     * @return array{valide: bool, fichier: ?string, octets: int, inventorie: bool, tables: int}
     */
    public function inventorier(?string $repertoire = null): array
    {
        $rapport = $this->verifier($repertoire);
        $fichier = $this->dernier($repertoire);
        $tables = 0;
        $inventorie = false;
        if ($rapport['valide'] && $fichier !== null) {
            $processus = new Process([$this->binaire(), '--list', $fichier]);
            $processus->setTimeout(120);
            $processus->run();
            if ($processus->isSuccessful()) {
                preg_match_all('/ TABLE DATA /', $processus->getOutput(), $correspondances);
                $tables = count($correspondances[0]);
                $inventorie = $tables > 0;
            }
        }
        app(AuditService::class)->enregistrer(
            null,
            'continuite.sauvegarde_inventoriee',
            'sauvegarde',
            $rapport['fichier'],
            null,
            ['tables' => $tables, 'inventorie' => $inventorie],
            $inventorie ? null : 'Catalogue illisible. Aucune restauration.',
            $inventorie ? 'succes' : 'echec',
        );

        return $rapport + ['inventorie' => $inventorie, 'tables' => $tables];
    }

    private function dernier(?string $repertoire): ?string
    {
        $repertoire ??= storage_path('app/backups');
        $fichiers = is_dir($repertoire) ? glob($repertoire.DIRECTORY_SEPARATOR.'*.dump') : [];
        $fichiers = $fichiers === false ? [] : $fichiers;
        usort($fichiers, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $fichiers[0] ?? null;
    }

    private function binaire(): string
    {
        $connu = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_restore.exe';
        if (PHP_OS_FAMILY === 'Windows' && is_file($connu)) {
            return $connu;
        }

        return 'pg_restore';
    }
}
