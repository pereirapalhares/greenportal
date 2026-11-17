<?php
declare(strict_types=1);
// Não Conformidades › Nova ocorrência de planejamento (menu NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO, migração 326).
// Abre a tela única da ocorrência (naoconformidades_ocorrencia.php), que valida as permissões.
$codigo = filter_var($_GET['codigo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
header('Location: naoconformidades_ocorrencia.php' . ($codigo ? '?codigo=' . $codigo : '?tipo=5'), true, 302);
exit;
