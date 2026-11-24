<?php
declare(strict_types=1);

/*
 * Teste da gravação em Access (24/11/2026, Atualização do Access): copia um .mdb para storage/access/tmp e, NA CÓPIA,
 * inclui uma lei fictícia (texto longo com acento e apóstrofo), grava o controle, altera e apaga. O original não é aberto.
 * Uso: php scripts\teste-access-gravacao.php "E:\caminho\arquivo.mdb"      Temporário: apague depois.
 */

require_once dirname(__DIR__) . '/classes/AccessAtualizacao.php';
$orig = (string)($argv[1] ?? '');
if ($orig === '' || !is_file($orig)) exit("informe o caminho de um .mdb\n");
$copia = AccessAtualizacao::pasta('tmp') . DIRECTORY_SEPARATOR . 'teste_' . date('His') . '.mdb';
copy($orig, $copia);
try { $r = ['ok' => true] + AccessAtualizacao::testarGravacao($copia); }
catch (Throwable $e) { $r = ['ok' => false, 'erro' => $e->getMessage()]; }
@unlink($copia);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
