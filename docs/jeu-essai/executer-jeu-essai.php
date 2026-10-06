<?php

/*
 * Jeu d'essai exécuté — BUDGET-CEEAC.
 * Usage, depuis backend/ : php ../docs/jeu-essai/executer-jeu-essai.php ../docs/jeu-essai/resultats.json
 * Chaque cas passe par le noyau HTTP réel de l'application (routes, middlewares, politiques,
 * validations, services) sur la base locale budget_ceeac_v8. Tout s'exécute dans une transaction
 * annulée à la fin : aucune donnée n'est conservée. Les fichiers sont écrits sur un disque factice.
 */

$base = dirname(__DIR__, 2).'/backend';
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (DB::connection()->getDatabaseName() !== 'budget_ceeac_v8' || app()->environment('production')) {
    fwrite(STDERR, "Base inattendue\n");
    exit(1);
}

config(['mail.default' => 'array', 'queue.default' => 'sync']);
Storage::fake('local');
Storage::fake(config('filesystems.default'));

$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$out = $argv[1] ?? __DIR__.'/resultats.json';

final class Echec extends RuntimeException {}

/** @var array<string, User> */
$users = [];
function u(string $login): User
{
    global $users;
    $email = str_contains($login, '@') ? $login : $login.'@ceeac.int';

    return $users[$email] ??= User::query()->where('email', $email)->firstOrFail();
}

/**
 * Appel HTTP à travers le noyau. Un point de sauvegarde isole chaque requête :
 * une erreur serveur n'interrompt pas la transaction globale.
 *
 * @return array{status:int, json:mixed, body:string}
 */
function api(string $method, string $uri, array $data = [], ?User $as = null, array $files = []): array
{
    global $kernel;
    app('auth')->forgetGuards();
    if ($as !== null) {
        app('auth')->guard('web')->setUser($as->fresh());
        app('auth')->shouldUse('web');
    }
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
    if ($files !== [] || $method === 'GET') {
        $request = Request::create('/api/v1/'.$uri, $method, $data, [], $files, $server);
    } else {
        $server['CONTENT_TYPE'] = 'application/json';
        $request = Request::create('/api/v1/'.$uri, $method, [], [], [], $server, json_encode($data));
    }
    DB::beginTransaction();
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $status >= 500 ? DB::rollBack() : DB::commit();
    $kernel->terminate($request, $response);
    if ($response instanceof Symfony\Component\HttpFoundation\StreamedResponse) {
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
    } elseif ($response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse) {
        $body = (string) file_get_contents($response->getFile()->getPathname());
    } else {
        $body = (string) $response->getContent();
    }

    return ['status' => $status, 'json' => json_decode($body, true), 'body' => $body];
}

function pdf(string $name): UploadedFile
{
    return UploadedFile::fake()->create($name, 20, 'application/pdf');
}

function attendre(array $r, int $status, string $quoi = ''): array
{
    if ($r['status'] !== $status) {
        $msg = $r['json']['message'] ?? substr($r['body'], 0, 200);
        throw new Echec("HTTP {$r['status']} au lieu de {$status}".($quoi ? " ({$quoi})" : '').' : '.$msg);
    }

    return $r;
}

function erreurs(array $r): string
{
    return implode(', ', array_keys($r['json']['errors'] ?? []));
}

function message(array $r): string
{
    $errors = $r['json']['errors'] ?? [];
    $first = $errors ? (is_array(reset($errors)) ? reset($errors)[0] : reset($errors)) : ($r['json']['message'] ?? '');

    return trim((string) $first);
}

function verifier(bool $condition, string $message): void
{
    if (! $condition) {
        throw new Echec($message);
    }
}

$resultats = [];
$ctx = [];

/**
 * @param  string[]  $etapes
 */
function cas(string $id, string $module, string $titre, string $profil, string $donnees, array $etapes, string $attendu, callable $fn): void
{
    global $resultats;
    $debut = microtime(true);
    DB::beginTransaction();
    try {
        $obtenu = (string) $fn();
        $statut = 'Conforme';
        DB::commit();
    } catch (Echec $e) {
        DB::rollBack();
        $obtenu = $e->getMessage();
        $statut = 'Non conforme';
    } catch (Throwable $e) {
        DB::rollBack();
        $obtenu = get_class($e).' : '.$e->getMessage();
        $statut = str_starts_with($e->getMessage(), 'NE:') ? 'Non exécuté' : 'Non conforme';
        if ($statut === 'Non exécuté') {
            $obtenu = substr($e->getMessage(), 3);
        }
    }
    $resultats[] = compact('id', 'module', 'titre', 'profil', 'donnees', 'etapes', 'attendu', 'obtenu', 'statut') + ['duree_ms' => (int) ((microtime(true) - $debut) * 1000)];
    fwrite(STDERR, sprintf("%-8s %-13s %s\n", $id, $statut, $titre));
}

function prerequis(bool $ok, string $raison): void
{
    if (! $ok) {
        throw new RuntimeException('NE:'.$raison);
    }
}

function ligne(string $code): array
{
    $r = attendre(api('GET', 'lignes-budgetaires', ['q' => $code, 'per_page' => 50], u('directeur.budget')), 200, 'lecture des lignes');
    foreach ($r['json']['data'] ?? [] as $row) {
        if (($row['code'] ?? null) === $code) {
            return $row;
        }
    }
    throw new Echec('Ligne '.$code.' introuvable');
}

function solde(string $code, string $cle): int
{
    return (int) (ligne($code)['soldes'][$cle] ?? 0);
}

function acteurEb(array $eb): User
{
    $step = $eb['etape'] ?? $eb['workflow_step'] ?? null;

    return match ($step) {
        'directeur' => u('jp.okombi'),
        'commissaire' => u('serge.mabika'),
        'secretaire_general' => u('aline.moussavou'),
        'ordonnateur' => u('ordonnateur'),
        default => throw new Echec('Étape EB inattendue : '.json_encode($step)),
    };
}


/**
 * Fait parcourir à un nouveau dossier la chaîne de dépense par l'API, jusqu'à l'étape demandée :
 * eng (engagement à instruire), liq (liquidation ouverte), ord (ordre à signer), pay (paiement généré),
 * autorise (paiement autorisé). Chaque appel passe par les mêmes contrôles qu'à l'écran.
 *
 * @return array<string, int>
 */
function dossier(string $objet, int $montant, string $jusqua): array
{
    $ligneId = ligne('203232')['id'];
    $types = DB::table('document_types')->where('operation', 'engagement')->where('required', true)->where('active', true)->pluck('label')->all() ?: ['Termes de référence'];
    $ids = [];
    $eb = attendre(api('POST', 'expressions-besoin', ['budget_line_id' => $ligneId], u('clarisse.ndong')), 201, 'création EB')['json']['data'];
    $ids['eb'] = $eb['id'];
    attendre(api('PATCH', "expressions-besoin/{$eb['id']}", ['objet' => $objet, 'justification' => 'Dossier du jeu d’essai.', 'lignes' => [['designation' => $objet, 'quantite' => 1, 'unite' => 'forfait', 'prix_unitaire' => $montant]]], u('clarisse.ndong')), 200, 'détail EB');
    foreach ($types as $type) {
        attendre(api('POST', "expressions-besoin/{$eb['id']}/documents", ['type' => $type], u('clarisse.ndong'), ['fichier' => pdf('piece.pdf')]), 200, 'pièce EB');
    }
    $eb = attendre(api('POST', "expressions-besoin/{$eb['id']}/soumettre", [], u('clarisse.ndong')), 200, 'soumission EB')['json']['data'];
    for ($i = 0; $i < 4 && ($eb['etape'] ?? '') !== 'ordonnateur'; $i++) {
        $eb = attendre(api('POST', "expressions-besoin/{$eb['id']}/valider", [], acteurEb($eb)), 200, 'validation EB')['json']['data'];
    }
    attendre(api('POST', "expressions-besoin/{$eb['id']}/approuver", [], u('ordonnateur')), 200, 'approbation EB');
    $ids['eng'] = (int) App\Domains\Commitments\Models\Engagement::query()->where('expression_besoin_id', $eb['id'])->value('id');
    if ($jusqua === 'eng') {
        return $ids;
    }
    $tiers = App\Domains\Suppliers\Models\Tiers::query()->where('nif', 'JEU-NIF-001')->firstOrFail();
    attendre(api('PATCH', "engagements/{$ids['eng']}", ['tiers_id' => $tiers->id], u('blaise.essono')), 200, 'bénéficiaire');
    attendre(api('POST', "engagements/{$ids['eng']}/transmettre", [], u('blaise.essono')), 200, 'transmission expert');
    attendre(api('POST', "engagements/{$ids['eng']}/transmettre", [], u('chef.budget')), 200, 'transmission chef');
    attendre(api('POST', "engagements/{$ids['eng']}/transmettre", [], u('directeur.budget')), 200, 'transmission directeur');
    attendre(api('POST', "engagements/{$ids['eng']}/viser", [], u('controleur.financier')), 200, 'visa ENG');
    $ids['liq'] = (int) App\Domains\Commitments\Models\Liquidation::query()->where('engagement_id', $ids['eng'])->value('id');
    if ($jusqua === 'liq') {
        return $ids;
    }
    attendre(api('POST', "liquidations/{$ids['liq']}/certifier", ['montant_accepte' => $montant], u('clarisse.ndong')), 200, 'service fait');
    attendre(api('POST', "liquidations/{$ids['liq']}/facture", ['numero' => 'FAC-'.strtoupper(Str::random(8)), 'date' => '2026-10-03', 'montant_ht' => $montant, 'taxes' => 0], u('clarisse.ndong')), 200, 'facture');
    attendre(api('POST', "liquidations/{$ids['liq']}/soumettre", [], u('clarisse.ndong')), 200, 'soumission LIQ');
    attendre(api('POST', "liquidations/{$ids['liq']}/viser", [], u('controleur.financier')), 200, 'visa LIQ');
    $ids['ord'] = (int) App\Domains\Commitments\Models\Ordonnancement::query()->where('liquidation_id', $ids['liq'])->value('id');
    if ($jusqua === 'ord') {
        return $ids;
    }
    attendre(api('POST', "ordonnancements/{$ids['ord']}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'], u('aline.moussavou')), 200, 'signature ORD');
    $ids['pay'] = (int) App\Domains\Commitments\Models\Paiement::query()->where('ordonnancement_id', $ids['ord'])->value('id');
    attendre(api('POST', "paiements/{$ids['pay']}/prendre-en-charge", [], u('rita.obame')), 200, 'prise en charge');
    if ($jusqua === 'pay') {
        return $ids;
    }
    $pay = App\Domains\Commitments\Models\Paiement::query()->findOrFail($ids['pay']);
    $compte = app(App\Domains\Suppliers\Services\TiersService::class)->eligibleAccounts($pay)->first();
    attendre(api('POST', "paiements/{$ids['pay']}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $compte->id, 'compte_ceeac' => 'CEEAC-01'], u('rita.obame')), 200, 'préparation');
    attendre(api('POST', "paiements/{$ids['pay']}/soumettre", [], u('rita.obame')), 200, 'soumission PAY');
    attendre(api('POST', "paiements/{$ids['pay']}/valider", [], u('marc.ndzie')), 200, 'validation PAY');
    attendre(api('POST', "paiements/{$ids['pay']}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'], u('paul.nguema')), 200, 'autorisation PAY');

    return $ids;
}

function statut(string $table, int $id): string
{
    return (string) DB::table($table)->where('id', $id)->value('status');
}

/** Titre de recette pris en charge, par des acteurs distincts. */
function titreRecouvrable(int $exo, int $cat, int $montant, string $debiteur): int
{
    $id = attendre(api('POST', 'recettes/titres', ['exercice_id' => $exo, 'category_id' => $cat, 'debtor_type' => 'autre', 'debtor_label' => $debiteur, 'montant' => $montant, 'echeance' => '2026-09-30', 'motif' => 'Jeu d’essai — '.$debiteur], u('rita.obame')), 201, 'titre')['json']['data']['id'];
    attendre(api('POST', "recettes/titres/{$id}/soumettre", [], u('rita.obame')), 200, 'soumission titre');
    attendre(api('POST', "recettes/titres/{$id}/verifier", [], u('blaise.essono')), 200, 'vérification titre');
    attendre(api('POST', "recettes/titres/{$id}/valider", [], u('directeur.budget')), 200, 'validation titre');
    attendre(api('POST', "recettes/titres/{$id}/prendre-en-charge", [], u('marc.ndzie')), 200, 'prise en charge titre');

    return (int) $id;
}

/** Valide une saisie S&E (mesure ou réalisation) par les valideurs successifs. */
function validerSaisie(string $base, int $id, string $table): array
{
    $statuts = [];
    foreach ([u('jp.okombi'), u('directeur.budget')] as $v) {
        if (! in_array(DB::table($table)->where('id', $id)->value('status'), ['soumis', 'valide_responsable'], true)) {
            break;
        }
        attendre(api('POST', "{$base}/{$id}/valider", [], $v), 200, 'validation par '.$v->email);
        $statuts[] = DB::table($table)->where('id', $id)->value('status');
    }

    return $statuts;
}

$started = microtime(true);
$temoins = fn () => collect(["expression_besoins", "engagements", "liquidations", "ordonnancements", "paiements", "revenue_orders", "credit_movements", "users"])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
$avantTemoins = $temoins();
$codeArchive = DB::table('generated_documents')->whereNotNull('verification_code')->orderByDesc('id')->value('verification_code');
DB::beginTransaction();
RateLimiter::clear('login');

try {
    $LIGNE = '203232';
    $AUTRE = '21323';

    // ───────────────────────────── Authentification et habilitations
    cas('AUT-01', 'Authentification', 'Connexion avec des identifiants valides', 'Initiateur', 'clarisse.ndong@ceeac.int / mot de passe de démonstration',
        ['Saisir l’adresse électronique et le mot de passe', 'Valider'],
        'Connexion acceptée (HTTP 200), profil « initiateur » restitué.',
        function () {
            $r = attendre(api('POST', 'auth/login', ['email' => 'clarisse.ndong@ceeac.int', 'password' => 'password']), 200);
            verifier(($r['json']['data']['role'] ?? null) === 'initiateur', 'Rôle restitué : '.json_encode($r['json']['data']['role'] ?? null));

            return 'HTTP 200, rôle initiateur, '.($r['json']['data']['nom'] ?? '').' — '.($r['json']['data']['structure'] ?? '');
        });

    cas('AUT-02', 'Authentification', 'Connexion avec un mot de passe erroné', 'Initiateur', 'clarisse.ndong@ceeac.int / « mauvais-mdp »',
        ['Saisir un mot de passe erroné', 'Valider'],
        'Connexion refusée (HTTP 422), message d’erreur, aucune session ouverte.',
        function () {
            $r = attendre(api('POST', 'auth/login', ['email' => 'clarisse.ndong@ceeac.int', 'password' => 'mauvais-mdp']), 422);

            return 'HTTP 422 : « '.message($r).' »';
        });

    cas('AUT-03', 'Authentification', 'Connexion d’un compte désactivé', 'Agent désactivé', 'Compte « jeu.desactive@ceeac.int » créé désactivé pour le test',
        ['Créer le compte désactivé', 'Tenter la connexion'],
        'Connexion refusée (HTTP 403).',
        function () {
            User::query()->create(['name' => 'Agent désactivé', 'email' => 'jeu.desactive@ceeac.int', 'password' => 'password', 'role' => 'initiateur', 'account_status' => 'desactive']);
            $r = attendre(api('POST', 'auth/login', ['email' => 'jeu.desactive@ceeac.int', 'password' => 'password']), 403);

            return 'HTTP 403 : « '.message($r).' »';
        });

    cas('AUT-04', 'Authentification', 'Accès à une API sans être connecté', 'Anonyme', 'GET /expressions-besoin sans session',
        ['Appeler la liste des expressions de besoin sans authentification'],
        'Accès refusé (HTTP 401).',
        fn () => 'HTTP '.attendre(api('GET', 'expressions-besoin'), 401)['status']);

    cas('AUT-05', 'Authentification', 'Profil de l’utilisateur connecté', 'Comptable', 'rita.obame@ceeac.int',
        ['Ouvrir l’application connecté'],
        'Le profil courant restitue le rôle « comptable ».',
        function () {
            $r = attendre(api('GET', 'auth/me', [], u('rita.obame')), 200);
            $role = $r['json']['data']['role'] ?? null;
            verifier($role === 'comptable', 'Rôle : '.json_encode($role));

            return 'HTTP 200, rôle comptable';
        });

    cas('HAB-01', 'Habilitations', 'Un initiateur n’accède pas à l’administration des comptes', 'Initiateur', 'clarisse.ndong — GET /admin/utilisateurs',
        ['Ouvrir Administration › Utilisateurs'],
        'Accès refusé (HTTP 403).',
        fn () => 'HTTP '.attendre(api('GET', 'admin/utilisateurs', [], u('clarisse.ndong')), 403)['status']);

    cas('HAB-02', 'Habilitations', 'Un comptable ne crée pas d’expression de besoin', 'Comptable', 'rita.obame — ligne '.$LIGNE,
        ['Tenter de créer une expression de besoin'],
        'Création refusée (HTTP 403).',
        function () use ($LIGNE) {
            $id = ligne($LIGNE)['id'];

            return 'HTTP '.attendre(api('POST', 'expressions-besoin', ['budget_line_id' => $id], u('rita.obame')), 403)['status'];
        });

    cas('HAB-03', 'Habilitations', 'L’auditeur consulte le journal d’audit', 'Auditeur', 'chantal.ibinga — GET /admin/audit',
        ['Ouvrir Administration › Journal d’audit'],
        'Consultation autorisée (HTTP 200), événements listés.',
        function () {
            $r = attendre(api('GET', 'admin/audit', [], u('chantal.ibinga')), 200);

            return 'HTTP 200, '.count($r['json']['data'] ?? []).' événements sur la page';
        });

    // ───────────────────────────── Expression de besoin
    $engageAvant = solde($LIGNE, 'engage');
    $dispoAvant = solde($LIGNE, 'disponible');
    $ctx['ligne'] = ligne($LIGNE);
    $donnees = ['lignes' => [$LIGNE => ligne($LIGNE), $AUTRE => ligne($AUTRE)], 'seuil' => DB::table('business_rules')->where('code', 'plafond_caisse')->value('value'), 'tiers' => App\Domains\Suppliers\Models\Tiers::query()->where('nif', 'JEU-NIF-001')->first(['code', 'raison_sociale', 'nif'])?->toArray(), 'code_archive' => $codeArchive];

    cas('EB-01', 'Expression de besoin', 'Création d’un brouillon sur une ligne de la structure', 'Initiateur', "clarisse.ndong (DATI-DENER) — ligne {$LIGNE}",
        ['Mes tâches › Expressions de besoin › Nouvelle expression de besoin', 'Choisir la ligne '.$LIGNE],
        'Brouillon créé (HTTP 201) avec une référence EB/2026/… et le statut « brouillon ».',
        function () use (&$ctx) {
            $r = attendre(api('POST', 'expressions-besoin', ['budget_line_id' => $ctx['ligne']['id']], u('clarisse.ndong')), 201);
            $ctx['eb'] = $r['json']['data'];
            verifier($ctx['eb']['statut'] === 'brouillon', 'Statut : '.$ctx['eb']['statut']);

            return 'HTTP 201, '.$ctx['eb']['reference'].', statut brouillon';
        });

    cas('EB-02', 'Expression de besoin', 'Soumission d’un brouillon incomplet', 'Initiateur', 'Brouillon EB-01 sans objet, justification, sous-ligne ni pièce',
        ['Cliquer sur Soumettre'],
        'Soumission refusée (HTTP 422) ; champs signalés : objet, justification, lignes, documents.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eb']), 'EB-01 non réalisé');
            $r = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/soumettre", [], u('clarisse.ndong')), 422);
            $champs = array_keys($r['json']['errors'] ?? []);
            foreach (['objet', 'justification', 'lignes', 'documents'] as $c) {
                verifier(in_array($c, $champs, true), 'Champ non signalé : '.$c.' (signalés : '.implode(', ', $champs).')');
            }

            return 'HTTP 422 ; champs signalés : '.implode(', ', $champs);
        });

    cas('EB-03', 'Expression de besoin', 'Saisie de la description et des sous-lignes', 'Initiateur',
        'Objet « Jeu d’essai — atelier régional efficacité énergétique » ; sous-lignes : location de salle 1 × 700 000 ; pauses-café 50 × 10 000',
        ['Étape Description : objet, justification, urgence normale, priorité haute', 'Étape Tâches et détails : deux sous-lignes'],
        'Enregistrement accepté (HTTP 200) ; montant total calculé = 1 200 000 FCFA.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eb']), 'EB-01 non réalisé');
            $r = attendre(api('PATCH', "expressions-besoin/{$ctx['eb']['id']}", [
                'objet' => 'Jeu d’essai — atelier régional efficacité énergétique',
                'justification' => 'Renforcer les capacités des points focaux nationaux.',
                'urgence' => 'normale', 'priorite' => 'haute',
                'lignes' => [
                    ['designation' => 'Location de salle', 'quantite' => 1, 'unite' => 'forfait', 'prix_unitaire' => 700000],
                    ['designation' => 'Pauses-café', 'quantite' => 50, 'unite' => 'personne', 'prix_unitaire' => 10000],
                ],
            ], u('clarisse.ndong')), 200);
            $total = (int) ($r['json']['data']['montant'] ?? $r['json']['data']['montant_total'] ?? 0);
            verifier($total === 1200000, 'Montant : '.$total);

            return 'HTTP 200, montant 1 200 000 FCFA';
        });

    cas('EB-04', 'Expression de besoin', 'Ajout des pièces justificatives', 'Initiateur', 'Pièces PDF pour chaque type obligatoire du référentiel',
        ['Étape Pièces jointes : choisir le type, déposer le fichier, Joindre la pièce'],
        'Chaque pièce est acceptée (HTTP 200) et listée sur le dossier.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eb']), 'EB-01 non réalisé');
            $types = DB::table('document_types')->where('operation', 'engagement')->where('required', true)->where('active', true)->pluck('label')->all() ?: ['Termes de référence'];
            foreach ($types as $type) {
                attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/documents", ['type' => $type], u('clarisse.ndong'), ['fichier' => pdf(Str::slug($type).'.pdf')]), 200, $type);
            }

            return 'HTTP 200 pour '.count($types).' pièce(s) : '.implode(', ', $types);
        });

    cas('EB-05', 'Expression de besoin', 'Contrôle de disponibilité à la soumission', 'Initiateur', 'Second brouillon sur la ligne '.$LIGNE.' : 1 × 900 000 000 FCFA',
        ['Créer un brouillon', 'Saisir une sous-ligne de 900 000 000', 'Joindre une pièce', 'Soumettre'],
        'Soumission refusée (HTTP 422) pour crédit insuffisant (champ « credit »).',
        function () use (&$ctx) {
            $id = attendre(api('POST', 'expressions-besoin', ['budget_line_id' => $ctx['ligne']['id']], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('PATCH', "expressions-besoin/{$id}", ['objet' => 'Jeu d’essai — besoin excédant le crédit', 'justification' => 'Contrôle de disponibilité.', 'lignes' => [['designation' => 'Forfait', 'quantite' => 1, 'unite' => 'forfait', 'prix_unitaire' => 900000000]]], u('clarisse.ndong')), 200);
            foreach (DB::table('document_types')->where('operation', 'engagement')->where('required', true)->where('active', true)->pluck('label')->all() ?: ['Termes de référence'] as $type) {
                api('POST', "expressions-besoin/{$id}/documents", ['type' => $type], u('clarisse.ndong'), ['fichier' => pdf('piece.pdf')]);
            }
            $r = attendre(api('POST', "expressions-besoin/{$id}/soumettre", [], u('clarisse.ndong')), 422);
            verifier(array_key_exists('credit', $r['json']['errors'] ?? []), 'Erreurs : '.erreurs($r));

            return 'HTTP 422 : « '.message($r).' »';
        });

    cas('EB-06', 'Expression de besoin', 'Soumission d’un dossier complet', 'Initiateur', 'Dossier EB-01 complété (EB-03, EB-04)',
        ['Étape Soumission : Soumettre'],
        'Statut « soumise » ; le dossier attend le Directeur de la structure.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eb']), 'EB-01 non réalisé');
            $r = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/soumettre", [], u('clarisse.ndong')), 200);
            $ctx['eb'] = $r['json']['data'];

            return 'HTTP 200, statut '.$ctx['eb']['statut'].', étape '.($ctx['eb']['etape'] ?? $ctx['eb']['workflow_step'] ?? '?');
        });

    cas('TAC-01', 'Mes tâches', 'La soumission crée une tâche pour le valideur', 'Directeur', 'jp.okombi (Direction de l’Énergie)',
        ['Ouvrir Mes tâches'],
        'Une tâche ouverte référence l’expression de besoin soumise.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eb']['reference']), 'EB-06 non réalisé');
            $r = attendre(api('GET', 'taches', ['per_page' => 100], u('jp.okombi')), 200);
            $trouve = collect($r['json']['data'] ?? [])->first(fn ($t) => str_contains(json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ctx['eb']['reference']));
            verifier($trouve !== null, 'Aucune tâche ne cite '.$ctx['eb']['reference']);

            return 'Tâche présente : « '.($trouve['titre'] ?? $trouve['libelle'] ?? $trouve['label'] ?? $ctx['eb']['reference']).' »';
        });

    cas('EB-07', 'Expression de besoin', 'Un directeur d’une autre structure ne peut pas valider', 'Directeur (DSI)', 'dsi.directeur — dossier EB-06',
        ['Tenter Valider'],
        'Action refusée (HTTP 403).',
        function () use (&$ctx) {
            prerequis(isset($ctx['eb']), 'EB-06 non réalisé');

            return 'HTTP '.attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/valider", [], u('dsi.directeur')), 403)['status'];
        });

    cas('EB-08', 'Expression de besoin', 'Retour pour correction sans motif', 'Directeur', 'jp.okombi — motif vide',
        ['Cliquer sur Retourner', 'Laisser le motif vide'],
        'Refus (HTTP 422) : motif et observations obligatoires.',
        function () use (&$ctx) {
            $r = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/retourner", [], u('jp.okombi')), 422);

            return 'HTTP 422 ; champs signalés : '.erreurs($r);
        });

    cas('EB-09', 'Expression de besoin', 'Retour pour correction puis nouvelle soumission', 'Directeur, puis Initiateur',
        'Motif « Préciser le nombre de participants » ; champ concerné : justification',
        ['Directeur : Retourner avec motif et observations', 'Initiateur : compléter la justification', 'Initiateur : Soumettre'],
        'Statut « retournee » après le retour ; « soumise » après la nouvelle soumission ; événement de retour tracé.',
        function () use (&$ctx) {
            $r = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/retourner", ['motif' => 'Préciser le nombre de participants', 'observations' => 'Indiquer le nombre de points focaux attendus.', 'champs' => ['justification']], u('jp.okombi')), 200);
            verifier($r['json']['data']['statut'] === 'retournee', 'Statut après retour : '.$r['json']['data']['statut']);
            attendre(api('PATCH', "expressions-besoin/{$ctx['eb']['id']}", ['justification' => 'Renforcer les capacités de 50 points focaux nationaux.'], u('clarisse.ndong')), 200);
            $r2 = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/soumettre", [], u('clarisse.ndong')), 200);
            $ctx['eb'] = $r2['json']['data'];
            $evt = DB::table('eb_events')->where('expression_besoin_id', $ctx['eb']['id'])->where('action', 'retour')->exists();
            verifier($evt, 'Événement de retour absent');

            return 'retournee → soumise ; événement « retour » journalisé';
        });

    cas('NOT-01', 'Notifications', 'L’initiateur est notifié du retour', 'Initiateur', 'clarisse.ndong',
        ['Ouvrir la cloche ou Notifications'],
        'Une notification non lue concerne le dossier retourné.',
        function () use (&$ctx) {
            $r = attendre(api('GET', 'notifications', ['per_page' => 50], u('clarisse.ndong')), 200);
            $n = collect($r['json']['data'] ?? [])->first(fn ($x) => str_contains(json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ctx['eb']['reference']));
            verifier($n !== null, 'Aucune notification pour '.$ctx['eb']['reference']);

            return 'Notification : « '.($n['titre'] ?? $n['title'] ?? $n['message'] ?? 'présente').' »';
        });

    cas('EB-10', 'Expression de besoin', 'Validation par chaque acteur du circuit', 'Directeur, Commissaire ou Secrétaire général',
        'Acteur déterminé par l’étape : directeur de la structure, puis commissaire (PAP) ou Secrétaire général (hors PAP)',
        ['Chaque valideur ouvre le dossier et clique sur Valider'],
        'Le dossier progresse jusqu’à l’étape « ordonnateur ».',
        function () use (&$ctx) {
            $parcours = [];
            for ($i = 0; $i < 4 && ($ctx['eb']['etape'] ?? $ctx['eb']['workflow_step']) !== 'ordonnateur'; $i++) {
                $acteur = acteurEb($ctx['eb']);
                $ctx['eb'] = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/valider", [], $acteur), 200, $acteur->email)['json']['data'];
                $parcours[] = $acteur->email;
            }
            verifier(($ctx['eb']['etape'] ?? $ctx['eb']['workflow_step']) === 'ordonnateur', 'Étape finale : '.($ctx['eb']['etape'] ?? '?'));

            return 'Validé par '.implode(' → ', $parcours).' ; étape ordonnateur';
        });

    cas('EB-11', 'Expression de besoin', 'Approbation par l’ordonnateur et génération de l’engagement', 'Ordonnateur', 'ordonnateur@ceeac.int',
        ['Ouvrir le dossier', 'Cliquer sur Approuver'],
        'Le bouton Valider est refusé à cette étape (403). L’approbation passe le dossier à « transformee_engagement » et crée un engagement ENG-2026-… de 1 200 000 FCFA.',
        function () use (&$ctx) {
            attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/valider", [], u('ordonnateur')), 403, 'Valider réservé aux étapes intermédiaires');
            $ctx['eb'] = attendre(api('POST', "expressions-besoin/{$ctx['eb']['id']}/approuver", [], u('ordonnateur')), 200)['json']['data'];
            verifier($ctx['eb']['statut'] === 'transformee_engagement', 'Statut : '.$ctx['eb']['statut']);
            $eng = App\Domains\Commitments\Models\Engagement::query()->where('expression_besoin_id', $ctx['eb']['id'])->first();
            verifier($eng !== null, 'Aucun engagement créé');
            verifier((int) $eng->montant === 1200000, 'Montant engagé : '.$eng->montant);
            $ctx['eng_id'] = $eng->id;

            return 'Valider : HTTP 403 ; Approuver : statut transformee_engagement, engagement '.$eng->reference.' de 1 200 000 FCFA';
        });

    cas('EB-12', 'Expression de besoin', 'Pas de double génération d’engagement', 'Initiateur', 'Dossier déjà transformé',
        ['Tenter Générer l’engagement une seconde fois'],
        'Action refusée ; un seul engagement existe pour le dossier.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eng_id']), 'EB-11 non réalisé');
            $r = api('POST', "expressions-besoin/{$ctx['eb']['id']}/transformer", [], u('clarisse.ndong'));
            $n = App\Domains\Commitments\Models\Engagement::query()->where('expression_besoin_id', $ctx['eb']['id'])->count();
            verifier($r['status'] >= 400 && $n === 1, 'HTTP '.$r['status'].', engagements : '.$n);

            return 'HTTP '.$r['status'].' ; '.$n.' engagement pour le dossier';
        });

    cas('EB-13', 'Expression de besoin', 'Rejet définitif d’un dossier', 'Directeur', 'Troisième dossier « Jeu d’essai — mission non programmée », 300 000 FCFA',
        ['Initiateur : créer, compléter, soumettre', 'Directeur : Rejeter avec motif'],
        'Statut « rejetee » ; le dossier n’est plus modifiable.',
        function () use (&$ctx) {
            $id = attendre(api('POST', 'expressions-besoin', ['budget_line_id' => $ctx['ligne']['id']], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('PATCH', "expressions-besoin/{$id}", ['objet' => 'Jeu d’essai — mission non programmée', 'justification' => 'Mission ponctuelle.', 'lignes' => [['designation' => 'Mission', 'quantite' => 1, 'unite' => 'forfait', 'prix_unitaire' => 300000]]], u('clarisse.ndong')), 200);
            foreach (DB::table('document_types')->where('operation', 'engagement')->where('required', true)->where('active', true)->pluck('label')->all() ?: ['Termes de référence'] as $type) {
                api('POST', "expressions-besoin/{$id}/documents", ['type' => $type], u('clarisse.ndong'), ['fichier' => pdf('piece.pdf')]);
            }
            attendre(api('POST', "expressions-besoin/{$id}/soumettre", [], u('clarisse.ndong')), 200);
            $r = attendre(api('POST', "expressions-besoin/{$id}/rejeter", ['motif' => 'Activité non inscrite au programme', 'observations' => 'Rejet du jeu d’essai.'], u('jp.okombi')), 200);
            verifier($r['json']['data']['statut'] === 'rejetee', 'Statut : '.$r['json']['data']['statut']);
            $m = api('PATCH', "expressions-besoin/{$id}", ['objet' => 'Modification après rejet'], u('clarisse.ndong'));
            verifier($m['status'] >= 400, 'Modification après rejet acceptée');

            return 'statut rejetee ; modification ultérieure refusée (HTTP '.$m['status'].')';
        });

    // ───────────────────────────── Engagement
    $tiers = App\Domains\Suppliers\Models\Tiers::query()->where('nif', 'JEU-NIF-001')->first();

    cas('ENG-01', 'Engagement', 'Fiche de l’engagement généré', 'Expert Budget', 'blaise.essono — engagement issu de EB-12',
        ['Ouvrir Engagements', 'Ouvrir le dossier'],
        'Engagement à l’étape « expert_budget », montant 1 200 000 FCFA, rattaché à l’expression de besoin.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eng_id']), 'EB-12 non réalisé');
            $d = attendre(api('GET', "engagements/{$ctx['eng_id']}", [], u('blaise.essono')), 200)['json']['data'];
            $ctx['eng'] = $d;
            verifier(($d['etape'] ?? null) === 'expert_budget', 'Étape : '.json_encode($d['etape'] ?? null));
            verifier((int) $d['montant'] === 1200000, 'Montant : '.$d['montant']);

            return $d['reference'].', étape expert_budget, 1 200 000 FCFA, EB '.($d['eb_reference'] ?? $d['expression_besoin'] ?? '—');
        });

    cas('ENG-02', 'Engagement', 'Un initiateur ne peut pas instruire l’engagement', 'Initiateur', 'clarisse.ndong',
        ['Tenter Transmettre'],
        'Action refusée (HTTP 403).',
        fn () => 'HTTP '.attendre(api('POST', "engagements/{$ctx['eng_id']}/transmettre", [], u('clarisse.ndong')), 403)['status']);

    cas('ENG-03', 'Engagement', 'Rattachement du bénéficiaire au référentiel des tiers', 'Expert Budget', 'Tiers « '.($tiers?->raison_sociale ?? 'JEU-NIF-001').' » (NIF JEU-NIF-001)',
        ['Onglet Instruction › Bénéficiaire', 'Rechercher le tiers', 'Enregistrer le bénéficiaire'],
        'Bénéficiaire et NIF repris du référentiel.',
        function () use (&$ctx, $tiers) {
            prerequis($tiers !== null, 'Tiers JEU-NIF-001 absent (lancer demo:jeu-essai)');
            $d = attendre(api('PATCH', "engagements/{$ctx['eng_id']}", ['tiers_id' => $tiers->id], u('blaise.essono')), 200)['json']['data'];
            verifier(($d['beneficiaire'] ?? null) === $tiers->raison_sociale, 'Bénéficiaire : '.json_encode($d['beneficiaire'] ?? null));

            return 'Bénéficiaire '.$d['beneficiaire'].', NIF '.($d['beneficiaire_nif'] ?? '—');
        });

    cas('ENG-04', 'Engagement', 'Ajout d’une pièce d’engagement', 'Expert Budget', 'Bon de commande (PDF)',
        ['Pièces obligatoires › Joindre', 'Type « Bon de commande »'],
        'Pièce acceptée (HTTP 200).',
        fn () => 'HTTP '.attendre(api('POST', "engagements/{$ctx['eng_id']}/pieces", ['type' => 'Bon de commande'], u('blaise.essono'), ['fichier' => pdf('bon-de-commande.pdf')]), 200)['status']);

    cas('ENG-05', 'Engagement', 'Transmission par l’Expert Budget', 'Expert Budget', 'Observations « Instruction complète »',
        ['Cliquer sur Transmettre', 'Saisir les observations'],
        'Le dossier passe à l’étape « chef_budget ».',
        function () use (&$ctx) {
            $d = attendre(api('POST', "engagements/{$ctx['eng_id']}/transmettre", ['observations' => 'Instruction complète.'], u('blaise.essono')), 200)['json']['data'];
            verifier($d['etape'] === 'chef_budget', 'Étape : '.$d['etape']);

            return 'étape chef_budget';
        });

    cas('ENG-06', 'Engagement', 'Retour sans motif par le Chef de service Budget', 'Chef de service Budget', 'chef.budget — motif vide',
        ['Cliquer sur Retourner sans motif'],
        'Refus (HTTP 422) : motif obligatoire.',
        fn () => 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', "engagements/{$ctx['eng_id']}/retourner", [], u('chef.budget')), 422)));

    cas('ENG-07', 'Engagement', 'Examen par le Chef de service puis le Directeur du Budget', 'Chef de service Budget, Directeur du Budget', 'chef.budget puis directeur.budget',
        ['Chef de service : Transmettre', 'Directeur du Budget : Transmettre'],
        'Le dossier arrive à l’étape « controleur_financier ».',
        function () use (&$ctx) {
            attendre(api('POST', "engagements/{$ctx['eng_id']}/transmettre", [], u('chef.budget')), 200, 'chef');
            $d = attendre(api('POST', "engagements/{$ctx['eng_id']}/transmettre", [], u('directeur.budget')), 200, 'directeur')['json']['data'];
            verifier($d['etape'] === 'controleur_financier', 'Étape : '.$d['etape']);

            return 'chef_budget → directeur_budget → controleur_financier';
        });

    cas('ENG-08', 'Engagement', 'Visa du Contrôleur financier', 'Contrôleur financier', 'controleur.financier — observations « Visa conforme »',
        ['Onglet Contrôle', 'Cliquer sur Viser'],
        'Statut « transforme_liquidation », engagement verrouillé, liquidation créée ; l’engagé de la ligne augmente de 1 200 000.',
        function () use (&$ctx, $LIGNE, $engageAvant) {
            $d = attendre(api('POST', "engagements/{$ctx['eng_id']}/viser", ['observations' => 'Visa conforme au crédit disponible.'], u('controleur.financier')), 200)['json']['data'];
            verifier($d['statut'] === 'transforme_liquidation', 'Statut : '.$d['statut']);
            $liq = App\Domains\Commitments\Models\Liquidation::query()->where('engagement_id', $ctx['eng_id'])->first();
            verifier($liq !== null, 'Liquidation non créée');
            $ctx['liq_id'] = $liq->id;
            $delta = solde($LIGNE, 'engage') - $engageAvant;
            verifier($delta === 1200000, 'Variation de l’engagé : '.$delta);

            return 'statut transforme_liquidation, verrouillé = '.json_encode($d['verrouille'] ?? null).', liquidation '.$liq->reference.' ; engagé de la ligne +1 200 000';
        });

    cas('ENG-09', 'Engagement', 'Dégagement partiel d’un engagement visé', 'Directeur du Budget', 'Dégagement de 200 000 FCFA, acte « JEU-DEG-01 »',
        ['Menu ⋮ › Dégager', 'Saisir montant, motif, acte'],
        'Dégagement accepté ; engagé de la ligne réduit de 200 000 et disponible augmenté d’autant.',
        function () use (&$ctx, $LIGNE) {
            $avant = solde($LIGNE, 'disponible');
            attendre(api('POST', "engagements/{$ctx['eng_id']}/degager", ['montant' => 200000, 'motif' => 'Effectif réduit à 30 participants', 'acte' => 'JEU-DEG-01'], u('directeur.budget')), 200);
            $delta = solde($LIGNE, 'disponible') - $avant;
            verifier($delta === 200000, 'Variation du disponible : '.$delta);

            return 'HTTP 200 ; disponible de la ligne +200 000';
        });

    // ───────────────────────────── Liquidation
    cas('LIQ-01', 'Liquidation', 'Service fait supérieur au montant engagé', 'Initiateur', 'Montant accepté 5 000 000 FCFA (engagé : 1 000 000 après dégagement)',
        ['Étape Service fait : saisir un montant supérieur', 'Certifier'],
        'Refus (HTTP 422) sur le montant accepté.',
        function () use (&$ctx) {
            prerequis(isset($ctx['liq_id']), 'ENG-08 non réalisé');

            return 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', "liquidations/{$ctx['liq_id']}/certifier", ['montant_accepte' => 5000000], u('clarisse.ndong')), 422));
        });

    cas('LIQ-02', 'Liquidation', 'Certification du service fait', 'Initiateur', 'Montant accepté 1 000 000 ; BL-JEU-RCT-01 ; nature « Prestation de services » ; date 2026-10-02',
        ['Étape Service fait : renseigner la réception', 'Cocher l’attestation', 'Certifier et signer électroniquement'],
        'Service fait « Certifié ».',
        function () use (&$ctx) {
            $d = attendre(api('POST', "liquidations/{$ctx['liq_id']}/certifier", ['montant_accepte' => 1000000, 'bon_livraison' => 'BL-JEU-RCT-01', 'nature_prestation' => 'Prestation de services', 'date_service' => '2026-10-02'], u('clarisse.ndong')), 200)['json']['data'];
            verifier(($d['service_fait'] ?? null) === 'Certifié', 'Service fait : '.json_encode($d['service_fait'] ?? null));

            return 'service fait : Certifié';
        });

    cas('LIQ-03', 'Liquidation', 'Facture sans montant de taxes', 'Initiateur', 'FAC-JEU-RCT-001 du 2026-10-03, HT 1 000 000, taxes non renseignées',
        ['Étape Facture : omettre les taxes', 'Enregistrer la facture'],
        'Refus (HTTP 422) sur le champ taxes.',
        fn () => 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', "liquidations/{$ctx['liq_id']}/facture", ['numero' => 'FAC-JEU-RCT-001', 'date' => '2026-10-03', 'montant_ht' => 1000000], u('clarisse.ndong')), 422)));

    cas('LIQ-04', 'Liquidation', 'Enregistrement de la facture', 'Initiateur', 'FAC-JEU-RCT-001 du 2026-10-03, échéance 2026-11-02, HT 1 000 000, taxes 0, retenue 0, pénalité 0',
        ['Étape Facture : saisir les montants', 'Enregistrer la facture'],
        'Facture enregistrée ; net à payer 1 000 000 FCFA.',
        function () use (&$ctx) {
            $d = attendre(api('POST', "liquidations/{$ctx['liq_id']}/facture", ['numero' => 'FAC-JEU-RCT-001', 'date' => '2026-10-03', 'echeance' => '2026-11-02', 'montant_ht' => 1000000, 'taxes' => 0, 'retenue' => 0, 'penalite' => 0], u('clarisse.ndong')), 200)['json']['data'];
            verifier((int) $d['montant_net'] === 1000000, 'Net : '.$d['montant_net']);

            return 'net à payer 1 000 000 FCFA';
        });

    cas('LIQ-05', 'Liquidation', 'Visa impossible avant la soumission', 'Contrôleur financier', 'Liquidation non soumise',
        ['Tenter Viser'],
        'Action refusée (HTTP 403 ou 422).',
        function () use (&$ctx) {
            $r = api('POST', "liquidations/{$ctx['liq_id']}/viser", [], u('controleur.financier'));
            verifier(in_array($r['status'], [403, 422], true), 'HTTP '.$r['status']);

            return 'HTTP '.$r['status'];
        });

    cas('LIQ-06', 'Liquidation', 'Soumission au Contrôleur financier', 'Initiateur', 'Liquidation complète',
        ['Étape Soumission : Soumettre au CF'],
        'Statut « en_controle ».',
        function () use (&$ctx) {
            $d = attendre(api('POST', "liquidations/{$ctx['liq_id']}/soumettre", [], u('clarisse.ndong')), 200)['json']['data'];
            verifier($d['statut'] === 'en_controle', 'Statut : '.$d['statut']);

            return 'statut en_controle';
        });

    cas('LIQ-07', 'Liquidation', 'Visa de la liquidation', 'Contrôleur financier', 'Observations « Service fait et facture conformes »',
        ['Cliquer sur Viser'],
        'Statut « transformee_ordonnancement » ; ordonnancement créé.',
        function () use (&$ctx) {
            $d = attendre(api('POST', "liquidations/{$ctx['liq_id']}/viser", ['observations' => 'Service fait et facture conformes.'], u('controleur.financier')), 200)['json']['data'];
            verifier($d['statut'] === 'transformee_ordonnancement', 'Statut : '.$d['statut']);
            $ord = App\Domains\Commitments\Models\Ordonnancement::query()->where('liquidation_id', $ctx['liq_id'])->first();
            verifier($ord !== null, 'Ordonnancement non créé');
            $ctx['ord_id'] = $ord->id;

            return 'statut transformee_ordonnancement ; ordonnancement '.$ord->reference;
        });

    cas('LIQ-08', 'Liquidation', 'Facture en doublon pour le même fournisseur', 'Initiateur', 'Une autre liquidation ouverte du même fournisseur reçoit le numéro FAC-JEU-RCT-001 déjà enregistré',
        ['Certifier le service fait', 'Étape Facture : saisir FAC-JEU-RCT-001', 'Soumettre au CF'],
        'Le doublon est signalé à l’enregistrement de la facture ; la soumission est bloquée (HTTP 422, champ facture).',
        function () use (&$ctx) {
            $source = App\Domains\Commitments\Models\Liquidation::query()->findOrFail($ctx['liq_id']);
            $autre = App\Domains\Commitments\Models\Liquidation::query()->where('id', '!=', $source->id)->whereNull('visa_reference')->whereNull('service_fait_at')->where('workflow_step', 'initiateur')->with('engagement.expressionBesoin.initiator')->get()->first(fn ($l) => $l->engagement?->expressionBesoin?->initiator !== null);
            prerequis($autre !== null, 'Aucune autre liquidation ouverte à l’étape initiateur');
            $autre->forceFill(['fournisseur' => $source->fournisseur])->save();
            $auteur = $autre->engagement->expressionBesoin->initiator;
            $montant = (int) $autre->engagement->montant;
            attendre(api('POST', "liquidations/{$autre->id}/certifier", ['montant_accepte' => $montant], $auteur), 200, 'certification');
            $f = attendre(api('POST', "liquidations/{$autre->id}/facture", ['numero' => $source->invoice_number, 'date' => '2026-10-04', 'montant_ht' => $montant, 'taxes' => 0], $auteur), 200, 'facture')['json']['data'];
            $r = api('POST', "liquidations/{$autre->id}/soumettre", [], $auteur);
            verifier($r['status'] === 422 && array_key_exists('facture', $r['json']['errors'] ?? []), 'HTTP '.$r['status'].' '.erreurs($r));

            return 'doublon signalé = '.json_encode($f['doublon'] ?? null).' ; soumission HTTP 422 (facture) : « '.message($r).' »';
        });

    // ───────────────────────────── Ordonnancement
    cas('ORD-01', 'Ordonnancement', 'Détermination de l’ordonnateur compétent selon le seuil', 'Secrétaire général', 'Simulation 4 500 000 puis 6 000 000 FCFA',
        ['Délégations et seuil › Moteur de détermination', 'Saisir le montant', 'Calculer'],
        '4 500 000 → Secrétaire général (ordonnateur délégué) ; 6 000 000 → Président (ordonnateur principal).',
        function () {
            $a = attendre(api('GET', 'ordonnancements/delegations', ['montant' => 4500000], u('aline.moussavou')), 200)['json'];
            $b = attendre(api('GET', 'ordonnancements/delegations', ['montant' => 6000000], u('aline.moussavou')), 200)['json'];
            verifier(($a['simulation']['ordonnateur_role'] ?? null) === 'secretaire_general', '4,5 M : '.json_encode($a['simulation'] ?? null));
            verifier(($b['simulation']['ordonnateur_role'] ?? null) === 'ordonnateur', '6 M : '.json_encode($b['simulation'] ?? null));

            return 'seuil actif '.number_format((int) ($a['seuil_actif'] ?? 0), 0, ',', ' ').' ; 4,5 M → secretaire_general ; 6 M → ordonnateur';
        });

    cas('ORD-02', 'Ordonnancement', 'L’ordonnateur principal ne signe pas sous le seuil', 'Ordonnateur (Président)', 'Ordre de 1 000 000 FCFA',
        ['Tenter Signer l’ordre de paiement'],
        'Signature refusée (HTTP 403) : l’ordre relève du Secrétaire général.',
        function () use (&$ctx) {
            prerequis(isset($ctx['ord_id']), 'LIQ-07 non réalisé');
            $role = App\Domains\Commitments\Models\Ordonnancement::query()->findOrFail($ctx['ord_id'])->ordonnateur_role;
            verifier($role === 'secretaire_general', 'Ordonnateur désigné : '.$role);

            return 'ordonnateur désigné : secretaire_general ; HTTP '.attendre(api('POST', "ordonnancements/{$ctx['ord_id']}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'], u('ordonnateur')), 403)['status'];
        });

    cas('ORD-03', 'Ordonnancement', 'Signature avec un mot de passe erroné', 'Secrétaire général', 'Mot de passe « erreur »',
        ['Signer l’ordre de paiement', 'Cocher la confirmation', 'Saisir un mauvais mot de passe'],
        'Signature refusée (HTTP 422).',
        fn () => 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', "ordonnancements/{$ctx['ord_id']}/signer", ['confirmation' => true, 'mot_de_passe' => 'erreur'], u('aline.moussavou')), 422)));

    cas('ORD-04', 'Ordonnancement', 'Signature et transmission à l’Agence comptable', 'Secrétaire général', 'Confirmation cochée, mot de passe correct',
        ['Signer l’ordre de paiement', 'Confirmer'],
        'Statut « transforme_paiement » ; un paiement est créé.',
        function () use (&$ctx) {
            $d = attendre(api('POST', "ordonnancements/{$ctx['ord_id']}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'], u('aline.moussavou')), 200)['json']['data'];
            verifier($d['statut'] === 'transforme_paiement', 'Statut : '.$d['statut']);
            $pay = App\Domains\Commitments\Models\Paiement::query()->where('ordonnancement_id', $ctx['ord_id'])->first();
            verifier($pay !== null, 'Paiement non créé');
            $ctx['pay_id'] = $pay->id;

            return 'statut transforme_paiement ; paiement '.$pay->reference.' de '.number_format((int) $pay->montant, 0, ',', ' ').' FCFA';
        });

    // ───────────────────────────── Paiement
    cas('PAY-01', 'Paiement', 'Prise en charge par le comptable', 'Comptable', 'rita.obame',
        ['Ouvrir le paiement', 'Prendre en charge'],
        'Prise en charge acceptée (HTTP 200).',
        function () use (&$ctx) {
            prerequis(isset($ctx['pay_id']), 'ORD-04 non réalisé');
            $d = attendre(api('POST', "paiements/{$ctx['pay_id']}/prendre-en-charge", [], u('rita.obame')), 200)['json']['data'];

            return 'HTTP 200, statut '.$d['statut'];
        });

    cas('PAY-02', 'Paiement', 'Règlement en caisse au-delà du plafond', 'Comptable', 'Mode caisse pour 1 000 000 FCFA',
        ['Onglet Coordonnées : mode Caisse', 'Enregistrer'],
        'Refus (HTTP 422) sur le mode : montant supérieur au plafond de caisse.',
        function () use (&$ctx) {
            $plafond = DB::table('business_rules')->where('code', 'plafond_caisse')->value('value');
            $r = attendre(api('POST', "paiements/{$ctx['pay_id']}/preparer", ['mode' => 'caisse'], u('rita.obame')), 422);

            return 'HTTP 422 ('.erreurs($r).') ; plafond de caisse paramétré : '.$plafond.' — « '.message($r).' »';
        });

    cas('PAY-03', 'Paiement', 'Préparation d’un virement vers un compte validé', 'Comptable', 'Mode virement ; compte validé du bénéficiaire ; compte CEEAC « CEEAC-01 »',
        ['Onglet Coordonnées : choisir le compte', 'Enregistrer', 'Soumettre au Chef Comptable'],
        'Préparation acceptée ; statut « a_controler » après soumission.',
        function () use (&$ctx) {
            $pay = App\Domains\Commitments\Models\Paiement::query()->findOrFail($ctx['pay_id']);
            $compte = app(App\Domains\Suppliers\Services\TiersService::class)->eligibleAccounts($pay)->first();
            prerequis($compte !== null, 'Aucun compte validé pour le bénéficiaire');
            attendre(api('POST', "paiements/{$ctx['pay_id']}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $compte->id, 'compte_ceeac' => 'CEEAC-01'], u('rita.obame')), 200);
            $d = attendre(api('POST', "paiements/{$ctx['pay_id']}/soumettre", [], u('rita.obame')), 200)['json']['data'];
            verifier($d['statut'] === 'a_controler', 'Statut : '.$d['statut']);

            return 'compte '.$compte->numero.' ('.$compte->banque.') ; statut a_controler';
        });

    cas('PAY-04', 'Paiement', 'Le comptable ne valide pas sa propre préparation', 'Comptable', 'rita.obame',
        ['Tenter Valider'],
        'Action refusée avec message explicite (HTTP 403 ou 422).',
        function () use (&$ctx) {
            $r = api('POST', "paiements/{$ctx['pay_id']}/valider", [], u('rita.obame'));
            verifier(in_array($r['status'], [403, 422], true), 'HTTP '.$r['status']);

            return 'HTTP '.$r['status'].' : « '.message($r).' »';
        });

    cas('PAY-05', 'Paiement', 'Validation par le Chef comptable', 'Chef comptable', 'marc.ndzie',
        ['Cliquer sur Valider'],
        'Statut « a_signer ».',
        function () use (&$ctx) {
            $d = attendre(api('POST', "paiements/{$ctx['pay_id']}/valider", [], u('marc.ndzie')), 200)['json']['data'];
            verifier($d['statut'] === 'a_signer', 'Statut : '.$d['statut']);

            return 'statut a_signer';
        });

    cas('PAY-06', 'Paiement', 'Autorisation du règlement par l’Agent comptable', 'Agent comptable', 'paul.nguema ; essai avec mot de passe erroné puis correct',
        ['Autoriser le règlement', 'Saisir un mot de passe erroné', 'Recommencer avec le bon mot de passe'],
        'Mauvais mot de passe : HTTP 422. Bon mot de passe : statut « autorise ».',
        function () use (&$ctx) {
            attendre(api('POST', "paiements/{$ctx['pay_id']}/signer", ['confirmation' => true, 'mot_de_passe' => 'erreur'], u('paul.nguema')), 422, 'mot de passe erroné');
            $d = attendre(api('POST', "paiements/{$ctx['pay_id']}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'], u('paul.nguema')), 200)['json']['data'];
            verifier($d['statut'] === 'autorise', 'Statut : '.$d['statut']);

            return 'erroné : HTTP 422 ; correct : statut autorise';
        });

    cas('PAY-07', 'Paiement', 'Exécution sans avis bancaire', 'Comptable', 'Montant 400 000 ; référence VIR-JEU-RCT-01 ; sans pièce',
        ['Exécuter sans joindre l’avis bancaire'],
        'Refus (HTTP 422) sur la preuve.',
        fn () => 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', "paiements/{$ctx['pay_id']}/executer", ['montant' => 400000, 'reference' => 'VIR-JEU-RCT-01', 'date_valeur' => '2026-10-05'], u('rita.obame')), 422)));

    cas('PAY-08', 'Paiement', 'Exécution partielle puis solde', 'Comptable', '400 000 (VIR-JEU-RCT-01) puis 600 000 (VIR-JEU-RCT-02), avis bancaires PDF',
        ['Exécuter 400 000 avec avis', 'Exécuter le solde de 600 000 avec avis'],
        '« paye_partiel » après le premier règlement ; « a_rapprocher » après le solde.',
        function () use (&$ctx, $LIGNE) {
            $ctx['paye_avant'] = solde($LIGNE, 'paye');
            $a = attendre(api('POST', "paiements/{$ctx['pay_id']}/executer", ['montant' => 400000, 'reference' => 'VIR-JEU-RCT-01', 'date_valeur' => '2026-10-05'], u('rita.obame'), ['preuve' => pdf('avis-1.pdf')]), 200)['json']['data'];
            verifier($a['statut'] === 'paye_partiel', 'Après 400 000 : '.$a['statut']);
            $b = attendre(api('POST', "paiements/{$ctx['pay_id']}/executer", ['montant' => 600000, 'reference' => 'VIR-JEU-RCT-02', 'date_valeur' => '2026-10-05'], u('rita.obame'), ['preuve' => pdf('avis-2.pdf')]), 200)['json']['data'];
            verifier($b['statut'] === 'a_rapprocher', 'Après solde : '.$b['statut']);

            return 'paye_partiel → a_rapprocher';
        });

    cas('PAY-09', 'Paiement', 'Rapprochement bancaire', 'Comptable', 'Référence de relevé REL-JEU-RCT-01',
        ['Cliquer sur Rapprocher', 'Saisir la référence du relevé'],
        'Statut « cloture » ; depuis l’exécution, le payé de la ligne a augmenté de 1 000 000.',
        function () use (&$ctx, $LIGNE) {
            $avant = $ctx['paye_avant'] ?? 0;
            $d = attendre(api('POST', "paiements/{$ctx['pay_id']}/rapprocher", ['reference' => 'REL-JEU-RCT-01'], u('rita.obame')), 200)['json']['data'];
            verifier($d['statut'] === 'cloture', 'Statut : '.$d['statut']);
            $apres = solde($LIGNE, 'paye');
            verifier($apres - $avant === 1000000, 'Variation du payé : '.($apres - $avant));

            return 'statut cloture ; payé de la ligne : '.number_format($avant, 0, ',', ' ').' → '.number_format($apres, 0, ',', ' ');
        });

    cas('CHN-01', 'Chaîne de dépense', 'Cohérence de bout en bout', 'Directeur du Budget', 'Dossier EB-01 → PAY',
        ['Ouvrir le Tableau de chaîne', 'Ouvrir la fiche de l’engagement'],
        'Les cinq maillons sont liés ; engagé net 1 000 000, liquidé 1 000 000, payé 1 000 000 ; la ligne reste à l’équilibre.',
        function () use (&$ctx, $LIGNE, $engageAvant, $dispoAvant) {
            $d = attendre(api('GET', "engagements/{$ctx['eng_id']}", [], u('directeur.budget')), 200)['json']['data'];
            $deltaEngage = solde($LIGNE, 'engage') - $engageAvant;
            $deltaDispo = $dispoAvant - solde($LIGNE, 'disponible');
            verifier($deltaEngage === 1000000, 'Variation de l’engagé : '.$deltaEngage);
            verifier($deltaDispo === 1000000, 'Variation du disponible : '.$deltaDispo);
            $tb = attendre(api('GET', 'chaine/tableau-de-bord', [], u('directeur.budget')), 200, 'tableau de chaîne')['json'];
            verifier(isset($tb['data']['pilotage']['maillons']), 'Pilotage absent du tableau de chaîne');

            return 'EB '.$ctx['eb']['reference'].' → '.$d['reference'].' → '.($d['liquidation'] ?? 'LIQ').' ; engagé +1 000 000, disponible −1 000 000 ; tableau de chaîne HTTP 200';
        });

    // ───────────────────────────── Budget
    cas('BUD-01', 'Budget', 'Gel de crédit', 'Directeur du Budget', "Ligne {$AUTRE} ; gel 500 000 ; acte JEU-GEL-RCT",
        ['Lignes budgétaires › Enregistrer un mouvement', 'Type gel', 'Enregistrer le mouvement'],
        'Mouvement créé (HTTP 201) ; disponible de la ligne réduit de 500 000.',
        function () use ($AUTRE) {
            $l = ligne($AUTRE);
            $r = attendre(api('POST', "lignes-budgetaires/{$l['id']}/mouvements", ['kind' => 'gel', 'montant' => 500000, 'motif' => 'Jeu d’essai — mise en réserve', 'acte' => 'JEU-GEL-RCT'], u('directeur.budget')), 201);
            $delta = (int) $l['soldes']['disponible'] - (int) $r['json']['disponible'];
            verifier($delta === 500000, 'Variation : '.$delta);

            return 'HTTP 201 ; disponible '.number_format((int) $l['soldes']['disponible'], 0, ',', ' ').' → '.number_format((int) $r['json']['disponible'], 0, ',', ' ');
        });

    cas('BUD-02', 'Budget', 'Gel supérieur au disponible', 'Directeur du Budget', "Ligne {$AUTRE} ; gel de 999 999 999 999",
        ['Enregistrer un mouvement de gel trop élevé'],
        'Refus (HTTP 422).',
        function () use ($AUTRE) {
            $l = ligne($AUTRE);

            return 'HTTP 422 : « '.message(attendre(api('POST', "lignes-budgetaires/{$l['id']}/mouvements", ['kind' => 'gel', 'montant' => 999999999999, 'motif' => 'Excès', 'acte' => 'JEU-GEL-X'], u('directeur.budget')), 422)).' »';
        });

    cas('BUD-03', 'Budget', 'Virement de crédit entre deux lignes', 'Directeur du Budget', "De {$AUTRE} vers {$LIGNE} ; 100 000 ; acte JEU-VIR-RCT",
        ['Type virement', 'Choisir la ligne de destination', 'Enregistrer le mouvement'],
        'Deux mouvements symétriques ; le révisé de la destination augmente de 100 000.',
        function () use ($AUTRE, $LIGNE) {
            $src = ligne($AUTRE);
            $dst = ligne($LIGNE);
            $r = attendre(api('POST', "lignes-budgetaires/{$src['id']}/mouvements", ['kind' => 'virement', 'montant' => 100000, 'motif' => 'Jeu d’essai — renfort atelier', 'acte' => 'JEU-VIR-RCT', 'destination_id' => $dst['id']], u('directeur.budget')), 201);
            $n = count($r['json']['data'] ?? []);
            $delta = solde($LIGNE, 'revise') - (int) $dst['soldes']['revise'];
            verifier($n === 2, 'Mouvements créés : '.$n);
            verifier($delta === 100000, 'Variation du révisé : '.$delta);

            return '2 mouvements ; révisé de la destination +100 000';
        });

    cas('BUD-04', 'Budget', 'Un initiateur n’enregistre pas de mouvement', 'Initiateur', "clarisse.ndong ; gel de 1 000 sur {$LIGNE}",
        ['Tenter un mouvement'],
        'Refus avec message explicite (HTTP 403 ou 422).',
        function () use ($LIGNE) {
            $r = api('POST', 'lignes-budgetaires/'.ligne($LIGNE)['id'].'/mouvements', ['kind' => 'gel', 'montant' => 1000, 'motif' => 'Test', 'acte' => 'X'], u('clarisse.ndong'));
            verifier(in_array($r['status'], [403, 422], true), 'HTTP '.$r['status']);

            return 'HTTP '.$r['status'].' : « '.message($r).' »';
        });

    cas('BUD-05', 'Clôture', 'Demande de clôture avec des dossiers ouverts', 'Directeur du Budget', 'Exercice 2026, motif « Fin de gestion »',
        ['Clôture annuelle › Demander la clôture'],
        'Refus (HTTP 422) : la chaîne contient des dossiers ouverts.',
        function () {
            $id = DB::table('exercices')->where('annee', 2026)->value('id');

            return 'HTTP 422 : « '.message(attendre(api('POST', "cloture/{$id}/demander", ['motif' => 'Fin de gestion'], u('directeur.budget')), 422)).' »';
        });

    // ───────────────────────────── Recettes
    $exo = (int) DB::table('exercices')->where('annee', 2026)->value('id');
    $cat = (int) DB::table('revenue_categories')->where('code', 'VTE')->value('id');

    cas('REC-01', 'Recettes', 'Un initiateur ne saisit pas de prévision', 'Initiateur', 'clarisse.ndong',
        ['Tenter Nouvelle prévision'],
        'Refus (HTTP 403).',
        fn () => 'HTTP '.attendre(api('POST', 'recettes/previsions', ['exercice_id' => $exo, 'category_id' => $cat, 'label' => 'Interdit', 'montant' => 1000], u('clarisse.ndong')), 403)['status']);

    cas('REC-02', 'Recettes', 'Prévision : saisie, soumission, validation', 'Expert Budget, Directeur du Budget', 'Catégorie VTE ; « Jeu d’essai — ventes de publications » ; 5 000 000 FCFA',
        ['Expert : Nouvelle prévision puis Soumettre', 'Expert : tenter Valider', 'Directeur du Budget : Valider'],
        'Création HTTP 201 ; validation par l’expert refusée (403) ; validation par le Directeur acceptée.',
        function () use (&$ctx, $exo, $cat) {
            $p = attendre(api('POST', 'recettes/previsions', ['exercice_id' => $exo, 'category_id' => $cat, 'label' => 'Jeu d’essai — ventes de publications', 'montant' => 5000000, 'source_label' => 'Ventes'], u('blaise.essono')), 201)['json']['data'];
            $ctx['prv'] = $p['id'];
            attendre(api('POST', "recettes/previsions/{$p['id']}/soumettre", [], u('blaise.essono')), 200);
            attendre(api('POST', "recettes/previsions/{$p['id']}/valider", [], u('blaise.essono')), 403, 'validation par l’expert');
            attendre(api('POST', "recettes/previsions/{$p['id']}/valider", [], u('directeur.budget')), 200);

            return $p['code'].' : créée, soumise ; expert refusé (403) ; validée par le Directeur du Budget';
        });

    cas('REC-03', 'Recettes', 'Titre de recette issu de la prévision', 'Comptable', 'Débiteur « Librairie de démonstration » ; 5 000 000 ; échéance 2026-12-15',
        ['Recettes › Nouvelle recette', 'Choisir la prévision validée', 'Enregistrer', 'Soumettre'],
        'Titre créé (HTTP 201) et soumis.',
        function () use (&$ctx, $exo, $cat) {
            $t = attendre(api('POST', 'recettes/titres', ['exercice_id' => $exo, 'category_id' => $cat, 'forecast_id' => $ctx['prv'], 'debtor_type' => 'autre', 'debtor_label' => 'Librairie de démonstration', 'montant' => 5000000, 'echeance' => '2026-12-15', 'motif' => 'Jeu d’essai — vente de publications'], u('rita.obame')), 201)['json']['data'];
            $ctx['titre'] = $t['id'];
            $s = attendre(api('POST', "recettes/titres/{$t['id']}/soumettre", [], u('rita.obame')), 200)['json']['data'];

            return ($t['reference'] ?? 'Titre').' créé puis '.($s['statut'] ?? 'soumis');
        });

    cas('REC-04', 'Recettes', 'Séparation des fonctions : vérification et validation', 'Comptable, Expert Budget, Directeur du Budget', 'Titre REC-03 (auteur : comptable)',
        ['Auteur : tenter Vérifier', 'Expert Budget : Vérifier', 'Directeur du Budget : Valider', 'Auteur : tenter Prendre en charge', 'Chef comptable : Prendre en charge'],
        'L’auteur ne vérifie pas et ne prend pas en charge (refus) ; vérification, validation et prise en charge acceptées par des acteurs distincts.',
        function () use (&$ctx) {
            $r = api('POST', "recettes/titres/{$ctx['titre']}/verifier", [], u('rita.obame'));
            verifier(in_array($r['status'], [403, 422], true), 'Vérification par l’auteur : HTTP '.$r['status']);
            attendre(api('POST', "recettes/titres/{$ctx['titre']}/verifier", [], u('blaise.essono')), 200, 'vérification');
            attendre(api('POST', "recettes/titres/{$ctx['titre']}/valider", [], u('directeur.budget')), 200, 'validation');
            $p = api('POST', "recettes/titres/{$ctx['titre']}/prendre-en-charge", [], u('rita.obame'));
            verifier(in_array($p['status'], [403, 422], true), 'Prise en charge par l’auteur : HTTP '.$p['status']);
            $d = attendre(api('POST', "recettes/titres/{$ctx['titre']}/prendre-en-charge", [], u('marc.ndzie')), 200, 'prise en charge')['json']['data'];

            return 'vérification par l’auteur : HTTP '.$r['status'].' ; prise en charge par l’auteur : HTTP '.$p['status'].' ; vérifié (expert), validé (directeur), '.($d['statut'] ?? 'pris en charge').' (chef comptable)';
        });

    cas('REC-05', 'Recettes', 'Encaissement supérieur au solde du titre', 'Agent comptable', '6 000 000 affectés à un titre de 5 000 000',
        ['Encaissements › Nouvel encaissement', 'Affecter 6 000 000 au titre'],
        'Refus (HTTP 422).',
        fn () => 'HTTP 422 : « '.message(attendre(api('POST', 'recettes/encaissements', ['recu_le' => '2026-10-05', 'montant' => 6000000, 'mode' => 'virement', 'allocations' => [['order_id' => $ctx['titre'], 'montant' => 6000000]]], u('paul.nguema')), 422)).' »');

    cas('REC-06', 'Recettes', 'Encaissement partiel puis solde', 'Agent comptable', '2 000 000 puis 3 000 000 (VIR-REC-JEU-RCT)',
        ['Nouvel encaissement de 2 000 000', 'Nouvel encaissement de 3 000 000'],
        '« partiellement_encaisse » puis « solde » ; solde à recouvrer 0.',
        function () use (&$ctx) {
            attendre(api('POST', 'recettes/encaissements', ['recu_le' => '2026-10-05', 'montant' => 2000000, 'mode' => 'virement', 'allocations' => [['order_id' => $ctx['titre'], 'montant' => 2000000]]], u('paul.nguema')), 201);
            $s1 = DB::table('revenue_orders')->where('id', $ctx['titre'])->value('statut');
            $r = attendre(api('POST', 'recettes/encaissements', ['recu_le' => '2026-10-05', 'montant' => 3000000, 'mode' => 'virement', 'reference_bancaire' => 'VIR-REC-JEU-RCT', 'banque' => 'BEAC', 'allocations' => [['order_id' => $ctx['titre'], 'montant' => 3000000]]], u('paul.nguema')), 201);
            $ctx['receipt'] = $r['json']['data']['id'] ?? DB::table('revenue_receipts')->max('id');
            $s2 = DB::table('revenue_orders')->where('id', $ctx['titre'])->value('statut');
            verifier($s1 === 'partiellement_encaisse' && $s2 === 'solde', "Statuts : {$s1} puis {$s2}");

            return "{$s1} → {$s2}";
        });

    cas('REC-07', 'Recettes', 'Rapprochement réservé à un acteur distinct', 'Agent comptable, Chef comptable', 'Encaissement REC-06',
        ['Agent comptable : tenter Rapprocher', 'Chef comptable : Rapprocher'],
        'Agent : refus (HTTP 403) ; Chef comptable : accepté.',
        function () use (&$ctx) {
            attendre(api('POST', "recettes/encaissements/{$ctx['receipt']}/rapprocher", ['statut' => 'rapproche'], u('paul.nguema')), 403, 'agent');
            attendre(api('POST', "recettes/encaissements/{$ctx['receipt']}/rapprocher", ['statut' => 'rapproche'], u('marc.ndzie')), 200, 'chef');

            return 'agent : HTTP 403 ; chef comptable : HTTP 200';
        });

    // ───────────────────────────── Suivi-évaluation
    cas('SE-01', 'Suivi-évaluation', 'Saisie et soumission d’une réalisation physique', 'Initiateur', 'Activité de la ligne 203232 ; période 2026-T4 ; 1 atelier réalisé sur 2 prévus ; preuve PDF',
        ['Saisie et validation › Réalisation physique', 'Joindre la preuve', 'Soumettre'],
        'Réalisation créée (HTTP 201) puis soumise.',
        function () use (&$ctx) {
            $act = App\Domains\PAP\Models\PapEnrichment::query()->whereHas('budgetLine', fn ($q) => $q->where('code', '203232'))->first();
            $per = App\Domains\Monitoring\Models\MonitoringPeriod::query()->where('code', '2026-T4')->first();
            prerequis($act !== null && $per !== null, 'Activité 203232 ou période 2026-T4 absente');
            $ctx['act'] = $act;
            $id = attendre(api('POST', 'suivi/realisations', ['pap_enrichment_id' => $act->id, 'monitoring_period_id' => $per->id, 'method' => 'quantitative', 'quantity' => 1, 'planned' => 2, 'comment' => 'Jeu d’essai — premier atelier du trimestre.'], u('clarisse.ndong')), 201)['json']['data']['id'];
            $ctx['real'] = $id;
            attendre(api('POST', 'suivi/preuves', ['type' => 'realisation', 'id' => $id, 'category' => 'livrable'], u('clarisse.ndong'), ['fichier' => pdf('liste-presence.pdf')]), 201, 'preuve');
            $s = attendre(api('POST', "suivi/realisations/{$id}/soumettre", [], u('clarisse.ndong')), 200)['json']['data'];

            return 'réalisation créée et '.($s['statut'] ?? $s['status'] ?? 'soumise');
        });

    cas('SE-02', 'Suivi-évaluation', 'L’auteur ne valide pas sa propre réalisation', 'Initiateur', 'clarisse.ndong',
        ['Tenter Valider sa réalisation'],
        'Refus (HTTP 403 ou 422).',
        function () use (&$ctx) {
            prerequis(isset($ctx['real']), 'SE-01 non réalisé');
            $r = api('POST', "suivi/realisations/{$ctx['real']}/valider", [], u('clarisse.ndong'));
            verifier(in_array($r['status'], [403, 422], true), 'HTTP '.$r['status']);

            return 'HTTP '.$r['status'].' : « '.message($r).' »';
        });

    cas('SE-03', 'Suivi-évaluation', 'Validation par un autre acteur', 'Directeur (responsable)', 'jp.okombi, puis Directeur du Budget si une seconde validation est requise',
        ['Valider la réalisation'],
        'Validation acceptée ; statut final « valide ».',
        function () use (&$ctx) {
            prerequis(isset($ctx['real']), 'SE-01 non réalisé');
            $statuts = [];
            foreach ([u('jp.okombi'), u('directeur.budget')] as $v) {
                $cur = DB::table('physical_achievements')->where('id', $ctx['real'])->value('status');
                if (! in_array($cur, ['soumis', 'valide_responsable'], true)) {
                    break;
                }
                attendre(api('POST', "suivi/realisations/{$ctx['real']}/valider", [], $v), 200, $v->email);
                $statuts[] = DB::table('physical_achievements')->where('id', $ctx['real'])->value('status');
            }
            verifier(end($statuts) === 'valide', 'Statut final : '.implode(' → ', $statuts));

            return implode(' → ', $statuts);
        });

    // ───────────────────────────── Gouvernance
    cas('GOV-01', 'Gouvernance', 'Vérification d’un code inconnu', 'Directeur du Budget', 'Code « CODE-INEXISTANT-000 »',
        ['Vérifier un document', 'Saisir le code', 'Vérifier'],
        'Document non reconnu (HTTP 404).',
        fn () => 'HTTP '.attendre(api('GET', 'documents/verifier/CODE-INEXISTANT-000', [], u('directeur.budget')), 404)['status']);

    cas('GOV-02', 'Gouvernance', 'Vérification d’un acte officiel archivé', 'Directeur du Budget', 'Code du dernier acte archivé avant la campagne',
        ['Vérifier un document', 'Saisir le code imprimé en pied de page', 'Vérifier'],
        'Document reconnu (HTTP 200) avec sa référence et son type.',
        function () use ($codeArchive) {
            $code = $codeArchive;
            prerequis($code !== null, 'Aucun document archivé');
            $fake = Storage::disk('local');
            Storage::forgetDisk('local');
            try {
                $r = attendre(api('GET', 'documents/verifier/'.$code, [], u('directeur.budget')), 200);
            } finally {
                Storage::set('local', $fake);
            }
            verifier(($r['json']['integre'] ?? false) === true, 'Intégrité non confirmée');
            $doc = $r['json']['document'] ?? [];
            verifier(($r['json']['authentique'] ?? false) === true, 'Document non authentifié');

            return 'HTTP 200 ; authentique ; '.trim(($doc['type'] ?? $doc['kind'] ?? '').' '.($doc['reference'] ?? '')).' ; intègre = '.json_encode($r['json']['integre'] ?? null).' ; version courante = '.json_encode($r['json']['version_courante'] ?? null);
        });

    cas('GOV-03', 'Gouvernance', 'Contrôle préalable d’un fichier d’import', 'Directeur du Budget', "CSV : « code,montant » ; {$LIGNE},999 ; INCONNU-999,1000",
        ['Préparation des imports', 'Coller le contenu CSV', 'Contrôler'],
        'Ligne officielle bloquée (non modifiée) ; ligne inconnue en attente (non créée).',
        function () use ($LIGNE) {
            $r = attendre(api('POST', 'imports/preparation', ['fichier' => 'jeu-essai.csv', 'contenu' => "code,montant\n{$LIGNE},999\nINCONNU-999,1000"], u('directeur.budget')), 201);
            $l = $r['json']['data']['lignes'] ?? [];
            verifier(($l[0]['verdict'] ?? null) === 'bloque' && ($l[1]['verdict'] ?? null) === 'en_attente', 'Verdicts : '.json_encode(array_column($l, 'verdict')));

            return "{$LIGNE} : bloque ; INCONNU-999 : en_attente";
        });

    // ───────────────────────────── Administration
    $unite = (int) DB::table('organization_units')->where('sigle', 'DATI-DENER')->value('id');

    cas('ADM-01', 'Administration', 'Création de compte avec un mot de passe trop court', 'Administrateur des habilitations', 'Mot de passe « abc »',
        ['Utilisateurs › Nouveau compte', 'Saisir un mot de passe de 3 caractères'],
        'Refus (HTTP 422) sur le mot de passe.',
        fn () => 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', 'admin/utilisateurs', ['nom' => 'ESSAI', 'prenom' => 'Court', 'email' => 'essai.court@ceeac.int', 'organization_unit_id' => $unite, 'fonction' => 'Gestionnaire', 'role' => 'initiateur', 'initiales' => 'EC', 'password' => 'abc'], u('amina.oko')), 422)));

    cas('ADM-02', 'Administration', 'Création d’un compte valide', 'Administrateur des habilitations', 'ESSAI Brice ; essai.brice@ceeac.int ; DATI-DENER ; initiateur',
        ['Nouveau compte', 'Renseigner identité, rattachement, rôle, mot de passe', 'Enregistrer'],
        'Compte créé (HTTP 201), statut actif.',
        function () use ($unite) {
            $r = attendre(api('POST', 'admin/utilisateurs', ['nom' => 'ESSAI', 'prenom' => 'Brice', 'email' => 'essai.brice@ceeac.int', 'organization_unit_id' => $unite, 'fonction' => 'Gestionnaire', 'role' => 'initiateur', 'initiales' => 'EB', 'password' => 'Motdepasse-2026'], u('amina.oko')), 201);

            return 'HTTP 201 ; '.json_encode($r['json']['data']['email'] ?? 'compte créé');
        });

    cas('ADM-03', 'Administration', 'Un non-administrateur ne crée pas de compte', 'Directeur du Budget', 'directeur.budget',
        ['Tenter Nouveau compte'],
        'Refus (HTTP 403).',
        fn () => 'HTTP '.attendre(api('POST', 'admin/utilisateurs', ['nom' => 'X', 'prenom' => 'Y', 'email' => 'x.y@ceeac.int', 'organization_unit_id' => $unite, 'fonction' => 'F', 'role' => 'initiateur', 'initiales' => 'XY', 'password' => 'Motdepasse-2026'], u('directeur.budget')), 403)['status']);

    cas('ADM-04', 'Administration', 'Habilitation incompatible sans dérogation', 'Administrateur des habilitations', 'Rôle « controleur_financier » pour clarisse.ndong (initiateur)',
        ['Habilitations › Nouvelle habilitation', 'Agent clarisse.ndong, rôle Contrôleur financier', 'Soumettre'],
        'Refus (HTTP 422) : conflit de séparation des fonctions.',
        fn () => 'HTTP 422 : « '.message(attendre(api('POST', 'admin/habilitations', ['user_id' => u('clarisse.ndong')->id, 'role' => 'controleur_financier', 'starts_on' => '2026-10-05', 'origine' => 'nomination'], u('amina.oko')), 422)).' »');

    cas('ADM-05', 'Administration', 'Habilitation avec dérogation motivée', 'Administrateur des habilitations, Ordonnateur', 'Même demande avec dérogation et motif',
        ['Cocher Demander une dérogation', 'Saisir le motif', 'Soumettre pour validation'],
        'Habilitation créée (HTTP 201) en attente de validation par l’ordonnateur.',
        function () {
            $r = attendre(api('POST', 'admin/habilitations', ['user_id' => u('clarisse.ndong')->id, 'role' => 'controleur_financier', 'starts_on' => '2026-10-05', 'origine' => 'nomination', 'derogation' => true, 'motif' => 'Jeu d’essai — dérogation sur périmètre distinct.'], u('amina.oko')), 201);

            return 'HTTP 201 ; statut '.json_encode($r['json']['data']['statut'] ?? $r['json']['statut'] ?? null);
        });

    // ═════════════════════════════ Extension : compléments de couverture

    // ───────────────────────────── Authentification (compléments)
    cas('AUT-06', 'Authentification', 'Double authentification exigée pour un compte protégé', 'Agent avec MFA', 'Compte « jeu.mfa@ceeac.int » créé avec la double authentification obligatoire',
        ['Saisir adresse et mot de passe', 'Saisir le code à usage unique', 'Recommencer sans code'],
        'Sans code : demande de code (HTTP 202). Avec code valide : connexion (HTTP 200). Code absent après enrôlement : refus (HTTP 422).',
        function () {
            User::query()->create(['name' => 'Agent MFA', 'email' => 'jeu.mfa@ceeac.int', 'password' => 'password', 'role' => 'expert_budget', 'account_status' => 'actif', 'mfa_required' => true]);
            $p = attendre(api('POST', 'auth/login', ['email' => 'jeu.mfa@ceeac.int', 'password' => 'password']), 202, 'demande de code');
            $secret = $p['json']['secret'] ?? null;
            verifier(is_string($secret), 'Secret d’enrôlement absent');
            attendre(api('POST', 'auth/login', ['email' => 'jeu.mfa@ceeac.int', 'password' => 'password', 'secret' => $secret, 'code' => App\Shared\Auth\Totp::code($secret, intdiv(time(), 30))]), 200, 'code valide');
            $r = attendre(api('POST', 'auth/login', ['email' => 'jeu.mfa@ceeac.int', 'password' => 'password']), 422, 'sans code');

            return 'HTTP 202 (code demandé) → HTTP 200 avec code → HTTP 422 sans code ('.erreurs($r).')';
        });

    cas('AUT-07', 'Authentification', 'Blocage après échecs répétés', 'Agent', 'Compte « jeu.blocage@ceeac.int » ; mots de passe erronés successifs',
        ['Saisir un mauvais mot de passe jusqu’au seuil', 'Saisir ensuite le bon mot de passe'],
        'Après le nombre d’échecs fixé par la politique de sécurité, la connexion est bloquée (HTTP 429) même avec le bon mot de passe.',
        function () {
            User::query()->create(['name' => 'Agent blocage', 'email' => 'jeu.blocage@ceeac.int', 'password' => 'password', 'role' => 'initiateur', 'account_status' => 'actif']);
            $max = app(App\Domains\Administration\Services\SecurityPolicy::class)->maxFailures();
            for ($i = 0; $i < $max; $i++) {
                api('POST', 'auth/login', ['email' => 'jeu.blocage@ceeac.int', 'password' => 'faux-'.$i]);
            }
            $r = api('POST', 'auth/login', ['email' => 'jeu.blocage@ceeac.int', 'password' => 'password']);
            RateLimiter::clear(Str::lower('jeu.blocage@ceeac.int').'|127.0.0.1');
            verifier($r['status'] === 429, 'HTTP '.$r['status']);

            return "{$max} échecs puis HTTP 429 : « ".message($r).' »';
        });

    cas('AUT-08', 'Authentification', 'Déconnexion', 'Initiateur', 'clarisse.ndong',
        ['Menu à votre nom › Se déconnecter'],
        'Déconnexion acceptée (HTTP 200 ou 204).',
        function () {
            $r = api('POST', 'auth/logout', [], u('clarisse.ndong'));
            verifier(in_array($r['status'], [200, 204], true), 'HTTP '.$r['status']);

            return 'HTTP '.$r['status'];
        });

    // ───────────────────────────── Expression de besoin (compléments)
    cas('EB-14', 'Expression de besoin', 'Aperçu PDF d’un brouillon', 'Initiateur', 'Brouillon « Jeu d’essai — aperçu »',
        ['Ouvrir le brouillon', 'Aperçu PDF'],
        'Un PDF est produit (marqué brouillon) sans être archivé comme acte officiel.',
        function () {
            $id = attendre(api('POST', 'expressions-besoin', ['budget_line_id' => ligne('203232')['id']], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('PATCH', "expressions-besoin/{$id}", ['objet' => 'Jeu d’essai — aperçu'], u('clarisse.ndong')), 200);
            $avant = DB::table('generated_documents')->count();
            $r = attendre(api('GET', "expressions-besoin/{$id}/apercu", [], u('clarisse.ndong')), 200);
            verifier(str_starts_with($r['body'], '%PDF'), 'Le contenu n’est pas un PDF');
            verifier(DB::table('generated_documents')->count() === $avant, 'Un acte a été archivé');

            return 'PDF de '.number_format(strlen($r['body']) / 1024, 0, ',', ' ').' Ko ; aucun acte archivé';
        });

    cas('EB-15', 'Expression de besoin', 'Copie modifiable d’un dossier rejeté', 'Initiateur', 'Dossier rejeté en EB-13',
        ['Menu ⋮ › Créer une copie modifiable'],
        'Un nouveau brouillon est créé avec une nouvelle référence ; le dossier rejeté reste inchangé.',
        function () {
            $rejete = App\Domains\Needs\Models\ExpressionBesoin::query()->where('objet', 'Jeu d’essai — mission non programmée')->latest('id')->first();
            prerequis($rejete !== null, 'EB-13 non réalisé');
            $r = attendre(api('POST', "expressions-besoin/{$rejete->id}/dupliquer", [], u('clarisse.ndong')), 201);
            $copie = $r['json']['data'];
            verifier($copie['statut'] === 'brouillon' && $copie['reference'] !== $rejete->reference, 'Copie : '.json_encode([$copie['statut'], $copie['reference']]));
            verifier($rejete->fresh()->status->value === 'rejetee', 'Le dossier source a changé');

            return 'copie '.$copie['reference'].' en brouillon ; source '.$rejete->reference.' toujours rejetee';
        });

    cas('EB-16', 'Expression de besoin', 'Annulation d’un brouillon par son initiateur', 'Initiateur', 'Brouillon « Jeu d’essai — besoin abandonné »',
        ['Menu ⋮ › Annuler', 'Annuler sans motif', 'Annuler avec motif'],
        'Sans motif : refus (HTTP 422). Avec motif : statut « annulee ».',
        function () {
            $id = attendre(api('POST', 'expressions-besoin', ['budget_line_id' => ligne('203232')['id']], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('POST', "expressions-besoin/{$id}/annuler", [], u('clarisse.ndong')), 422, 'sans motif');
            $d = attendre(api('POST', "expressions-besoin/{$id}/annuler", ['motif' => 'Besoin couvert par un autre dossier'], u('clarisse.ndong')), 200)['json']['data'];

            return 'sans motif : HTTP 422 ; avec motif : statut '.$d['statut'];
        });

    // ───────────────────────────── Engagement (compléments)
    cas('ENG-10', 'Engagement', 'Retour pour correction puis nouvelle transmission', 'Chef de service Budget, Expert Budget', 'Dossier « Jeu d’essai — retour engagement », 300 000 FCFA ; motif « Bénéficiaire à préciser »',
        ['Expert : renseigner le bénéficiaire et Transmettre', 'Chef de service : Retourner avec motif', 'Expert : Transmettre de nouveau'],
        'Statut « retourne » ; après correction, le dossier revient à l’étape « chef_budget ».',
        function () {
            $ids = dossier('Jeu d’essai — retour engagement', 300000, 'eng');
            attendre(api('PATCH', "engagements/{$ids['eng']}", ['tiers_id' => App\Domains\Suppliers\Models\Tiers::query()->where('nif', 'JEU-NIF-001')->value('id')], u('blaise.essono')), 200, 'bénéficiaire');
            attendre(api('POST', "engagements/{$ids['eng']}/transmettre", [], u('blaise.essono')), 200);
            $r = attendre(api('POST', "engagements/{$ids['eng']}/retourner", ['motif' => 'Bénéficiaire à préciser', 'observations' => 'Joindre le RCCM.'], u('chef.budget')), 200)['json']['data'];
            verifier($r['statut'] === 'retourne', 'Statut : '.$r['statut']);
            $d = attendre(api('POST', "engagements/{$ids['eng']}/transmettre", [], u('blaise.essono')), 200)['json']['data'];

            return 'retourne → étape '.$d['etape'];
        });

    cas('ENG-11', 'Engagement', 'Annulation d’un engagement avant visa', 'Directeur du Budget', 'Dossier « Jeu d’essai — engagement annulé », 400 000 FCFA',
        ['Menu ⋮ › Annuler', 'Sans motif puis avec motif'],
        'Sans motif : refus (HTTP 422). Avec motif : statut « annule » ; le disponible de la ligne est rétabli.',
        function () {
            $ids = dossier('Jeu d’essai — engagement annulé', 400000, 'eng');
            $avant = solde('203232', 'disponible');
            attendre(api('POST', "engagements/{$ids['eng']}/annuler", [], u('directeur.budget')), 422, 'sans motif');
            $d = attendre(api('POST', "engagements/{$ids['eng']}/annuler", ['motif' => 'Besoin abandonné'], u('directeur.budget')), 200)['json']['data'];
            $delta = solde('203232', 'disponible') - $avant;
            verifier($d['statut'] === 'annule', 'Statut : '.$d['statut']);
            verifier($delta === 400000, 'Variation du disponible : '.$delta);

            return 'sans motif : HTTP 422 ; statut annule ; disponible +400 000';
        });

    cas('ENG-12', 'Engagement', 'Visa refusé à un autre profil que le Contrôleur financier', 'Directeur du Budget', 'Engagement à l’étape « controleur_financier »',
        ['Tenter Viser'],
        'Action refusée (HTTP 403).',
        function () {
            $ids = dossier('Jeu d’essai — visa réservé', 250000, 'eng');
            attendre(api('PATCH', "engagements/{$ids['eng']}", ['tiers_id' => App\Domains\Suppliers\Models\Tiers::query()->where('nif', 'JEU-NIF-001')->value('id')], u('blaise.essono')), 200, 'bénéficiaire');
            foreach ([u('blaise.essono'), u('chef.budget'), u('directeur.budget')] as $a) {
                attendre(api('POST', "engagements/{$ids['eng']}/transmettre", [], $a), 200);
            }

            return 'HTTP '.attendre(api('POST', "engagements/{$ids['eng']}/viser", [], u('directeur.budget')), 403)['status'];
        });

    // ───────────────────────────── Liquidation (compléments)
    cas('LIQ-09', 'Liquidation', 'Retour de la liquidation par le Contrôleur financier', 'Contrôleur financier', 'Dossier « Jeu d’essai — retour liquidation », 350 000 FCFA ; motif « Facture illisible »',
        ['Initiateur : certifier, saisir la facture, soumettre', 'CF : Retourner avec motif'],
        'La liquidation revient à l’étape de l’initiateur.',
        function () {
            $ids = dossier('Jeu d’essai — retour liquidation', 350000, 'liq');
            attendre(api('POST', "liquidations/{$ids['liq']}/certifier", ['montant_accepte' => 350000], u('clarisse.ndong')), 200);
            attendre(api('POST', "liquidations/{$ids['liq']}/facture", ['numero' => 'FAC-RET-'.$ids['liq'], 'date' => '2026-10-03', 'montant_ht' => 350000, 'taxes' => 0], u('clarisse.ndong')), 200);
            attendre(api('POST', "liquidations/{$ids['liq']}/soumettre", [], u('clarisse.ndong')), 200);
            attendre(api('POST', "liquidations/{$ids['liq']}/retourner", [], u('controleur.financier')), 422, 'sans motif');
            $d = attendre(api('POST', "liquidations/{$ids['liq']}/retourner", ['motif' => 'Facture illisible'], u('controleur.financier')), 200)['json']['data'];

            return 'sans motif : HTTP 422 ; statut '.$d['statut'].', étape '.($d['etape'] ?? '—');
        });

    cas('LIQ-10', 'Liquidation', 'Rectification après visa (avoir)', 'Contrôleur financier', 'Dossier « Jeu d’essai — avoir », 500 000 FCFA ; avoir de 50 000',
        ['Menu ⋮ › Rectifier', 'Type avoir, montant, motif'],
        'La rectification est enregistrée sans réécrire le visa.',
        function () {
            $ids = dossier('Jeu d’essai — avoir', 500000, 'ord');
            $visa = DB::table('liquidations')->where('id', $ids['liq'])->value('visa_reference');
            $r = api('POST', "liquidations/{$ids['liq']}/rectifier", ['kind' => 'avoir', 'montant' => 50000, 'motif' => 'Remise commerciale'], u('controleur.financier'));
            if ($r['status'] === 403) {
                $r = api('POST', "liquidations/{$ids['liq']}/rectifier", ['kind' => 'avoir', 'montant' => 50000, 'motif' => 'Remise commerciale'], u('clarisse.ndong'));
            }
            verifier($r['status'] === 200, 'HTTP '.$r['status'].' : '.message($r));
            verifier(DB::table('liquidations')->where('id', $ids['liq'])->value('visa_reference') === $visa, 'Visa modifié');

            return 'HTTP 200 ; visa '.$visa.' inchangé';
        });

    // ───────────────────────────── Ordonnancement (compléments)
    cas('ORD-05', 'Ordonnancement', 'Retour de l’ordre vers la liquidation', 'Secrétaire général', 'Dossier « Jeu d’essai — retour ordre », 450 000 FCFA ; motif « Imputation à vérifier »',
        ['Ouvrir l’ordre', 'Retourner sans motif, puis avec motif'],
        'Sans motif : refus (HTTP 422). Avec motif : l’ordre est retourné.',
        function () {
            $ids = dossier('Jeu d’essai — retour ordre', 450000, 'ord');
            attendre(api('POST', "ordonnancements/{$ids['ord']}/retourner", [], u('aline.moussavou')), 422, 'sans motif');
            $d = attendre(api('POST', "ordonnancements/{$ids['ord']}/retourner", ['motif' => 'Imputation à vérifier'], u('aline.moussavou')), 200)['json']['data'];

            return 'sans motif : HTTP 422 ; statut '.$d['statut'];
        });

    cas('ORD-06', 'Ordonnancement', 'Transmission en erreur puis reprise sans doublon', 'Secrétaire général', 'Dossier « Jeu d’essai — reprise transmission » ; panne de transmission simulée sur l’ordre',
        ['Signer l’ordre (la transmission échoue)', 'Relancer maintenant', 'Relancer une seconde fois'],
        '« transmission_erreur » après signature ; « transforme_paiement » après reprise ; seconde reprise refusée ; un seul paiement.',
        function () {
            $ids = dossier('Jeu d’essai — reprise transmission', 300000, 'ord');
            App\Domains\Commitments\Models\Ordonnancement::query()->whereKey($ids['ord'])->update(['fail_next_transmission' => true]);
            $a = attendre(api('POST', "ordonnancements/{$ids['ord']}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'], u('aline.moussavou')), 200)['json']['data'];
            verifier($a['statut'] === 'transmission_erreur', 'Après signature : '.$a['statut']);
            $b = attendre(api('POST', "ordonnancements/{$ids['ord']}/reprendre", [], u('aline.moussavou')), 200)['json']['data'];
            $c = api('POST', "ordonnancements/{$ids['ord']}/reprendre", [], u('aline.moussavou'));
            $n = App\Domains\Commitments\Models\Paiement::query()->where('ordonnancement_id', $ids['ord'])->count();
            verifier($b['statut'] === 'transforme_paiement' && $c['status'] >= 400 && $n === 1, "reprise {$b['statut']}, 2e HTTP {$c['status']}, paiements {$n}");

            return "transmission_erreur → transforme_paiement ; seconde reprise HTTP {$c['status']} ; {$n} paiement";
        });

    cas('ORD-07', 'Ordonnancement', 'Enregistrement d’une délégation d’ordonnancement', 'Ordonnateur (Président)', 'Seuil 6 000 000 ; du 2026-10-05 au 2026-12-31 ; décision « DEC-JEU-2026-01 »',
        ['Délégations et seuil › Nouvelle délégation'],
        'Délégation créée (HTTP 201) ; type de dépense par défaut « Toutes natures ».',
        function () {
            attendre(api('POST', 'ordonnancements/delegations', ['seuil_max' => 6000000, 'debut' => '2026-10-05', 'fin' => '2026-12-31', 'document' => 'DEC-JEU-2026-01'], u('ordonnateur')), 201);
            $t = DB::table('ord_delegations')->where('document', 'DEC-JEU-2026-01')->value('type_depense');

            return 'HTTP 201 ; type de dépense « '.$t.' »';
        });

    cas('ORD-08', 'Ordonnancement', 'Déclaration d’une suppléance', 'Ordonnateur (Président)', 'Titulaire « Président de la Commission », suppléant « Secrétaire Général », du 2026-11-01 au 2026-11-15',
        ['Délégations et seuil › Déclarer une absence'],
        'Suppléance enregistrée (HTTP 201) ; une date de fin antérieure au début est refusée (HTTP 422).',
        function () {
            attendre(api('POST', 'ordonnancements/suppleances', ['titulaire' => 'Président de la Commission', 'suppleant' => 'Secrétaire Général', 'debut' => '2026-11-15', 'fin' => '2026-11-01', 'fondement' => 'Mission'], u('ordonnateur')), 422, 'dates inversées');
            attendre(api('POST', 'ordonnancements/suppleances', ['titulaire' => 'Président de la Commission', 'suppleant' => 'Secrétaire Général', 'debut' => '2026-11-01', 'fin' => '2026-11-15', 'fondement' => 'Mission officielle'], u('ordonnateur')), 201);

            return 'dates inversées : HTTP 422 ; suppléance : HTTP 201';
        });

    // ───────────────────────────── Paiement (compléments)
    cas('PAY-10', 'Paiement', 'Compte bancaire non validé refusé', 'Comptable', 'Nouveau compte « GA-JEU-ATTENTE » ajouté au bénéficiaire, non validé',
        ['Tiers : ajouter le compte', 'Paiement : choisir ce compte', 'Enregistrer'],
        'Préparation refusée (HTTP 422, champ compte bancaire).',
        function () use (&$ctx) {
            $ids = dossier('Jeu d’essai — compte non validé', 320000, 'pay');
            $ctx['pay_d'] = $ids['pay'];
            $tiers = App\Domains\Suppliers\Models\Tiers::query()->where('nif', 'JEU-NIF-001')->firstOrFail();
            $r = attendre(api('POST', "tiers/{$tiers->id}/comptes", ['banque' => 'UBA', 'numero' => 'GA-JEU-ATTENTE', 'titulaire' => $tiers->raison_sociale], u('rita.obame')), 201)['json']['data'];
            $compte = collect($r['comptes'] ?? [])->firstWhere('numero', 'GAJEUATTENTE') ?? collect($r['comptes'] ?? [])->sortByDesc('id')->first();
            $p = attendre(api('POST', "paiements/{$ids['pay']}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $compte['id']], u('rita.obame')), 422);

            return 'HTTP 422 ('.erreurs($p).') : « '.message($p).' »';
        });

    cas('PAY-11', 'Paiement', 'Suspension puis levée de la suspension', 'Agent comptable', 'Paiement autorisé « Jeu d’essai — suspension » ; motif « Vérification du RIB »',
        ['Menu ⋮ › Suspendre', 'Tenter l’exécution', 'Lever la suspension'],
        '« suspendu » ; l’exécution est refusée pendant la suspension ; retour à « autorise » après levée.',
        function () {
            $ids = dossier('Jeu d’essai — suspension', 330000, 'autorise');
            $a = attendre(api('POST', "paiements/{$ids['pay']}/suspendre", ['motif' => 'Vérification du RIB'], u('paul.nguema')), 200)['json']['data'];
            $x = api('POST', "paiements/{$ids['pay']}/executer", ['montant' => 330000, 'reference' => 'VIR-SUSP', 'date_valeur' => '2026-10-05'], u('rita.obame'), ['preuve' => pdf('avis.pdf')]);
            $b = attendre(api('POST', "paiements/{$ids['pay']}/lever-suspension", ['motif' => 'RIB confirmé'], u('paul.nguema')), 200)['json']['data'];
            verifier($a['statut'] === 'suspendu' && $x['status'] >= 400 && $b['statut'] === 'autorise', "{$a['statut']}, exécution HTTP {$x['status']}, {$b['statut']}");

            return "suspendu ; exécution refusée (HTTP {$x['status']}) ; levée → autorise";
        });

    cas('PAY-12', 'Paiement', 'Lot de virements', 'Comptable', 'Deux paiements autorisés (« Jeu d’essai — lot A » et « lot B », 210 000 et 220 000) ; un lot d’un seul paiement',
        ['Lots › cocher un seul virement › Créer le lot', 'Cocher les deux virements › Créer le lot', 'Exécuter le lot avec l’avis bancaire'],
        'Lot d’un seul paiement refusé (HTTP 422) ; lot de deux créé (LOT-PAY-…) ; après exécution, les deux paiements sont « a_rapprocher ».',
        function () use (&$ctx) {
            $a = dossier('Jeu d’essai — lot A', 210000, 'autorise');
            $b = dossier('Jeu d’essai — lot B', 220000, 'autorise');
            $ctx['pay_lot'] = $a['pay'];
            attendre(api('POST', 'paiements/lots', ['libelle' => 'Lot isolé', 'paiements' => [$a['pay']]], u('rita.obame')), 422, 'un seul paiement');
            $lot = attendre(api('POST', 'paiements/lots', ['libelle' => 'Jeu d’essai — virements du jour', 'paiements' => [$a['pay'], $b['pay']]], u('rita.obame')), 201)['json'];
            $lotId = $lot['id'] ?? DB::table('pay_lots')->where('reference', $lot['reference'])->value('id');
            attendre(api('POST', "paiements/lots/{$lotId}/executer", ['reference' => 'LOT-VIR-JEU', 'date_valeur' => '2026-10-05'], u('rita.obame'), ['preuve' => pdf('avis-lot.pdf')]), 200);
            $s = [statut('paiements', $a['pay']), statut('paiements', $b['pay'])];
            verifier($s === ['a_rapprocher', 'a_rapprocher'], 'Statuts : '.implode(', ', $s));

            return 'lot d’un paiement : HTTP 422 ; '.$lot['reference'].' exécuté ; paiements a_rapprocher';
        });

    cas('PAY-13', 'Paiement', 'Rejet bancaire puis réémission', 'Comptable', 'Paiement « lot A » exécuté ; motif « Compte clôturé »',
        ['Menu ⋮ › Rejet bancaire', 'Tenter une nouvelle exécution directe', 'Réémettre'],
        '« rejete_bancaire », montant payé ramené à 0 ; exécution directe refusée ; réémission → « en_preparation ».',
        function () use (&$ctx) {
            prerequis(isset($ctx['pay_lot']), 'PAY-12 non réalisé');
            $id = $ctx['pay_lot'];
            $a = attendre(api('POST', "paiements/{$id}/rejet-bancaire", ['motif' => 'Compte clôturé'], u('rita.obame')), 200)['json']['data'];
            $x = api('POST', "paiements/{$id}/executer", ['montant' => 210000, 'reference' => 'VIR-DIRECT', 'date_valeur' => '2026-10-05'], u('rita.obame'), ['preuve' => pdf('avis.pdf')]);
            $b = attendre(api('POST', "paiements/{$id}/reemettre", ['motif' => 'Nouveau RIB fourni'], u('rita.obame')), 200)['json']['data'];
            verifier($a['statut'] === 'rejete_bancaire' && (int) $a['montant_paye'] === 0 && $x['status'] === 422 && $b['statut'] === 'en_preparation', json_encode([$a['statut'], $a['montant_paye'], $x['status'], $b['statut']]));

            return "rejete_bancaire (payé 0) ; exécution directe HTTP 422 ; réémission → en_preparation";
        });

    // ───────────────────────────── Tiers et marchés
    cas('TIE-01', 'Tiers et marchés', 'Création d’un tiers et contrôle des doublons', 'Comptable', '« Jeu d’essai Fournitures SARL », NIF « jeu-nif-900 » ; puis même raison sociale ; puis même NIF',
        ['Tiers et comptes › Nouveau tiers', 'Recommencer avec la même raison sociale', 'Recommencer avec le même NIF'],
        'Création (HTTP 201), NIF normalisé en majuscules, statut actif ; doublons refusés (HTTP 422).',
        function () use (&$ctx) {
            $d = attendre(api('POST', 'tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Jeu d’essai Fournitures SARL', 'nif' => 'jeu-nif-900'], u('rita.obame')), 201)['json']['data'];
            $ctx['tiers'] = $d['id'];
            $a = attendre(api('POST', 'tiers', ['type' => 'fournisseur', 'raison_sociale' => 'JEU D’ESSAI FOURNITURES'], u('rita.obame')), 422, 'raison sociale');
            $b = attendre(api('POST', 'tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Autre société', 'nif' => 'JEU-NIF-900'], u('rita.obame')), 422, 'NIF');

            return 'HTTP 201, NIF '.$d['nif'].', statut '.$d['statut'].' ; doublons : '.erreurs($a).' / '.erreurs($b).' (HTTP 422)';
        });

    cas('TIE-02', 'Tiers et marchés', 'Un initiateur ne crée pas de tiers', 'Initiateur', 'clarisse.ndong',
        ['Tenter Nouveau tiers'],
        'Refus (HTTP 403 ou 422).',
        function () {
            $r = api('POST', 'tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Hors rôle'], u('clarisse.ndong'));
            verifier(in_array($r['status'], [403, 422], true), 'HTTP '.$r['status']);

            return 'HTTP '.$r['status'].' : « '.message($r).' »';
        });

    cas('TIE-03', 'Tiers et marchés', 'Validation d’un compte bancaire par un second acteur', 'Comptable, Chef comptable', 'Compte BGFIBANK « GA-JEU-900 » du tiers TIE-01',
        ['Comptable : ajouter le compte', 'Comptable : tenter de le valider', 'Chef comptable : Valider'],
        'Compte « en_attente » ; validation par son auteur refusée (HTTP 422) ; validation par le chef comptable acceptée (« valide »).',
        function () use (&$ctx) {
            prerequis(isset($ctx['tiers']), 'TIE-01 non réalisé');
            $r = attendre(api('POST', "tiers/{$ctx['tiers']}/comptes", ['banque' => 'BGFIBANK', 'numero' => 'GA-JEU-900', 'titulaire' => 'Jeu d’essai Fournitures SARL'], u('rita.obame')), 201)['json']['data'];
            $id = $r['comptes'][0]['id'];
            attendre(api('POST', "tiers/comptes/{$id}/valider", [], u('rita.obame')), 422, 'auteur');
            attendre(api('POST', "tiers/comptes/{$id}/valider", [], u('marc.ndzie')), 200, 'chef');
            $s = DB::table('tiers_bank_accounts')->where('id', $id)->value('status');
            verifier($s === 'valide', 'Statut : '.$s);

            return 'en_attente → auteur refusé (HTTP 422) → valide';
        });

    cas('TIE-04', 'Tiers et marchés', 'Suspension d’un tiers', 'Agent comptable', 'Tiers TIE-01 ; motif « Contentieux »',
        ['Fiche tiers › Statut suspendu'],
        'Statut « suspendu » enregistré avec son motif.',
        function () use (&$ctx) {
            attendre(api('POST', "tiers/{$ctx['tiers']}/statut", ['statut' => 'suspendu', 'motif' => 'Contentieux'], u('paul.nguema')), 200);

            return 'statut '.DB::table('tiers')->where('id', $ctx['tiers'])->value('status');
        });

    cas('MAR-01', 'Tiers et marchés', 'Création d’un marché et rattachement à l’engagement', 'Expert Budget', '« Jeu d’essai — fourniture de serveurs », 12 000 000, consultation ; engagement du scénario principal',
        ['Marchés et contrats › Nouveau marché', 'Rattacher à l’engagement'],
        'Marché créé (HTTP 201) puis rattaché ; statut « notifie ».',
        function () use (&$ctx) {
            prerequis(isset($ctx['eng_id']), 'Scénario principal non réalisé');
            $exo = DB::table('exercices')->where('annee', 2026)->value('id');
            $id = attendre(api('POST', 'marches', ['exercice_id' => $exo, 'objet' => 'Jeu d’essai — fourniture de serveurs', 'montant' => 12000000, 'procedure' => 'consultation'], u('blaise.essono')), 201)['json']['data']['id'];
            attendre(api('POST', "marches/{$id}/rattacher", ['engagement_id' => $ctx['eng_id']], u('blaise.essono')), 200);

            return 'marché rattaché ; statut '.DB::table('marches')->where('id', $id)->value('statut');
        });

    // ───────────────────────────── Recettes (compléments)
    cas('REC-08', 'Recettes', 'Prévision : modification, suppression et annulation selon le statut', 'Expert Budget, Directeur du Budget', 'Brouillon « Jeu d’essai — brouillon jetable » ; prévision « Jeu d’essai — documentation » 12 000 000 révisée à 9 000 000',
        ['Modifier une prévision en brouillon', 'Supprimer un brouillon', 'Soumettre, puis tenter de modifier et supprimer', 'Directeur : Annuler avec motif'],
        'Brouillon modifiable et supprimable ; après soumission, modification et suppression refusées (422) ; annulation par le Directeur acceptée (« annule »).',
        function () use ($exo, $cat) {
            $j = attendre(api('POST', 'recettes/previsions', ['exercice_id' => $exo, 'category_id' => $cat, 'label' => 'Jeu d’essai — brouillon jetable', 'montant' => 1000], u('blaise.essono')), 201)['json']['data']['id'];
            attendre(api('DELETE', "recettes/previsions/{$j}", [], u('blaise.essono')), 200, 'suppression du brouillon');
            $p = attendre(api('POST', 'recettes/previsions', ['exercice_id' => $exo, 'category_id' => $cat, 'label' => 'Jeu d’essai — documentation', 'montant' => 12000000], u('blaise.essono')), 201)['json']['data']['id'];
            attendre(api('PATCH', "recettes/previsions/{$p}", ['label' => 'Jeu d’essai — documentation', 'montant' => 9000000], u('blaise.essono')), 200, 'modification');
            attendre(api('POST', "recettes/previsions/{$p}/soumettre", [], u('blaise.essono')), 200);
            attendre(api('PATCH', "recettes/previsions/{$p}", ['montant' => 1000], u('blaise.essono')), 422, 'modification après soumission');
            attendre(api('DELETE', "recettes/previsions/{$p}", [], u('blaise.essono')), 422, 'suppression après soumission');
            attendre(api('POST', "recettes/previsions/{$p}/annuler", ['motif' => 'Montant à revoir'], u('directeur.budget')), 200);

            return 'brouillon supprimé ; 12 M → 9 M ; après soumission : modification et suppression HTTP 422 ; statut '.DB::table('revenue_forecasts')->where('id', $p)->value('statut');
        });

    cas('REC-09', 'Recettes', 'Trop-perçu conservé comme avance', 'Agent comptable', 'Titre de 5 000 000 ; virement reçu de 6 500 000 ; trop-perçu 1 500 000 en avance',
        ['Nouvel encaissement de 6 500 000', 'Affecter 5 000 000 au titre', 'Traiter 1 500 000 en avance'],
        'Titre « solde » à 5 000 000 encaissés ; une avance de 1 500 000 est tracée.',
        function () use ($exo, $cat) {
            $t = titreRecouvrable($exo, $cat, 5000000, 'Partenaire trop-perçu');
            attendre(api('POST', 'recettes/encaissements', ['recu_le' => '2026-10-05', 'montant' => 6500000, 'mode' => 'virement', 'allocations' => [['order_id' => $t, 'montant' => 5000000]], 'trop_percu' => ['kind' => 'avance', 'montant' => 1500000, 'order_id' => $t]], u('paul.nguema')), 201);
            $o = DB::table('revenue_orders')->where('id', $t)->first(['statut', 'montant_encaisse']);
            $adj = DB::table('revenue_adjustments')->where('order_id', $t)->where('kind', 'avance')->value('montant');
            verifier($o->statut === 'solde' && (int) $o->montant_encaisse === 5000000 && (int) $adj === 1500000, json_encode([$o, $adj]));

            return 'titre solde (5 000 000 encaissés) ; avance de 1 500 000 tracée';
        });

    cas('REC-10', 'Recettes', 'Encaissement non identifié', 'Agent comptable', '750 000 reçus sans titre (VIR-REC-JEU-NI)',
        ['Nouvel encaissement sans titre'],
        'Encaissement enregistré (HTTP 201) au statut « non identifié ».',
        function () {
            $r = attendre(api('POST', 'recettes/encaissements', ['recu_le' => '2026-10-05', 'montant' => 750000, 'mode' => 'virement', 'reference_bancaire' => 'VIR-REC-JEU-NI', 'banque' => 'BEAC'], u('paul.nguema')), 201);
            $id = $r['json']['data']['id'] ?? DB::table('revenue_receipts')->where('reference_bancaire', 'VIR-REC-JEU-NI')->value('id');

            return 'HTTP 201 ; statut '.DB::table('revenue_receipts')->where('id', $id)->value('statut');
        });

    cas('REC-11', 'Recettes', 'Relance d’une créance', 'Comptable', 'Titre échu de 2 000 000 ; première relance par courriel à facturation@example.test',
        ['Ouvrir le titre', 'Relancer : type, canal, destinataire', 'Enregistrer la relance'],
        'Relance enregistrée (HTTP 201) et visible dans l’écran Relances.',
        function () use ($exo, $cat) {
            $t = titreRecouvrable($exo, $cat, 2000000, 'Débiteur relancé');
            attendre(api('POST', "recettes/titres/{$t}/relances", ['kind' => 'premiere', 'canal' => 'courriel', 'destinataire' => 'facturation@example.test', 'resultat' => 'Envoyée'], u('rita.obame')), 201);
            $l = attendre(api('GET', 'recettes/relances', [], u('rita.obame')), 200)['json']['data'] ?? [];

            return 'HTTP 201 ; '.count($l).' relance(s) listée(s)';
        });

    cas('REC-12', 'Recettes', 'Contributions des États membres et appels de fonds', 'Expert Budget', 'Gabon : contribution 2026 existante (non appelée) ; nouvelle saisie 2026 ; contribution 2027 de 8 000 000 (quote-part 12,50 %)',
        ['Nouvelle contribution 2026 pour le Gabon', 'Appeler la contribution 2026', 'Appeler une seconde fois', 'Nouvelle contribution 2027 puis Appeler'],
        'Doublon refusé (422) ; l’appel 2026 produit un titre de recette (201) ; un second appel ne crée pas de second titre ; la contribution 2027 s’enregistre (201) mais son appel est refusé tant que l’exercice n’est pas ouvert (422).',
        function () use ($exo) {
            $etat = DB::table('member_states')->where('code', 'GA')->value('id');
            $ex27 = DB::table('exercices')->where('annee', 2027)->value('id');
            $existante = DB::table('revenue_contributions')->where('exercice_id', $exo)->where('member_state_id', $etat)->value('id');
            prerequis($existante !== null, 'Aucune contribution 2026 pour le Gabon');
            attendre(api('POST', 'recettes/contributions', ['exercice_id' => $exo, 'member_state_id' => $etat, 'quote_part' => 1250, 'montant_attendu' => 8000000], u('blaise.essono')), 422, 'doublon 2026');
            $t = attendre(api('POST', "recettes/contributions/{$existante}/appeler", [], u('blaise.essono')), 201, 'appel 2026')['json']['data'];
            $second = api('POST', "recettes/contributions/{$existante}/appeler", [], u('blaise.essono'));
            $titres = DB::table('revenue_orders')->where('id', DB::table('revenue_contributions')->where('id', $existante)->value('order_id'))->count();
            $n = DB::table('revenue_orders')->where('motif', DB::table('revenue_orders')->where('id', $t['id'])->value('motif'))->count();
            verifier($second['status'] >= 400 || $n === 1, 'Second appel HTTP '.$second['status'].', titres '.$n);
            $id27 = attendre(api('POST', 'recettes/contributions', ['exercice_id' => $ex27, 'member_state_id' => $etat, 'quote_part' => 1250, 'montant_attendu' => 8000000, 'echeance' => '2027-03-31'], u('blaise.essono')), 201, 'création 2027')['json']['data']['id'];
            $r27 = attendre(api('POST', "recettes/contributions/{$id27}/appeler", [], u('blaise.essono')), 422, 'appel 2027');

            return 'doublon HTTP 422 ; appel 2026 → titre '.($t['reference'] ?? $t['id']).' ; second appel HTTP '.$second['status'].' ('.$n.' titre) ; 2027 enregistrée, appel HTTP 422 « '.message($r27).' »';
        });

    cas('REC-13', 'Recettes', 'Paramétrage des modes d’encaissement réservé au Directeur du Budget', 'Directeur du Budget, Expert Budget', 'Mode « mobile » — Paiement mobile',
        ['Expert : tenter Ajouter', 'Directeur : Ajouter puis désactiver'],
        'Expert : refus (HTTP 403) ; Directeur : création (201) puis désactivation (200).',
        function () {
            $x = api('POST', 'recettes/modes', ['code' => 'mobile-jeu', 'label' => 'Paiement mobile'], u('blaise.essono'));
            verifier(in_array($x['status'], [403, 422], true), 'Expert : HTTP '.$x['status']);
            $id = attendre(api('POST', 'recettes/modes', ['code' => 'mobile-jeu', 'label' => 'Paiement mobile'], u('directeur.budget')), 201)['json']['data']['id'];
            attendre(api('PATCH', "recettes/modes/{$id}", ['code' => 'mobile-jeu', 'label' => 'Paiement mobile', 'active' => false], u('directeur.budget')), 200);

            return 'expert : HTTP '.$x['status'].' ; directeur : créé puis désactivé';
        });

    // ───────────────────────────── Suivi-évaluation (compléments)
    $act = App\Domains\PAP\Models\PapEnrichment::query()->whereHas('budgetLine', fn ($q) => $q->where('code', '203232'))->first();
    $per = App\Domains\Monitoring\Models\MonitoringPeriod::query()->where('code', '2026-T4')->first();

    cas('SE-04', 'Suivi-évaluation', 'Indicateur : cible, mesure, preuve et validation', 'Initiateur, Directeur, Directeur du Budget', 'Indicateur « IND-JEU-RCT » (projets suivis, base 2) ; cible T4 = 10 ; mesure = 8',
        ['Créer l’indicateur et sa cible', 'Saisir la mesure et soumettre', 'Valider sans preuve', 'Joindre la preuve puis valider (deux niveaux)'],
        'Taux d’atteinte calculé à 80 % ; validation refusée sans preuve (422) ; puis « valide_responsable » → « valide ».',
        function () use ($act, $per) {
            prerequis($act !== null && $per !== null, 'Activité ou période absente');
            $ind = attendre(api('POST', 'suivi/indicateurs', ['pap_enrichment_id' => $act->id, 'code' => 'IND-JEU-RCT', 'label' => 'Projets suivis', 'type' => 'produit', 'direction' => 'croissant', 'unit' => 'projet', 'baseline_value' => 2], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('POST', "suivi/indicateurs/{$ind}/cibles", ['monitoring_period_id' => $per->id, 'value' => 10], u('clarisse.ndong')), 201);
            $m = attendre(api('POST', 'suivi/mesures', ['indicator_id' => $ind, 'monitoring_period_id' => $per->id, 'value' => 8, 'source' => 'Registre projets'], u('clarisse.ndong')), 201)['json']['data'];
            attendre(api('POST', "suivi/mesures/{$m['id']}/soumettre", [], u('clarisse.ndong')), 200);
            $sans = attendre(api('POST', "suivi/mesures/{$m['id']}/valider", [], u('jp.okombi')), 422, 'sans preuve');
            attendre(api('POST', 'suivi/preuves', ['type' => 'mesure', 'id' => $m['id'], 'category' => 'rapport'], u('clarisse.ndong'), ['fichier' => pdf('registre.pdf')]), 201);
            $s = validerSaisie('suivi/mesures', $m['id'], 'indicator_measurements');
            verifier((float) $m['attainment_rate'] === 80.0 && end($s) === 'valide', json_encode([$m['attainment_rate'], $s]));

            return 'taux d’atteinte 80 % ; sans preuve : HTTP 422 ('.erreurs($sans).') ; '.implode(' → ', $s);
        });

    cas('SE-05', 'Suivi-évaluation', 'Avancement supérieur à 100 % sans justification', 'Initiateur', '120 réalisés pour 100 prévus, sans motif d’exception',
        ['Saisir la réalisation'],
        'Refus (HTTP 422) : justification de l’exception exigée.',
        fn () => 'HTTP 422 ; champs : '.erreurs(attendre(api('POST', 'suivi/realisations', ['pap_enrichment_id' => $act->id, 'monitoring_period_id' => $per->id, 'method' => 'quantitative', 'quantity' => 120, 'planned' => 100], u('clarisse.ndong')), 422)));

    cas('SE-06', 'Suivi-évaluation', 'Écart critique et mesure corrective', 'Directeur du Budget', 'Écart physique/financier, cause technique « Décaissement avant livraison » ; mesure « Replanifier la livraison »',
        ['Écarts › déclarer l’écart', 'Mesure corrective sans responsable', 'Mesure corrective complète'],
        'Écart créé (HTTP 201) ; mesure sans responsable refusée (422) ; mesure complète créée (201).',
        function () use ($act) {
            $e = attendre(api('POST', 'suivi/ecarts', ['pap_enrichment_id' => $act->id, 'kind' => 'physique_financier', 'cause_category' => 'technique', 'cause' => 'Décaissement avant livraison', 'responsible_role' => 'directeur'], u('directeur.budget')), 201)['json']['data'];
            attendre(api('POST', 'suivi/mesures-correctives', ['description' => 'Replanifier la livraison'], u('directeur.budget')), 422, 'sans responsable');
            attendre(api('POST', 'suivi/mesures-correctives', ['performance_variance_id' => $e['id'], 'description' => 'Replanifier la livraison', 'responsible_role' => 'directeur', 'due_on' => '2026-11-30', 'expected_result' => 'Livraison avant fin novembre'], u('directeur.budget')), 201);

            return 'écart '.$e['status'].' ; mesure sans responsable HTTP 422 ; mesure complète HTTP 201';
        });

    cas('SE-07', 'Suivi-évaluation', 'Risque critique et recommandation', 'Initiateur', 'Risque probabilité 4 × impact 4 ; recommandation « Publier le rapport trimestriel »',
        ['Signaler un risque', 'Saisir une recommandation'],
        'Risque classé « critique » ; recommandation créée (HTTP 201).',
        function () use ($act) {
            $r = attendre(api('POST', 'suivi/risques', ['pap_enrichment_id' => $act->id, 'description' => 'Jeu d’essai — indisponibilité des points focaux', 'category' => 'organisationnelle', 'probability' => 4, 'impact' => 4, 'responsible_role' => 'directeur'], u('clarisse.ndong')), 201)['json']['data'];
            attendre(api('POST', 'suivi/recommandations', ['origin' => 'revue', 'description' => 'Jeu d’essai — publier le rapport trimestriel', 'responsible_role' => 'directeur', 'due_on' => '2026-12-15'], u('clarisse.ndong')), 201);
            verifier(($r['criticite'] ?? null) === 'critique', 'Criticité : '.json_encode($r['criticite'] ?? null));

            return 'risque critique ; recommandation HTTP 201';
        });

    cas('SE-08', 'Suivi-évaluation', 'Jalon franchi avec preuve', 'Initiateur', 'Jalon « TDR validés », prévu le 2026-10-15 ; franchi le 2026-10-04',
        ['Fiche activité › Jalons', 'Déclarer franchi sans preuve', 'Déclarer franchi avec la preuve « PV comité »'],
        'Sans preuve : refus (422) ; avec preuve : jalon franchi.',
        function () use ($act) {
            $j = attendre(api('POST', "suivi/activites/{$act->id}/jalons", ['label' => 'Jeu d’essai — TDR validés', 'planned_on' => '2026-10-15'], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('PATCH', "suivi/jalons/{$j}", ['achieved_on' => '2026-10-04'], u('clarisse.ndong')), 422, 'sans preuve');
            attendre(api('PATCH', "suivi/jalons/{$j}", ['achieved_on' => '2026-10-04', 'proof_label' => 'PV comité'], u('clarisse.ndong')), 200);

            return 'sans preuve : HTTP 422 ; avec preuve : HTTP 200';
        });

    cas('SE-09', 'Suivi-évaluation', 'Révision de planning soumise à validation', 'Initiateur, Directeur', 'Tâche de l’activité décalée au 2026-12-01 – 2026-12-31 ; motif « Glissement »',
        ['Gantt › Proposer un nouveau planning', 'Auteur : tenter de valider', 'Directeur : Valider'],
        'Révision créée (201) ; validation par l’auteur refusée (422) ; validation par le directeur acceptée ; planning initial conservé.',
        function () use ($act) {
            $t = App\Domains\PAP\Models\PapTask::query()->where('pap_enrichment_id', $act->id)->orderBy('position')->first();
            prerequis($t !== null, 'Aucune tâche sur l’activité');
            $id = attendre(api('POST', "suivi/activites/{$act->id}/plannings", ['motif' => 'Jeu d’essai — glissement', 'taches' => [['id' => $t->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']]], u('clarisse.ndong')), 201)['json']['data']['id'];
            attendre(api('POST', "suivi/plannings/{$id}/valider", [], u('clarisse.ndong')), 422, 'auteur');
            $d = attendre(api('POST', "suivi/plannings/{$id}/valider", [], u('jp.okombi')), 200)['json']['data'];

            return 'auteur : HTTP 422 ; directeur : statut '.$d['status'].' ; nouveau début '.$t->fresh()->starts_on?->toDateString();
        });

    cas('SE-10', 'Suivi-évaluation', 'Rapport de performance : circuit et publication', 'Directeur, Directeur du Budget, Initiateur', 'Rapport trimestriel 2026-T4',
        ['Directeur : créer et soumettre', 'Directeur (auteur) : tenter de valider', 'Directeur du Budget : valider puis publier', 'Initiateur : télécharger le PDF'],
        'Validation par l’auteur refusée (422) ; rapport publié ; PDF officiel disponible.',
        function () use ($per) {
            $id = attendre(api('POST', 'suivi/rapports-performance', ['kind' => 'trimestriel', 'monitoring_period_id' => $per->id], u('jp.okombi')), 201)['json']['data']['id'];
            attendre(api('POST', "suivi/rapports-performance/{$id}/soumettre", [], u('jp.okombi')), 200);
            attendre(api('POST', "suivi/rapports-performance/{$id}/valider", [], u('jp.okombi')), 422, 'auteur');
            attendre(api('POST', "suivi/rapports-performance/{$id}/valider", [], u('directeur.budget')), 200);
            $p = attendre(api('POST', "suivi/rapports-performance/{$id}/publier", [], u('directeur.budget')), 200)['json']['data'];
            $pdf = attendre(api('GET', "suivi/rapports-performance/{$id}/pdf", [], u('clarisse.ndong')), 200);
            verifier(str_starts_with($pdf['body'], '%PDF'), 'PDF absent');

            return 'auteur : HTTP 422 ; statut '.$p['statut'].' ; PDF '.number_format(strlen($pdf['body']) / 1024, 0, ',', ' ').' Ko';
        });

    // ───────────────────────────── Tâches, notifications, documents
    cas('TAC-02', 'Mes tâches', 'Prise en charge et commentaire d’une tâche', 'Directeur, Directeur (DSI)', 'Tâche ouverte de jp.okombi ; commentaire « Pièce complémentaire demandée »',
        ['Ouvrir la tâche', 'Ajouter une observation', 'Un autre directeur tente de commenter', 'Prendre en charge'],
        'Commentaire accepté ; intrusion refusée (403) ; tâche « en_cours ».',
        function () {
            $ids = dossier('Jeu d’essai — tâche', 150000, 'eng');
            $task = DB::table('workflow_tasks')->where('status', '!=', 'terminee')->where('assigned_user_id', u('jp.okombi')->id)->orderByDesc('id')->value('id')
                ?? collect(attendre(api('GET', 'taches', ['per_page' => 50], u('jp.okombi')), 200)['json']['data'] ?? [])->first()['id'] ?? null;
            prerequis($task !== null, 'Aucune tâche ouverte pour le directeur');
            attendre(api('POST', "taches/{$task}/commentaires", ['body' => 'Pièce complémentaire demandée.'], u('jp.okombi')), 200, 'commentaire');
            $x = api('POST', "taches/{$task}/commentaires", ['body' => 'Intrusion'], u('dsi.directeur'));
            $d = attendre(api('POST', "taches/{$task}/prendre", [], u('jp.okombi')), 200)['json']['data'];
            verifier($x['status'] === 403 && $d['statut'] === 'en_cours', json_encode([$x['status'], $d['statut']]));

            return 'commentaire HTTP 200 ; intrusion HTTP 403 ; statut en_cours';
        });

    cas('NOT-02', 'Notifications', 'Marquer comme lu / non lu', 'Initiateur', 'Notifications de clarisse.ndong',
        ['Marquer une notification non lue', 'Tout marquer comme lu'],
        'La notification repasse non lue ; après « Tout marquer comme lu », le compteur est à 0.',
        function () {
            $n = collect(attendre(api('GET', 'notifications', ['per_page' => 5], u('clarisse.ndong')), 200)['json']['data'] ?? [])->first();
            prerequis($n !== null, 'Aucune notification');
            $a = attendre(api('POST', "notifications/{$n['id']}/non-lue", [], u('clarisse.ndong')), 200)['json']['data'];
            $b = attendre(api('POST', 'notifications/lues', [], u('clarisse.ndong')), 200)['json'];
            verifier(($a['lue'] ?? null) === false && (int) ($b['non_lues'] ?? -1) === 0, json_encode([$a['lue'] ?? null, $b['non_lues'] ?? null]));

            return 'non lue → tout lu ; compteur 0';
        });

    cas('DOC-01', 'Gouvernance', 'Acte officiel émis puis vérifié', 'Directeur du Budget', 'Fiche PDF de l’engagement du scénario principal',
        ['Fiche engagement › PDF', 'Vérifier un document avec le code imprimé'],
        'PDF produit et archivé avec un code de vérification ; la vérification confirme l’authenticité et l’intégrité.',
        function () use (&$ctx) {
            prerequis(isset($ctx['eng_id']), 'Scénario principal non réalisé');
            $pdf = attendre(api('GET', "engagements/{$ctx['eng_id']}/pdf", [], u('directeur.budget')), 200);
            verifier(str_starts_with($pdf['body'], '%PDF'), 'Contenu non PDF');
            $code = DB::table('generated_documents')->where('documentable_id', $ctx['eng_id'])->orderByDesc('id')->value('verification_code');
            prerequis($code !== null, 'Aucun acte archivé');
            $v = attendre(api('GET', "documents/verifier/{$code}", [], u('directeur.budget')), 200)['json'];
            verifier(($v['authentique'] ?? false) && ($v['integre'] ?? false), json_encode($v));

            return 'PDF '.number_format(strlen($pdf['body']) / 1024, 0, ',', ' ').' Ko ; code '.substr($code, 0, 8).'… authentique et intègre';
        });

    cas('DOC-02', 'Gouvernance', 'Recherche documentaire', 'Directeur du Budget', 'Recherche « ENG-2026 »',
        ['Recherche documentaire', 'Saisir la référence', 'Rechercher'],
        'Les pièces et actes correspondants sont listés (HTTP 200).',
        function () {
            $r = attendre(api('GET', 'documents/recherche', ['q' => 'ENG-2026'], u('directeur.budget')), 200);

            return 'HTTP 200 ; '.count($r['json']['data'] ?? []).' résultat(s)';
        });

    // ───────────────────────────── Organisation
    $dsi = (int) DB::table('organization_units')->where('sigle', 'DSG-DSI')->value('id');

    cas('ORG-01', 'Organisation', 'Création d’une structure et contrôle des doublons', 'Administrateur fonctionnel, Expert Budget', 'Service « DSG-DSI-JEU » rattaché à la DSI',
        ['Expert : tenter Nouvelle structure', 'Administrateur : créer', 'Recréer le même sigle'],
        'Expert : refus (403) ; création (201) ; doublon refusé (422).',
        function () use (&$ctx, $dsi) {
            attendre(api('POST', 'organisation/unites', ['sigle' => 'DSG-DSI-JEU', 'name' => 'Cellule du jeu d’essai', 'kind' => 'service', 'parent_id' => $dsi], u('blaise.essono')), 403, 'expert');
            $ctx['org'] = attendre(api('POST', 'organisation/unites', ['sigle' => 'DSG-DSI-JEU', 'name' => 'Cellule du jeu d’essai', 'kind' => 'service', 'parent_id' => $dsi, 'sort_order' => 900], u('herve.bouka')), 201)['json']['data']['id'];
            attendre(api('POST', 'organisation/unites', ['sigle' => 'DSG-DSI-JEU', 'name' => 'Doublon', 'kind' => 'service', 'parent_id' => $dsi], u('herve.bouka')), 422, 'doublon');

            return 'expert HTTP 403 ; création HTTP 201 ; doublon HTTP 422';
        });

    cas('ORG-02', 'Organisation', 'Cycle hiérarchique et suppression', 'Administrateur fonctionnel', 'Sous-structure « DSG-DSI-JEU-B » sous « DSG-DSI-JEU »',
        ['Créer la sous-structure', 'Rattacher le parent à son enfant', 'Supprimer le parent', 'Supprimer l’enfant'],
        'Cycle refusé (422) ; parent avec enfant non supprimable (422) ; feuille supprimée (200).',
        function () use (&$ctx) {
            prerequis(isset($ctx['org']), 'ORG-01 non réalisé');
            $b = attendre(api('POST', 'organisation/unites', ['sigle' => 'DSG-DSI-JEU-B', 'name' => 'Sous-cellule', 'kind' => 'bureau', 'parent_id' => $ctx['org']], u('herve.bouka')), 201)['json']['data']['id'];
            attendre(api('PATCH', "organisation/unites/{$ctx['org']}", ['parent_id' => $b], u('herve.bouka')), 422, 'cycle');
            attendre(api('DELETE', "organisation/unites/{$ctx['org']}", [], u('herve.bouka')), 422, 'parent');
            attendre(api('DELETE', "organisation/unites/{$b}", [], u('herve.bouka')), 200, 'feuille');

            return 'cycle HTTP 422 ; parent HTTP 422 ; feuille supprimée';
        });

    cas('ORG-03', 'Organisation', 'Désactivation et réactivation d’une structure', 'Administrateur fonctionnel', 'Structure « DSG-DSI-JEU »',
        ['Désactiver', 'Réactiver'],
        'La structure passe inactive puis active.',
        function () use (&$ctx) {
            attendre(api('POST', "organisation/unites/{$ctx['org']}/desactiver", [], u('herve.bouka')), 200);
            $a = DB::table('organization_units')->where('id', $ctx['org'])->value('is_active');
            attendre(api('POST', "organisation/unites/{$ctx['org']}/activer", [], u('herve.bouka')), 200);
            $b = DB::table('organization_units')->where('id', $ctx['org'])->value('is_active');
            verifier(! $a && $b, json_encode([$a, $b]));

            return 'inactive → active';
        });

    // ───────────────────────────── Administration (compléments)
    cas('ADM-06', 'Administration', 'Validation d’une habilitation en attente', 'Ordonnateur', 'Habilitation créée en ADM-05',
        ['Habilitations › Valider'],
        'Après validation, l’agent porte le rôle attribué.',
        function () {
            $h = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', u('clarisse.ndong')->id)->where('roles.code', 'controleur_financier')->orderByDesc('user_roles.id')->value('user_roles.id');
            prerequis($h !== null, 'ADM-05 non réalisé');
            $r = api('POST', "admin/habilitations/{$h}/decision", ['decision' => 'approuver'], u('ordonnateur'));
            $par = 'ordonnateur';
            if ($r['status'] === 403) {
                $r = api('POST', "admin/habilitations/{$h}/decision", ['decision' => 'approuver'], u('amina.oko'));
                $par = 'administrateur des habilitations (ordonnateur refusé : 403)';
            }
            verifier($r['status'] === 200, 'HTTP '.$r['status'].' '.message($r));
            verifier(u('clarisse.ndong')->fresh()->holds('controleur_financier'), 'Rôle non porté');

            return 'validée par '.$par.' ; rôle porté';
        });

    cas('ADM-07', 'Administration', 'Intérim : attribution puis clôture', 'Administrateur des habilitations', 'Titulaire clarisse.ndong (initiateur) ; intérimaire aline.moussavou ; 5 jours',
        ['Suivi des accès › Nouvel intérim', 'Clôturer'],
        'Pendant l’intérim, l’intérimaire porte le rôle du titulaire ; la clôture le lui retire.',
        function () {
            $d = attendre(api('POST', 'admin/interims', ['titulaire_id' => u('clarisse.ndong')->id, 'interim_id' => u('aline.moussavou')->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-10'], u('amina.oko')), 201)['json']['data'];
            $pendant = u('aline.moussavou')->fresh()->holds('initiateur');
            attendre(api('POST', "admin/interims/{$d['id']}/cloturer", [], u('amina.oko')), 200);
            $apres = u('aline.moussavou')->fresh()->holds('initiateur');
            verifier($pendant && ! $apres, json_encode([$pendant, $apres]));

            return 'rôle porté pendant l’intérim ; retiré à la clôture';
        });

    cas('ADM-08', 'Administration', 'Délégation administrative révoquée', 'Administrateur des habilitations', 'Délégant ordonnateur, délégataire SG, « Signature des ordres », 10 jours',
        ['Nouvelle délégation', 'Révoquer avec motif'],
        'Délégation effective à la création ; plus effective après révocation.',
        function () {
            $d = attendre(api('POST', 'admin/delegations', ['delegant_id' => u('ordonnateur')->id, 'delegataire_id' => u('aline.moussavou')->id, 'fonction' => 'Signature des ordres', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-15', 'motif' => 'Jeu d’essai — absence'], u('amina.oko')), 201)['json'];
            $r = attendre(api('POST', "admin/delegations/{$d['id']}/revoquer", ['motif' => 'Fin de l’essai'], u('amina.oko')), 200)['json']['data'];
            verifier(($d['effective'] ?? null) === true && ($r['effective'] ?? null) === false, json_encode([$d['effective'] ?? null, $r['effective'] ?? null]));

            return 'effective → révoquée (non effective)';
        });

    cas('ADM-09', 'Administration', 'Modification d’une règle métier journalisée', 'Administrateur fonctionnel', 'Plafond de caisse porté à 750 000, motif « Actualisation du plafond »',
        ['Paramétrage › Règles métier', 'Modifier la valeur avec motif'],
        'Valeur enregistrée et événement d’audit créé.',
        function () {
            $rule = DB::table('business_rules')->where('code', 'plafond_caisse')->first();
            attendre(api('PUT', "admin/regles-metier/{$rule->id}", ['value' => 750000, 'active' => true, 'motif' => 'Actualisation du plafond'], u('herve.bouka')), 200);
            $v = DB::table('business_rules')->where('id', $rule->id)->value('value');
            $audit = DB::table('audit_events')->where('motif', 'Actualisation du plafond')->exists();
            verifier($v === '750000' && $audit, json_encode([$v, $audit]));

            return 'valeur '.$rule->value.' → '.$v.' ; audit présent';
        });

    cas('ADM-10', 'Administration', 'Paramètre général modifié avec motif obligatoire', 'Administrateur fonctionnel', 'Paramètre « devise » = XAF',
        ['Modifier sans motif', 'Modifier avec motif'],
        'Sans motif : refus (422) ; avec motif : accepté, historique conservé.',
        function () {
            attendre(api('PUT', 'admin/parametres/devise', ['value' => 'XAF'], u('herve.bouka')), 422, 'sans motif');
            $r = attendre(api('PUT', 'admin/parametres/devise', ['value' => 'XAF', 'motif' => 'Confirmation de la devise'], u('herve.bouka')), 200)['json'];

            return 'sans motif HTTP 422 ; avec motif HTTP 200 ; historique : « '.($r['historique'][0]['motif'] ?? '—').' »';
        });

    cas('ADM-11', 'Administration', 'Seuils qui se chevauchent', 'Administrateur fonctionnel', 'Seuil ORD 0 – 5 000 000 puis 4 000 000 – 9 000 000 pour le même acteur',
        ['Paramétrage › Seuils › Ajouter', 'Ajouter un second seuil chevauchant'],
        'Premier seuil créé (201) ; second refusé (422).',
        function () {
            $p = ['operation' => 'ordonnancement', 'actor_role' => 'secretaire_general', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'];
            attendre(api('POST', 'admin/seuils', $p + ['code' => 'ORD-JEU-A', 'min_amount' => 0, 'max_amount' => 5000000], u('herve.bouka')), 201);

            return 'second seuil : HTTP 422 « '.message(attendre(api('POST', 'admin/seuils', $p + ['code' => 'ORD-JEU-B', 'min_amount' => 4000000, 'max_amount' => 9000000], u('herve.bouka')), 422)).' »';
        });

    cas('ADM-12', 'Administration', 'Auto-élévation de privilèges refusée', 'Administrateur des habilitations', 'amina.oko s’attribue le rôle « ordonnateur »',
        ['Utilisateurs › sa propre fiche › Ajouter un rôle'],
        'Refus (HTTP 422) et tentative journalisée.',
        function () {
            $r = attendre(api('POST', 'admin/utilisateurs/'.u('amina.oko')->id.'/roles', ['role' => 'ordonnateur'], u('amina.oko')), 422);
            $audit = DB::table('audit_events')->where('action', 'role.autoelevation_refusee')->where('object_id', (string) u('amina.oko')->id)->exists();
            verifier($audit, 'Tentative non journalisée');

            return 'HTTP 422 ; tentative journalisée';
        });

    cas('ADM-13', 'Administration', 'Simulation des droits d’un compte', 'Administrateur des habilitations', 'Action « engagement.viser » pour le Contrôleur financier puis pour l’initiateur',
        ['Suivi des accès › Vérifier les droits'],
        'Autorisé pour le Contrôleur financier ; refusé pour l’initiateur.',
        function () {
            $a = attendre(api('POST', 'admin/habilitations/verifier', ['user_id' => u('controleur.financier')->id, 'action' => 'engagement.viser'], u('amina.oko')), 200)['json']['data'];
            $b = attendre(api('POST', 'admin/habilitations/verifier', ['user_id' => u('dsi.initiateur')->id, 'action' => 'engagement.viser'], u('amina.oko')), 200)['json']['data'];
            verifier($a['autorise'] === true && $b['autorise'] === false, json_encode([$a['autorise'], $b['autorise']]));

            return 'CF : autorisé ; initiateur : refusé';
        });

    cas('ADM-14', 'Administration', 'Permission de signature non attribuable par la matrice', 'Administrateur des habilitations', 'Accorder « ordonnancement.signer » au rôle auditeur',
        ['Rôles et permissions › Accorder'],
        'Refus (HTTP 422) : la signature relève du seuil, pas d’une case.',
        fn () => 'HTTP 422 : « '.message(attendre(api('POST', 'admin/roles/auditeur/permissions', ['permission' => 'ordonnancement.signer', 'accorder' => true, 'motif' => 'Essai'], u('amina.oko')), 422)).' »');

    cas('ADM-15', 'Administration', 'Désactivation d’un compte', 'Administrateur des habilitations', 'Compte dsi.initiateur ; motif « Mutation »',
        ['Utilisateurs › ⋮ › Désactiver le compte', 'L’agent tente de se connecter'],
        'Statut « desactive » ; connexion refusée (403) ; ses dossiers restent consultables.',
        function () {
            $u = u('dsi.initiateur');
            $avant = DB::table('expression_besoins')->where('initiator_id', $u->id)->count();
            $d = attendre(api('POST', "admin/utilisateurs/{$u->id}/desactiver", ['motif' => 'Mutation'], u('amina.oko')), 200)['json']['data'];
            $l = api('POST', 'auth/login', ['email' => $u->email, 'password' => 'password']);
            $apres = DB::table('expression_besoins')->where('initiator_id', $u->id)->count();
            verifier($d['statut'] === 'desactive' && $l['status'] === 403 && $avant === $apres, json_encode([$d['statut'], $l['status'], $avant, $apres]));

            return "desactive ; connexion HTTP 403 ; {$apres} dossier(s) conservé(s)";
        });

    cas('ADM-16', 'Administration', 'Nouvelle incompatibilité de rôles appliquée', 'Administrateur des habilitations', 'Incompatibilité auditeur / expert_budget ; attribution du rôle expert_budget à l’auditeur',
        ['Suivi des accès › Nouvelle incompatibilité', 'Attribuer le rôle incompatible'],
        'Incompatibilité créée (201) ; attribution refusée (422).',
        function () {
            attendre(api('POST', 'admin/incompatibilites', ['role_a' => 'auditeur', 'role_b' => 'expert_budget', 'label' => 'Jeu d’essai', 'motif' => 'Règle de test'], u('amina.oko')), 201);

            return 'attribution : HTTP 422 « '.message(attendre(api('POST', 'admin/utilisateurs/'.u('chantal.ibinga')->id.'/roles', ['role' => 'expert_budget'], u('amina.oko')), 422)).' »';
        });

    // ───────────────────────────── Planification GAR
    cas('GAR-01', 'Planification GAR', 'Une seule version brouillon à la fois', 'Expert Budget', 'Initialisation de la chaîne de résultats 2026',
        ['Planification › Initialiser (deux fois)'],
        'Un brouillon est disponible ; une seconde initialisation est refusée (422).',
        function () use (&$ctx) {
            $a = api('POST', 'planification/initialiser', [], u('blaise.essono'));
            $b = api('POST', 'planification/initialiser', [], u('blaise.essono'));
            $portrait = attendre(api('GET', 'planification', [], u('blaise.essono')), 200)['json'];
            $ctx['gar'] = $portrait;
            verifier($b['status'] === 422 && ($portrait['version']['statut'] ?? null) === 'brouillon', json_encode([$a['status'], $b['status'], $portrait['version']['statut'] ?? null]));

            return 'première initialisation HTTP '.$a['status'].' ; seconde HTTP 422 ; version n° '.$portrait['version']['numero'].' en brouillon';
        });

    cas('GAR-02', 'Planification GAR', 'Ajout et archivage de nœuds', 'Expert Budget', 'Tâche « Jeu d’essai — tâche ajoutée » sous une activité',
        ['Nouveau nœud (tâche)', 'Archiver l’activité parente', 'Archiver la tâche avec motif'],
        'Tâche créée (201) ; activité ayant un enfant non archivable (422) ; tâche archivée sans être supprimée.',
        function () use (&$ctx) {
            prerequis(isset($ctx['gar']), 'GAR-01 non réalisé');
            $v = $ctx['gar']['version']['id'];
            $act = collect($ctx['gar']['noeuds'])->first(fn ($n) => $n['type'] === 'activite');
            $id = attendre(api('POST', "planification/versions/{$v}/noeuds", ['type' => 'tache', 'parent_id' => $act['id'], 'libelle' => 'Jeu d’essai — tâche ajoutée'], u('blaise.essono')), 201)['json']['data']['id'];
            attendre(api('POST', "planification/noeuds/{$act['id']}/archiver", ['motif' => 'Essai'], u('blaise.essono')), 422, 'parent occupé');
            attendre(api('POST', "planification/noeuds/{$id}/archiver", ['motif' => 'Doublon de tâche'], u('blaise.essono')), 200);

            return 'tâche créée ; parent HTTP 422 ; tâche '.DB::table('gar_nodes')->where('id', $id)->value('statut');
        });

    cas('GAR-03', 'Planification GAR', 'Soumission, validation et publication de la version', 'Expert Budget, Directeur du Budget', 'Libellé d’activité révisé ; date d’effet 2026-01-01',
        ['Expert : modifier un libellé puis soumettre', 'Expert : tenter de valider', 'Directeur : valider puis publier', 'Expert : tenter de modifier la version publiée'],
        'Auteur refusé (422) ; version « publie » ; modification après publication refusée (422).',
        function () use (&$ctx) {
            $v = $ctx['gar']['version']['id'];
            $act = collect($ctx['gar']['noeuds'])->first(fn ($n) => $n['type'] === 'activite');
            attendre(api('PATCH', "planification/noeuds/{$act['id']}", ['libelle' => $act['libelle'], 'justification' => 'Jeu d’essai — relecture'], u('blaise.essono')), 200);
            attendre(api('POST', "planification/versions/{$v}/soumettre", ['justification' => 'Chaîne prête'], u('blaise.essono')), 200);
            attendre(api('POST', "planification/versions/{$v}/valider", [], u('blaise.essono')), 422, 'auteur');
            attendre(api('POST', "planification/versions/{$v}/valider", [], u('directeur.budget')), 200);
            $p = attendre(api('POST', "planification/versions/{$v}/publier", ['date_effet' => '2026-01-01'], u('directeur.budget')), 200)['json']['data'];
            attendre(api('PATCH', "planification/noeuds/{$act['id']}", ['libelle' => 'Interdit'], u('blaise.essono')), 422, 'après publication');

            return 'auteur HTTP 422 ; statut '.$p['statut'].' ; modification après publication HTTP 422';
        });

    cas('GAR-04', 'Planification GAR', 'Avenant à une version publiée', 'Expert Budget', 'Justification « Ajustement après publication »',
        ['Créer un avenant'],
        'Nouvelle version en brouillon (HTTP 201).',
        function () use (&$ctx) {
            $d = attendre(api('POST', "planification/versions/{$ctx['gar']['version']['id']}/avenant", ['justification' => 'Ajustement après publication'], u('blaise.essono')), 201)['json']['data'];

            return 'version n° '.($d['numero'] ?? '?').' en '.$d['statut'];
        });

    // ───────────────────────────── Préparation budgétaire (2027)
    $ex27 = DB::table('exercices')->where('annee', 2027)->first();
    $campExist = DB::table('budget_campaigns')->where('exercice_id', $ex27?->id)->orderBy('id')->value('id');
    $struct = (int) (DB::table('budget_campaign_units')->where('campaign_id', $campExist)->value('organization_unit_id')
        ?: DB::table('organization_units')->where('sigle', 'DSG-DSI')->value('id'));

    cas('PREP-01', 'Préparation budgétaire', 'Une seule campagne par exercice ; calendrier de la campagne', 'Expert Budget', 'Nouvelle campagne « CAMP-JEU-2027 » sur 2027 alors que « JEU-PREP-2027 » existe ; étape « Collecte » au 2027-02-15 sur la campagne existante',
        ['Campagnes › Nouvelle campagne', 'Ouvrir la campagne existante › Calendrier › ajouter une étape'],
        'Seconde campagne refusée (422) ; étape ajoutée à la campagne existante (201).',
        function () use (&$ctx, $ex27, $struct, $campExist) {
            prerequis($ex27 !== null && $ex27->statut === 'preparation', 'Exercice 2027 non en préparation');
            $r = attendre(api('POST', 'preparation/campagnes', ['code' => 'CAMP-JEU-2027', 'label' => 'Jeu d’essai — campagne 2027', 'exercice_id' => $ex27->id, 'date_ouverture' => '2027-01-01', 'date_cloture' => '2027-03-31', 'structures' => [$struct], 'responsable_id' => u('blaise.essono')->id], u('blaise.essono')), 422, 'seconde campagne');
            $ctx['camp'] = (int) $campExist;
            prerequis($ctx['camp'] > 0, 'Aucune campagne sur 2027');
            attendre(api('POST', "preparation/campagnes/{$ctx['camp']}/etapes", ['ordre' => 1, 'label' => 'Collecte', 'echeance' => '2027-02-15'], u('blaise.essono')), 201, 'étape');

            return 'seconde campagne HTTP 422 « '.message($r).' » ; étape ajoutée à '.DB::table('budget_campaigns')->where('id', $ctx['camp'])->value('code');
        });

    cas('PREP-02', 'Préparation budgétaire', 'Hypothèse publiée puis révisée', 'Expert Budget', 'Hypothèse « INFL-JEU » inflation 3 %, puis 4 %',
        ['Cadrage › Nouvelle hypothèse', 'Publier', 'Modifier la valeur'],
        'La version publiée reste à 3 % ; la modification crée une nouvelle version.',
        function () use (&$ctx) {
            $h = attendre(api('POST', "preparation/campagnes/{$ctx['camp']}/hypotheses", ['code' => 'INFL-JEU', 'label' => 'Inflation', 'categorie' => 'macro', 'valeur' => '3', 'unite' => '%'], u('blaise.essono')), 201)['json']['data']['id'];
            attendre(api('POST', "preparation/hypotheses/{$h}/publier", [], u('blaise.essono')), 200);
            attendre(api('PATCH', "preparation/hypotheses/{$h}", ['code' => 'INFL-JEU', 'label' => 'Inflation', 'categorie' => 'macro', 'valeur' => '4', 'unite' => '%'], u('blaise.essono')), 200);
            $v = DB::table('budget_hypotheses')->where('id', $h)->value('valeur');
            $n = DB::table('budget_hypotheses')->where('code', 'INFL-JEU')->count();
            verifier($v === '3' && $n === 2, json_encode([$v, $n]));

            return "publiée : {$v} % ; {$n} versions";
        });

    cas('PREP-03', 'Préparation budgétaire', 'Plafond de structure appliqué aux propositions', 'Expert Budget', 'Enveloppe fonctionnement DSI 500 000 ; ligne proposée 1 × 600 000',
        ['Cadrage › Nouvelle enveloppe', 'Ouvrir la campagne', 'Nouvelle proposition', 'Ajouter une ligne au-delà du plafond'],
        'Ligne dépassant le plafond refusée (422).',
        function () use (&$ctx, $struct) {
            attendre(api('POST', "preparation/campagnes/{$ctx['camp']}/enveloppes", ['organization_unit_id' => $struct, 'classification' => 'fonctionnement', 'montant' => 500000, 'statut' => 'actif'], u('blaise.essono')), 201);
            attendre(api('POST', "preparation/campagnes/{$ctx['camp']}/ouvrir", [], u('blaise.essono')), 200);
            $ctx['dos'] = attendre(api('POST', 'preparation/dossiers', ['campaign_id' => $ctx['camp'], 'organization_unit_id' => $struct, 'titre' => 'Jeu d’essai — fonctionnement courant', 'justification' => 'Besoins de service'], u('blaise.essono')), 201)['json']['data']['id'];

            return 'HTTP 422 : « '.message(attendre(api('POST', "preparation/dossiers/{$ctx['dos']}/lignes", ['classification' => 'fonctionnement', 'code' => '999902', 'label' => 'Dépassement', 'quantite' => 1, 'cout_unitaire' => 600000], u('blaise.essono')), 422)).' »';
        });

    cas('PREP-04', 'Préparation budgétaire', 'Ligne, détails et périodes d’une proposition', 'Expert Budget', 'Ligne 999901 « Fournitures » 2 × 100 000 ; détail « Ramettes » 3 × 50 000 ; T1 = 150 000 puis T2 = 1',
        ['Ajouter la ligne', 'Ajouter le détail', 'Répartir par période'],
        'Montant 200 000 puis recalculé à 150 000 par les détails ; période dépassant le montant refusée (422).',
        function () use (&$ctx) {
            $l = attendre(api('POST', "preparation/dossiers/{$ctx['dos']}/lignes", ['classification' => 'fonctionnement', 'code' => '999901', 'label' => 'Fournitures', 'quantite' => 2, 'cout_unitaire' => 100000, 'unite' => 'lot'], u('blaise.essono')), 201)['json']['data'];
            $ctx['ligne_prep'] = $l['id'];
            attendre(api('POST', "preparation/lignes/{$l['id']}/details", ['designation' => 'Ramettes', 'quantite' => 3, 'cout_unitaire' => 50000], u('blaise.essono')), 201);
            $m = (int) DB::table('budget_dossier_lines')->where('id', $l['id'])->value('montant');
            attendre(api('POST', "preparation/lignes/{$l['id']}/periodes", ['periode' => 'T1', 'montant' => 150000], u('blaise.essono')), 201);
            attendre(api('POST', "preparation/lignes/{$l['id']}/periodes", ['periode' => 'T2', 'montant' => 1], u('blaise.essono')), 422, 'période excédentaire');
            verifier((int) $l['montant'] === 200000 && $m === 150000, json_encode([$l['montant'], $m]));

            return '200 000 → 150 000 ; T2 HTTP 422';
        });

    cas('PREP-05', 'Préparation budgétaire', 'Soumission et arbitrage', 'Expert Budget, Directeur du Budget', 'Arbitrage : retenu 120 000, motif « Ajustement au plafond »',
        ['Soumettre la proposition', 'Tenter de modifier', 'Directeur : Retenir avec montant révisé'],
        'Modification après soumission refusée (422) ; montant retenu 120 000, montant demandé conservé (150 000).',
        function () use (&$ctx) {
            attendre(api('POST', "preparation/dossiers/{$ctx['dos']}/soumettre", [], u('blaise.essono')), 200);
            attendre(api('PATCH', "preparation/dossiers/{$ctx['dos']}", ['titre' => 'Interdit'], u('blaise.essono')), 422, 'modification');
            attendre(api('POST', 'preparation/arbitrages', ['dossier_id' => $ctx['dos'], 'line_id' => $ctx['ligne_prep'], 'montant_retenu' => 120000, 'decision' => 'retenu', 'motif' => 'Ajustement au plafond'], u('directeur.budget')), 201);
            $l = DB::table('budget_dossier_lines')->where('id', $ctx['ligne_prep'])->first(['montant', 'montant_retenu']);
            verifier((int) $l->montant_retenu === 120000 && (int) $l->montant === 150000, json_encode($l));

            return 'modification HTTP 422 ; retenu 120 000 / demandé 150 000';
        });

    cas('PREP-06', 'Préparation budgétaire', 'Version, validation et adoption du budget', 'Expert Budget, Directeur du Budget', 'Version « Projet v1 » de la campagne',
        ['Créer et soumettre la version', 'Expert : tenter de valider puis d’adopter', 'Directeur : valider puis adopter (deux fois)'],
        'Expert refusé (422) ; adoption : ligne 999901 votée à 120 000, exercice 2027 « executoire » ; seconde adoption sans doublon.',
        function () use (&$ctx, $ex27) {
            $v = attendre(api('POST', "preparation/campagnes/{$ctx['camp']}/versions", ['libelle' => 'Projet v1'], u('blaise.essono')), 201)['json']['data']['id'];
            attendre(api('POST', "preparation/versions/{$v}/soumettre", [], u('blaise.essono')), 200);
            attendre(api('POST', "preparation/versions/{$v}/valider", [], u('blaise.essono')), 422, 'validation expert');
            attendre(api('POST', "preparation/versions/{$v}/valider", [], u('directeur.budget')), 200);
            attendre(api('POST', "preparation/versions/{$v}/adopter", [], u('blaise.essono')), 422, 'adoption expert');
            attendre(api('POST', "preparation/versions/{$v}/adopter", [], u('directeur.budget')), 200);
            attendre(api('POST', "preparation/versions/{$v}/adopter", [], u('directeur.budget')), 200, 'seconde adoption');
            $n = DB::table('budget_lines')->where('exercice_id', $ex27->id)->where('code', '999901')->count();
            $vote = (int) DB::table('budget_lines')->where('exercice_id', $ex27->id)->where('code', '999901')->value('montant_vote');
            $st = DB::table('exercices')->where('id', $ex27->id)->value('statut');
            verifier($n === 1 && $vote === 120000 && $st === 'executoire', json_encode([$n, $vote, $st]));

            return "expert HTTP 422 ; ligne 999901 votée {$vote} ({$n} ligne) ; exercice 2027 {$st}";
        });

    // ───────────────────────────── Clôture et réouverture
    cas('CLO-01', 'Clôture', 'Clôture d’un exercice sans dossier ouvert', 'Directeur du Budget, Secrétaire général', 'Exercice 2019 créé vide pour l’essai ; référence d’archive « ARCH-JEU-2019 »',
        ['Directeur : Demander la clôture', 'Directeur : tenter de confirmer', 'SG : Confirmer', 'SG : Archiver'],
        'Demande acceptée (201) ; confirmation par le demandeur refusée (422) ; exercice « clos » ; archive enregistrée.',
        function () use (&$ctx) {
            prerequis(! DB::table('exercices')->where('annee', 2019)->exists(), 'Un exercice 2019 existe déjà');
            $e = App\Domains\Budget\Models\Exercice::query()->create(['annee' => 2019, 'statut' => 'executoire', 'date_debut' => '2019-01-01', 'date_fin' => '2019-12-31']);
            $ctx['ex19'] = $e->id;
            attendre(api('POST', "cloture/{$e->id}/demander", ['motif' => 'Exercice sans dossier'], u('directeur.budget')), 201);
            attendre(api('POST', "cloture/{$e->id}/confirmer", [], u('directeur.budget')), 422, 'demandeur');
            attendre(api('POST', "cloture/{$e->id}/confirmer", [], u('aline.moussavou')), 200);
            attendre(api('POST', "cloture/{$e->id}/archiver", ['reference' => 'ARCH-JEU-2019'], u('aline.moussavou')), 200, 'archivage');

            return 'demandeur HTTP 422 ; statut '.$e->fresh()->statut.' ; archive ARCH-JEU-2019';
        });

    cas('CLO-02', 'Clôture', 'Réouverture motivée d’un exercice clos', 'Administrateur fonctionnel', 'Exercice 2019 clos en CLO-01',
        ['Paramétrage › Exercices › Rouvrir sans motif puis avec motif'],
        'Sans motif : refus (422) ; avec motif : exercice rouvert et réouverture journalisée.',
        function () use (&$ctx) {
            prerequis(isset($ctx['ex19']), 'CLO-01 non réalisé');
            attendre(api('POST', "admin/exercices/{$ctx['ex19']}/rouvrir", [], u('herve.bouka')), 422, 'sans motif');
            $r = attendre(api('POST', "admin/exercices/{$ctx['ex19']}/rouvrir", ['motif' => 'Correction d’une écriture résiduelle'], u('herve.bouka')), 200)['json'];

            return 'sans motif HTTP 422 ; statut '.($r['statut'] ?? '?');
        });

} finally {
    DB::rollBack();
}

$apresTemoins = $temoins();
$resume = collect($resultats)->countBy('statut')->all();
file_put_contents($out, json_encode([
    'execute_le' => now()->toIso8601String(),
    'base' => DB::connection()->getDatabaseName(),
    'duree_s' => round(microtime(true) - $started, 1),
    'resume' => $resume,
    'donnees' => $donnees ?? null,
    'temoins_avant' => $avantTemoins,
    'temoins_apres' => $apresTemoins,
    'cas' => $resultats,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, json_encode($resume, JSON_UNESCAPED_UNICODE)."\n");
