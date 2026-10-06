<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Changement d’acteur de démonstration
    |--------------------------------------------------------------------------
    |
    | Lorsqu’il est actif, un utilisateur déjà authentifié peut agir sous
    | l’identité d’un autre acteur via l’en-tête X-Actor-Id. Réservé aux
    | démonstrations locales : il est ignoré en production quelle que soit
    | la valeur de la variable d’environnement.
    |
    */

    'demo_impersonation' => (bool) env('GESBUDEP_DEMO_IMPERSONATION', false)
        && env('APP_ENV') !== 'production',

    /*
    |--------------------------------------------------------------------------
    | Connexion institutionnelle (OpenID Connect)
    |--------------------------------------------------------------------------
    */

    'sso' => [
        'enabled' => (bool) env('GESBUDEP_SSO_ENABLED', false),
        'issuer' => env('GESBUDEP_SSO_ISSUER'),
        'client_id' => env('GESBUDEP_SSO_CLIENT_ID'),
        'client_secret' => env('GESBUDEP_SSO_CLIENT_SECRET'),
        'redirect' => env('GESBUDEP_SSO_REDIRECT', rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/api/v1/auth/sso/callback'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Politique de connexion
    |--------------------------------------------------------------------------
    */

    'login' => [
        'max_attempts' => (int) env('GESBUDEP_LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('GESBUDEP_LOGIN_LOCKOUT_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Boîte « Mes tâches »
    |--------------------------------------------------------------------------
    */

    'taches' => [
        'priorite_faible_apres_jours' => 14,
        'relance_avant_jours' => 2,
        'escalade_jours' => [2, 4, 5, 7],
        'montant_eleve' => 5_000_000,
    ],

    /*
    | Notifications : récapitulatif quotidien par courriel (préférence par
    | utilisateur) et durée de conservation des avis déjà lus.
    */
    'notifications' => [
        'recapitulatif' => (bool) env('NOTIFICATIONS_RECAPITULATIF', true),
        'conservation_lues_jours' => (int) env('NOTIFICATIONS_CONSERVATION_JOURS', 90),
    ],

];
