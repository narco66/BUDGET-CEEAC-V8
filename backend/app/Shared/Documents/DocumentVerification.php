<?php

namespace App\Shared\Documents;

/**
 * Adresse publique de vérification. Le domaine vient de la configuration,
 * jamais d’une valeur figée, et le code est un identifiant opaque.
 */
class DocumentVerification
{
    public function __construct(private readonly QrCode $qr) {}

    public function url(string $code): string
    {
        $base = rtrim((string) config('gesbudep.frontend_url'), '/');
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }

        return $base.'/verifier/'.$code;
    }

    /**
     * @return array{code: string, version: int, evenement: string, genere_le: string, url: string, qr: string}
     */
    public function payload(string $code, int $version, string $event, string $generatedAt): array
    {
        $url = $this->url($code);

        return [
            'code' => $code,
            'version' => $version,
            'evenement' => $event,
            'genere_le' => $generatedAt,
            'url' => $url,
            'qr' => $this->qr->dataUri($url),
        ];
    }
}
