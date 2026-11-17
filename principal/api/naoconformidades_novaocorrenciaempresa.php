<?php
declare(strict_types=1);
// c491: substituída — a gravação das ocorrências passou para naoconformidades_ocorrencia.php?acao=... (procedures sp_nc_*, migração 321).
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode(['ok' => false, 'message' => 'Esta tela foi atualizada. Recarregue e use a nova tela da ocorrência.'], JSON_UNESCAPED_UNICODE);
