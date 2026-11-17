<?php
require_once __DIR__ . '/naoconformidade_pdftemplate_base.php';

// PDF da não conformidade do tipo Acidente/Incidente (tipo 4, migração 331: fluxo da Empresa).
function renderNaoConformidadeAcidentePdfTemplate(array $data): string
{
    return renderNaoConformidadePdfBase($data, 'Acidente/Incidente', [
        'Responsável pelo prazo de análise' => 'CODUSUARIO_PRAZO_ANALISE',
        'Data de criação' => 'DATA_DE_CRIACAO',
    ]);
}

if (isset($data) && is_array($data)) echo renderNaoConformidadeAcidentePdfTemplate($data);
