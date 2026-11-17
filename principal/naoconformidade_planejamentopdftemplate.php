<?php
require_once __DIR__ . '/naoconformidade_pdftemplate_base.php';

function renderNaoConformidadePlanejamentoPdfTemplate(array $data): string
{
    return renderNaoConformidadePdfBase($data, 'Planejamento', [
        'Passa pela análise da eficácia' => 'pass_pela_nanalise_eficacia',
        'Abrir nova avaliação de mudança' => 'abrir_nova_avaliacao_de_mudanca',
        'Atualizar relação de riscos e oportunidades' => 'atualizar_relacao_de_riscos_e_oportunidades',
        'Responsável pelo prazo de análise' => 'CODUSUARIO_PRAZO_ANALISE',
        'Data de criação' => 'DATA_DE_CRIACAO',
    ]);
}

if (isset($data) && is_array($data)) echo renderNaoConformidadePlanejamentoPdfTemplate($data);
