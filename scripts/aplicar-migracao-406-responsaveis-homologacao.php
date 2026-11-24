<?php
declare(strict_types=1);

/*
 * Migração 406 — Onda 2 da Revisão G, Homologação (24/11/2026): HOM-132 (Tipo de fornecedor × Responsável: usuário, vários ou
 * grupo), HOM-138 (atribuição automática pelo tipo, mantendo a manual) e HOM-140 (avisos para todos os responsáveis).
 * É a mesma estrutura da 361 (Responsáveis e avisos por regra) com a área HOMOLOGACAO em classes/ResponsaveisRegras.php:
 *   - menu "Responsáveis e avisos (Homologação de fornecedores)" (RESP_REGRAS_HOMOLOGACAO) ao lado da Gestão de fornecedores;
 *   - restrição de resp_regras com HOMOLOGACAO e LEGAL; sp_resp_regras_avisar com os textos da Homologação;
 *   - views refeitas: as regras entram na vw_responsabilidade_efetiva (ENTIDADE fo_fornecedores, módulo HOMOLOGACAO) e, por ela,
 *     em Meus fornecedores, Meus funcionários, pendências, agenda e no acesso do usuário interno restrito;
 *   - avisos de prazo (próxima avaliação, validade da homologação por tipo, documentos em até 30 dias) para atribuições e regras.
 * Este script só chama o da 361 (mesmas opções: --simular, --banco=, --remover).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-406-responsaveis-homologacao.php [--simular] [--banco=gcrown_dbp]
 */
require __DIR__ . '/aplicar-migracao-361-responsaveis-regras.php';
