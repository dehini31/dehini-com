<?php
/**
 * Formules d'abonnement de Studioma : la seule source de vérité des prix affichés, des limites et des
 * fonctionnalités de chaque formule. Les limites sont appliquées côté serveur (lib/billing.php) ; la page des
 * prix et les réglages les lisent ici (api.php › billing_plans).
 *
 * Un abonnement appartient à un compte (l'auteur d'un projet : projects.owner_uid). Tout ce qui se passe dans
 * ses projets — Studios, cartes créées par ses membres ou ses invités, membres, liens de partage — compte pour
 * la formule de ce compte. Les invités des liens de partage ne sont jamais des membres payants.
 */
declare(strict_types=1);

/** null = illimité. cards_month : cartes créées par mois civil (les cartes existantes ne sont jamais supprimées). */
const STM_PLANS = [
    'free' => [
        'name' => 'Free', 'rank' => 0, 'tagline' => 'For trying Studioma with one client.',
        'price' => ['month' => 0, 'year' => 0],   // en centimes de dollar US
        'limits' => ['projects' => 1, 'studios' => 1, 'cards_month' => 30, 'members' => 1, 'share_links' => 2],
        'features' => [
            'private_links' => false,     // liens privés (adresses vérifiées) et invités ajoutés par email
            'guest_collab' => false,      // invités : commenter, valider, modifier, demander des droits (sinon lecture seule)
            'approvals' => false,         // demandes de validation (membres et invités) et notifications de validation
            'team' => false,              // inviter des membres inscrits
            'insights' => false,          // tableau de bord « Insights » d'un Studio
        ],
    ],
    'solo' => [
        'name' => 'Solo', 'rank' => 1, 'tagline' => 'For freelancers managing several clients.',
        'price' => ['month' => 1200, 'year' => 12000],
        'limits' => ['projects' => 3, 'studios' => 5, 'cards_month' => null, 'members' => 1, 'share_links' => null],
        'features' => ['private_links' => true, 'guest_collab' => true, 'approvals' => true, 'team' => false, 'insights' => false],
    ],
    'team' => [
        'name' => 'Team', 'rank' => 2, 'tagline' => 'For agencies and in-house teams.',
        'price' => ['month' => 2900, 'year' => 29000],
        'limits' => ['projects' => 10, 'studios' => 20, 'cards_month' => null, 'members' => 5, 'share_links' => null],
        'features' => ['private_links' => true, 'guest_collab' => true, 'approvals' => true, 'team' => true, 'insights' => true],
    ],
];
/** Cartes « illimitées » : garde-fou contre les abus (création automatisée), par compte et par mois. */
const STM_FAIR_USE_CARDS = 5000;
/** Paiement en échec (past_due) : la formule payée reste active ce nombre de jours, puis la formule Free s'applique. */
const STM_PAST_DUE_GRACE_DAYS = 14;

/*
 * Offre de lancement (lib/promo.php) : 30 jours d'accès complet (formule Team) sans carte, et 1 an offert pour 10 amis
 * inscrits et vérifiés. Réglage STM_LAUNCH_PROMO (true par défaut) ; ces valeurs ne se changent qu'ici.
 */
const STM_PROMO_ID = 'launch2026';
const STM_PROMO_PLAN = 'team';
const STM_PROMO_TRIAL_DAYS = 30;
const STM_REFERRAL_GOAL = 10;
const STM_REFERRAL_REWARD_MONTHS = 12;
const STM_REFERRAL_ATTRIBUTION_DAYS = 30;

/**
 * Ce que la page des prix affiche pour chaque formule : uniquement des fonctionnalités qui existent et qui sont
 * réellement appliquées (voir les tests tests/studioma/billing.js).
 */
const STM_PLAN_BULLETS = [
    'free' => [
        '1 project and 1 Studio', '30 new cards per month', 'Content planning board and calendar', 'Levels, columns and automatic handoffs',
        'Up to 2 view-only guest links', 'Just you (no team members)',
    ],
    'solo' => [
        'Everything in Free', '3 projects and 5 Studios', 'Unlimited cards', 'Unlimited public and private guest links',
        'Guests verified by email, without an account', 'Guest requests to comment or edit, approved by you', 'Client approvals and approval notifications',
        'Just you (no team members)',
    ],
    'team' => [
        'Everything in Solo', '10 projects and 20 Studios', 'Up to 5 members, including you', 'Team invitations and roles (manager, creator, collaborator, client, viewer)',
        'Assignments and shared projects', 'Studio performance insights', 'Team activity history',
    ],
];
