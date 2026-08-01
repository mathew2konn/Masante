<?php

/**
 * P1 (Identité) — MFA « prêt à activer » (CDC_10 §3.5 ; CDC_01 §7.2 « prêt à activer »).
 *
 * L'enrôlement/confirmation d'un second facteur est toujours disponible. Ce qui est gouverné
 * ici, c'est l'EXIGENCE à la connexion : tant que `enforce=false` (défaut MVP), le login reste
 * strictement inchangé (patients Expo Go non impactés). Bascule sans redéploiement : `MFA_ENFORCE=true`.
 */
return [
    // Interrupteur global. false en MVP → comportement de connexion inchangé.
    'enforce' => env('MFA_ENFORCE', false),

    // Rôles pour qui la MFA est OBLIGATOIRE (CDC_10 §3.5). Valeurs = enum Role de @masante/shared.
    // « médecins, administrateurs, ministère, assurances, super administrateurs ».
    'roles_obligatoires' => [
        'medecin',
        'admin_etablissement',
        'super_admin',
        'ministere',
        'assurance',
    ],

    // Tolérance TOTP : nombre de tranches de 30 s acceptées de part et d'autre (dérive d'horloge).
    'fenetre' => (int) env('MFA_TOTP_FENETRE', 1),

    // Émetteur affiché dans l'application d'authentification (libellé du compte).
    'issuer' => env('MFA_ISSUER', 'MaSante'),

    // Durée de vie du jeton de défi entre login et vérification du 2e facteur (minutes).
    'defi_ttl_minutes' => (int) env('MFA_DEFI_TTL', 5),
];
