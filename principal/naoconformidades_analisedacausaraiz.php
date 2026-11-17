<?php
declare(strict_types=1);
// c491: substituída pela tela única da ocorrência (naoconformidades_ocorrencia.php, migração 321). Mantida para os links antigos
// (Painel lateral, favoritos), que passam a abrir a ocorrência na aba "analise".
$codigo = filter_var($_GET['codigo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
header('Location: naoconformidades_ocorrencia.php' . ($codigo ? '?codigo=' . $codigo . '&aba=analise' : '?tipo=3'), true, 302);
exit;
