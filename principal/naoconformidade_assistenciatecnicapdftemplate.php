<?php
require_once __DIR__ . '/naoconformidade_pdftemplate_base.php';

function renderNaoConformidadeAssistenciaTecnicaPdfTemplate(array $data): string
{
    return renderNaoConformidadePdfBase($data, 'Assistência Técnica', [
        'Cliente' => 'DSCCLIENTE',
        'Fornecedor' => 'CODFORNECEDOR',
        'Produto' => 'PRODUTO',
        'Código/identificação do produto' => 'CODIGODOPRODUTO',
        'Lote envolvido' => 'TAMANHOLOTEENVOLVIDO',
        'Quantidade envolvida' => 'QTDENVOLVIDA',
        'Quantidade devolvida' => 'QTDDEVOLVIDA',
        'Passa pela análise da eficácia' => 'pass_pela_nanalise_eficacia',
        'Responsável pelo prazo de análise' => 'CODUSUARIO_PRAZO_ANALISE',
    ]);
}

if (isset($data) && is_array($data)) echo renderNaoConformidadeAssistenciaTecnicaPdfTemplate($data);
