<?php
declare(strict_types=1);
// Não Conformidades › Nova ocorrência de acidente/incidente (menu NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE, migração 331).
// Tipo 4 com o fluxo da Empresa. Abre a tela única da ocorrência (naoconformidades_ocorrencia.php), que valida as permissões.
$codigo = filter_var($_GET['codigo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
header('Location: naoconformidades_ocorrencia.php' . ($codigo ? '?codigo=' . $codigo : '?tipo=4'), true, 302);
exit;
