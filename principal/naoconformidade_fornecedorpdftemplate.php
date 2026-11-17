<?php
require_once __DIR__ . '/naoconformidade_pdftemplate_base.php';

function renderNaoConformidadeFornecedorPdfTemplate(array $data): string
{
    return renderNaoConformidadePdfBase($data, 'Do Fornecedor', [
        'Fornecedor' => 'RAZAOSOCIAL',
        'Produto/Rótulo' => 'CODIGODOPRODUTO',
        'Quantidade envolvida' => 'QTDENVOLVIDA',
        'Quantidade devolvida' => 'QTDDEVOLVIDA',
        'Tamanho do lote' => 'TAMANHOLOTEENVOLVIDO',
        'Tamanho da lata' => 'codtamanhodalata',
        'Tipo de tampa' => 'codtipodetampa',
        'Tipo de reclamação' => 'codtipodereclamacao',
        'Afeta segurança de alimentos' => 'afetasegurancaalimentos',
        'Houve retenção de produto' => 'houve_retencao_produto',
        'Haverá devolução de material' => 'havera_devolucao_material',
        'Parada de linha' => 'PARADANALINHA',
    ]);
}

if (isset($data) && is_array($data)) echo renderNaoConformidadeFornecedorPdfTemplate($data);
