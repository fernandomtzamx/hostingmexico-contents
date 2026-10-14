<?php
/**
 * Pegar estas lineas en wp-config.php ANTES de "That's all, stop editing!".
 * Genera valores propios: openssl rand -hex 32
 */

// Token que usa Claude para autenticarse como el usuario "soporte" (Authorization: Bearer ...).
define( 'AGENTE_AUTH_TOKEN', 'CAMBIA-ESTE-TOKEN' );

// Secreto compartido con el webhook de GitHub (Settings > Webhooks > Secret).
define( 'AGENTE_GITHUB_SECRET', 'CAMBIA-ESTE-SECRETO' );

// Repositorio y rama de contenidos.
define( 'AGENTE_GITHUB_REPO', 'fernandomtzamx/hostingmexico-contents' );
define( 'AGENTE_GITHUB_BRANCH', 'main' );

// Usuario de WordPress bajo el que se publica (debe existir, rol Editor o Administrador).
define( 'AGENTE_WP_USER', 'soporte' );

// Opcional: token de GitHub (fine-grained, solo lectura de contenidos) si el repo es privado
// o para evitar limites de la API publica.
// define( 'AGENTE_GITHUB_TOKEN', 'github_pat_...' );
