<?php
declare(strict_types=1);

/*
 * Migração 335 — Não Conformidades › Administração em menus de 2º nível (22/10/2026, pedido do usuário).
 * Só o catálogo de menus (universal, replicado em todas as empresas); os códigos dos menus não mudam, então as permissões continuam.
 *   Administração
 *     Ocorrências            → Correção de ocorrência, Transferência de responsabilidades, Painel de saneamento
 *     Listas e participantes → Listas de distribuição, Participantes externos
 *     Cadastros gerais       → Classificação, Classificação de fornecedores, Tipos de ocorrência, Origens, Sistemas de gestão, Setores, Tipos de causa
 *     Cadastros do cliente   → Clientes, Unidades do cliente, Produtos, Tipos de documento, Tipos de reclamação, Tipos de tampa, Tamanhos da lata
 * Regra em classes/NaoConformidadeMenus.php (também usada pelas migrações 321 e 333). Pode ser executada mais de uma vez.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-335-nc-administracao-menus.php [--simular]
 * Depois: sair e entrar no portal para ver o menu novo.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeMenus.php';

$simular = in_array('--simular', $argv ?? [], true);
$rel = ['migracao' => '335', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try { $rel += NaoConformidadeMenus::organizar($simular); $rel['ok'] = !isset($rel['erro']); }
catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_335_nc_administracao_menus' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
