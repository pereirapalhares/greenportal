<?php
declare(strict_types=1);

/* Migração 245 — ajuda da transação homologacaofornecedores_criteriosfuncionarios (botão "?" e base do assistente). */

return [
    'titulo' => ['pt' => 'Ajuda — Critérios dos funcionários', 'en' => 'Help — Employee criteria', 'es' => 'Ayuda — Criterios de los empleados'],
    'texto' => [
        'pt' => '<p>Cadastre os <strong>critérios exigidos dos funcionários dos fornecedores</strong>: documentos e comprovações como ASO, NR-35, NR-10, CNH ou certificado de capacitação.</p>'
            . '<ul><li>Clique em <strong>+</strong>, descreva o <strong>Critério</strong>, escolha o <strong>Tipo de documento</strong> (Competência ou Regularização de Ordem Legal) e clique em Salvar.</li>'
            . '<li>Para alterar, edite a linha e clique em Salvar. A alteração vale para todos os funcionários e tipos de funcionário que usam o critério.</li>'
            . '<li>A coluna <strong>Em uso</strong> mostra em quantos vínculos o critério aparece (tipos de funcionário e funcionários). Critério em uso não pode ser excluído.</li>'
            . '<li>Use os filtros das colunas para localizar um critério e <strong>Exportar</strong> para gerar a planilha.</li></ul>'
            . '<p>O que cada perfil pode fazer (ver, incluir, alterar, excluir, exportar) é definido em Segurança › Permissões, na transação "Critérios dos funcionários".</p>',
        'en' => '<p>Register the <strong>criteria required from supplier employees</strong>: documents and proofs such as medical certificate (ASO), NR-35, NR-10, driver\'s license or training certificate.</p>'
            . '<ul><li>Click <strong>+</strong>, describe the <strong>Criterion</strong>, choose the <strong>Document type</strong> (Competence or Legal Regularization) and click Save.</li>'
            . '<li>To change it, edit the row and click Save. The change applies to every employee and employee type that uses the criterion.</li>'
            . '<li>The <strong>In use</strong> column shows in how many links the criterion appears (employee types and employees). A criterion in use cannot be deleted.</li>'
            . '<li>Use the column filters to find a criterion and <strong>Export</strong> to generate the spreadsheet.</li></ul>'
            . '<p>What each profile can do (view, add, edit, delete, export) is defined in Security › Permissions, in the "Employee criteria" transaction.</p>',
        'es' => '<p>Registre los <strong>criterios exigidos a los empleados de los proveedores</strong>: documentos y comprobantes como ASO, NR-35, NR-10, licencia de conducir o certificado de capacitación.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, describa el <strong>Criterio</strong>, elija el <strong>Tipo de documento</strong> (Competencia o Regularización de Orden Legal) y haga clic en Guardar.</li>'
            . '<li>Para modificarlo, edite la fila y haga clic en Guardar. El cambio vale para todos los empleados y tipos de empleado que usan el criterio.</li>'
            . '<li>La columna <strong>En uso</strong> muestra en cuántos vínculos aparece el criterio (tipos de empleado y empleados). Un criterio en uso no puede eliminarse.</li>'
            . '<li>Use los filtros de las columnas para encontrar un criterio y <strong>Exportar</strong> para generar la planilla.</li></ul>'
            . '<p>Lo que cada perfil puede hacer (ver, incluir, modificar, eliminar, exportar) se define en Seguridad › Permisos, en la transacción "Criterios de los empleados".</p>',
    ],
];
