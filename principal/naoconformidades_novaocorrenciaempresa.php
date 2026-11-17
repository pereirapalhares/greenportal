<?php
declare(strict_types=1);
// Não Conformidades › Nova ocorrência da empresa (menu NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA).
// c491: abre a tela única da ocorrência (naoconformidades_ocorrencia.php, migração 321), que valida as permissões.
$codigo = filter_var($_GET['codigo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
header('Location: naoconformidades_ocorrencia.php' . ($codigo ? '?codigo=' . $codigo : '?tipo=3'), true, 302);
exit;
