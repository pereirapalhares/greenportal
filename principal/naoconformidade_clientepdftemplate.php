<?php
require_once __DIR__ . '/naoconformidade_pdftemplate_base.php';

// Migração 327 (c510): descrições das listas do Cliente (vw_nc_ocorrencia) no lugar dos códigos.
function renderNaoConformidadeClientePdfTemplate(array $data): string
{
    return renderNaoConformidadePdfBase($data, 'Do Cliente', [
        'Cliente' => 'DSCCLIENTE',
        'Unidade do cliente' => 'DSCUNIDADE_CLIENTE',
        'Produto/Rótulo' => 'DSCPRODUTO',
        'Tipo de documento' => 'DSCTIPODEDOCUMENTO',
        'Nº do documento' => 'nrndocumento',
        'Quantidade envolvida' => 'QTDENVOLVIDA',
        'Quantidade devolvida' => 'QTDDEVOLVIDA',
        'Tamanho da lata' => 'DSCTAMANHODALATA',
        'Tipo de tampa' => 'DSCTIPODETAMPA',
        'Tipo de reclamação' => 'DSCTIPORECLAMACAO',
        'Procedência' => 'procedencia_cliente',
        'Afeta segurança de alimentos' => 'afetasegurancaalimentos',
        'Improcedência' => 'improcedente',
    ]);
}

if (isset($data) && is_array($data)) echo renderNaoConformidadeClientePdfTemplate($data);
