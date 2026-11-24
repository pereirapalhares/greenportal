<?php
declare(strict_types=1);

/*
 * Migração 407 — Onda 2 da Revisão G, Legal (24/11/2026): LEG-26 (categorias), LEG-27 (Responsável × Categoria: usuário, vários
 * ou grupo) e LEG-28 (atribuição automática pela categoria, mantendo a manual). Mesma estrutura da 361 com a área LEGAL:
 *   - database/407_legislacao_categorias_empresa.sql: legislacaoxcategoria_empresa (vínculo da empresa), vw_legislacao_categorias
 *     (catálogo central) e vw_legislacao_categorias_empresa (as da empresa, ou as centrais quando a empresa não definiu);
 *   - menu "Responsáveis e avisos (Requisitos legais)" (RESP_REGRAS_LEGAL) depois de Requisitos legais;
 *   - vw_ml_requisito_responsaveis (358) com as pessoas das regras → Meus requisitos e pendências;
 *   - aviso de prazo: próxima avaliação do requisito em até 30 dias, para as pessoas das regras com Prazos.
 * O vínculo lei × categoria é feito no botão "Categorias" da tela de Requisitos legais (assets/js/legislacao-categorias.js).
 * Este script só chama o da 361 (mesmas opções: --simular, --banco=, --remover).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-407-responsaveis-legal.php [--simular] [--banco=gcrown_dbp]
 */
require __DIR__ . '/aplicar-migracao-361-responsaveis-regras.php';
