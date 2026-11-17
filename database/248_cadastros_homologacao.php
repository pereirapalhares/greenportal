<?php
declare(strict_types=1);

/*
 * Migração 248 — cadastros simples de Homologação de Fornecedores (uma tabela, uma descrição).
 * Cada item gera: view do grid, validação de exclusão (procedure + gatilho), procedures de comparação e migração
 * (database/248_homologacao_cadastro_simples.sql), menu abaixo de Homologação de Fornecedores e ajuda da tela.
 * Legado: banco hpr_configuracoes (configuracoes_transacoes / configuracoes_grids).
 */

return [
    'fo_tipotreinamentos' => [
        'legado' => 'foTiposTreinamentos (hpr_configuracoes, codtransacao 145)',
        'pk' => 'CODTIPOTREINAMENTO',
        'dsc' => 'DSCTIPO',
        'view' => 'vw_homologacao_tipos_treinamentos_grid',
        'rotulo' => 'Tipo de treinamento',
        'menu' => ['codigo' => 'HOMOLOGACAO_TIPOS_TREINAMENTOS', 'transacao' => 'homologacaofornecedores_tipostreinamentos',
            'pt' => 'Tipos de treinamento', 'en' => 'Training types', 'es' => 'Tipos de capacitación', 'icone' => 'bi-mortarboard'],
        // Nome legível das tabelas que usam o registro (mensagem de exclusão bloqueada)
        'usos' => [
            'fo_tipofuncionariosxtipotreinamentos' => 'tipos de funcionário',
            'fo_funcionarioxtiposxtreinamentos' => 'treinamentos dos funcionários',
            'fo_tipofuncxcriteriofuncxtreinamento' => 'critérios por tipo de funcionário',
        ],
        'ajuda' => [
            'titulo' => ['pt' => 'Ajuda — Tipos de treinamento', 'en' => 'Help — Training types', 'es' => 'Ayuda — Tipos de capacitación'],
            'texto' => [
                'pt' => '<p>Cadastre os <strong>tipos de treinamento</strong> exigidos dos funcionários dos fornecedores (por exemplo, "NR-35 Trabalho em altura", "NR-10 Eletricidade", "Integração de segurança").</p>'
                    . '<ul><li>Clique em <strong>+</strong>, informe o <strong>Tipo de treinamento</strong> e clique em Salvar.</li>'
                    . '<li>Para alterar, edite a linha e clique em Salvar. A alteração vale para todos os tipos de funcionário e funcionários que usam o treinamento.</li>'
                    . '<li>A coluna <strong>Em uso</strong> mostra em quantos vínculos o tipo aparece. Tipo em uso não pode ser excluído.</li>'
                    . '<li>Use o filtro da coluna para localizar um tipo e <strong>Exportar</strong> para gerar a planilha.</li></ul>'
                    . '<p>Os treinamentos exigidos de cada função são definidos em Homologação de Fornecedores › Tipos de Funcionários. O que cada perfil pode fazer é definido em Segurança › Permissões.</p>',
                'en' => '<p>Register the <strong>training types</strong> required from supplier employees (for example, "NR-35 Work at height", "NR-10 Electricity", "Safety induction").</p>'
                    . '<ul><li>Click <strong>+</strong>, enter the <strong>Training type</strong> and click Save.</li>'
                    . '<li>To change it, edit the row and click Save. The change applies to every employee type and employee that uses the training.</li>'
                    . '<li>The <strong>In use</strong> column shows in how many links the type appears. A type in use cannot be deleted.</li>'
                    . '<li>Use the column filter to find a type and <strong>Export</strong> to generate the spreadsheet.</li></ul>'
                    . '<p>The training required for each role is defined in Supplier Approval › Employee Types. What each profile can do is defined in Security › Permissions.</p>',
                'es' => '<p>Registre los <strong>tipos de capacitación</strong> exigidos a los empleados de los proveedores (por ejemplo, "NR-35 Trabajo en altura", "NR-10 Electricidad", "Inducción de seguridad").</p>'
                    . '<ul><li>Haga clic en <strong>+</strong>, informe el <strong>Tipo de capacitación</strong> y haga clic en Guardar.</li>'
                    . '<li>Para modificarlo, edite la fila y haga clic en Guardar. El cambio vale para todos los tipos de empleado y empleados que usan la capacitación.</li>'
                    . '<li>La columna <strong>En uso</strong> muestra en cuántos vínculos aparece el tipo. Un tipo en uso no puede eliminarse.</li>'
                    . '<li>Use el filtro de la columna para encontrar un tipo y <strong>Exportar</strong> para generar la planilla.</li></ul>'
                    . '<p>Las capacitaciones exigidas de cada función se definen en Homologación de Proveedores › Tipos de Empleados. Lo que cada perfil puede hacer se define en Seguridad › Permisos.</p>',
            ],
        ],
    ],
    'fo_tipofuncionarios' => [
        'legado' => 'foTiposdeFuncionarios (hpr_configuracoes, codtransacao 142; abas 144 Critérios, 146 Treinamentos, 143 Palavras chave)',
        'pk' => 'CODTIPOFUNCIONARIO',
        'dsc' => 'DSCTIPO',
        'view' => 'vw_homologacao_tipos_funcionarios_cadastro_grid',
        'rotulo' => 'Tipo de funcionário',
        'menu' => ['codigo' => 'HOMOLOGACAO_TIPOS_FUNCIONARIOS', 'transacao' => 'homologacaofornecedores_tiposfuncionarios',
            'pt' => 'Tipos de Funcionários', 'en' => 'Employee types', 'es' => 'Tipos de empleados', 'icone' => 'bi-person-badge'],
        'usos' => [
            'fo_funcionarios' => 'funcionários',
            'fo_funcionariosxtipofuncionarios' => 'funções dos funcionários',
            'fo_funcionarioxtiposxcriterios' => 'critérios dos funcionários',
            'fo_funcionarioxtiposxtreinamentos' => 'treinamentos dos funcionários',
        ],
        // Migração 249: vínculos do próprio tipo (abas do legado). Não contam como uso e são excluídos junto com o tipo.
        'filhos' => [
            'fo_tipofuncionariosxcriteriofuncionarios' => 'codtipofuncionario',
            'fo_tipofuncionariosxtipotreinamentos' => 'codtipofuncionario',
            'fo_tipofuncionariosxpalavraschave' => 'codtipofuncionario',
        ],
        'ajuda' => [
            'titulo' => ['pt' => 'Ajuda — Tipos de Funcionários', 'en' => 'Help — Employee types', 'es' => 'Ayuda — Tipos de empleados'],
            'texto' => [
                'pt' => '<p>Cadastre os <strong>tipos de funcionário</strong> (funções) dos fornecedores e defina o que cada um exige.</p>'
                    . '<ul><li>Clique em <strong>+</strong>, informe o <strong>Tipo de funcionário</strong> e clique em Salvar. Para alterar, edite a linha e salve.</li>'
                    . '<li>Selecione uma linha e use os botões <strong>Critérios</strong>, <strong>Treinamentos</strong> e <strong>Palavras-chave</strong>. Em cada um há duas listas: à esquerda o que está disponível, à direita o que está vinculado ao tipo. Marque os itens e use as setas para vincular ou desvincular; a gravação é imediata.</li>'
                    . '<li>A coluna <strong>Em uso</strong> mostra quantos registros de funcionários usam o tipo. Tipo em uso não pode ser excluído; ao excluir um tipo sem uso, os vínculos dele também são removidos.</li>'
                    . '<li>Tipos com o nome <em>"Função não identificada (código N)"</em> vieram de funcionários do legado cuja função já não existia: renomeie-os para a função correta.</li></ul>'
                    . '<p>Os critérios e treinamentos são cadastrados em Homologação de Fornecedores › Critérios dos funcionários e › Tipos de treinamento. O que cada perfil pode fazer é definido em Segurança › Permissões.</p>',
                'en' => '<p>Register the suppliers\' <strong>employee types</strong> (roles) and define what each one requires.</p>'
                    . '<ul><li>Click <strong>+</strong>, enter the <strong>Employee type</strong> and click Save. To change it, edit the row and save.</li>'
                    . '<li>Select a row and use the <strong>Criteria</strong>, <strong>Training</strong> and <strong>Keywords</strong> buttons. Each one shows two lists: available items on the left, items linked to the type on the right. Check the items and use the arrows to link or unlink them; changes are saved immediately.</li>'
                    . '<li>The <strong>In use</strong> column shows how many employee records use the type. A type in use cannot be deleted; deleting an unused type also removes its links.</li>'
                    . '<li>Types named <em>"Função não identificada (código N)"</em> came from legacy employees whose role no longer existed: rename them to the correct role.</li></ul>'
                    . '<p>Criteria and training types are registered in Supplier Approval › Employee criteria and › Training types. What each profile can do is defined in Security › Permissions.</p>',
                'es' => '<p>Registre los <strong>tipos de empleado</strong> (funciones) de los proveedores y defina lo que cada uno exige.</p>'
                    . '<ul><li>Haga clic en <strong>+</strong>, informe el <strong>Tipo de empleado</strong> y haga clic en Guardar. Para modificarlo, edite la fila y guarde.</li>'
                    . '<li>Seleccione una fila y use los botones <strong>Criterios</strong>, <strong>Capacitaciones</strong> y <strong>Palabras clave</strong>. Cada uno muestra dos listas: a la izquierda lo disponible, a la derecha lo vinculado al tipo. Marque los ítems y use las flechas para vincular o desvincular; el cambio se guarda de inmediato.</li>'
                    . '<li>La columna <strong>En uso</strong> muestra cuántos registros de empleados usan el tipo. Un tipo en uso no puede eliminarse; al eliminar un tipo sin uso, sus vínculos también se eliminan.</li>'
                    . '<li>Los tipos llamados <em>"Função não identificada (código N)"</em> vinieron de empleados del sistema anterior cuya función ya no existía: cámbieles el nombre por la función correcta.</li></ul>'
                    . '<p>Los criterios y las capacitaciones se registran en Homologación de Proveedores › Criterios de los empleados y › Tipos de capacitación. Lo que cada perfil puede hacer se define en Seguridad › Permisos.</p>',
            ],
        ],
    ],

    // Migração 292 — Tipos e Critérios dos Fornecedores (legado foTiposdeFornecedor 138, abas 139/141; critérios: configuracoes/fo_criteriofornecedores.php).
    'fo_criteriofornecedores' => [
        'legado' => 'configuracoes/fo_criteriofornecedores.php (cadastros_c.php?tabela=fo_criteriofornecedores)',
        'pk' => 'CODCRITERIOFORNECEDOR',
        'dsc' => 'DSCRITERIO',
        'view' => 'vw_homologacao_criterios_fornecedores_grid',
        'rotulo' => 'Critério do fornecedor',
        'menu' => ['codigo' => 'HOMOLOGACAO_CRITERIOS_FORNECEDORES', 'transacao' => 'homologacaofornecedores_criteriosfornecedores',
            'pt' => 'Critérios dos Fornecedores', 'en' => 'Supplier criteria', 'es' => 'Criterios de los proveedores', 'icone' => 'bi-file-earmark-check'],
        'extras' => [
            'DSCRITERIO_EN' => 'c.`DSCRITERIO_EN`',
            'QTD_TIPOS' => '(SELECT COUNT(*) FROM `fo_tipofornecedoresxcriteriofornecedores` v WHERE v.`codcriteriofornecedor` = c.`codcriteriofornecedor`)',
        ],
        'refs_extra' => ['fo_fornecedorxcriterios' => 'codcriterio'],
        'usos' => [
            'fo_tipofornecedoresxcriteriofornecedores' => 'tipos de fornecedor',
            'fo_fornecedorxcriterios' => 'documentos dos fornecedores',
            'fo_fornecedorxcriterios_historico' => 'histórico de documentos',
        ],
        'ajuda' => [
            'titulo' => ['pt' => 'Ajuda — Critérios dos Fornecedores', 'en' => 'Help — Supplier criteria', 'es' => 'Ayuda — Criterios de los proveedores'],
            'texto' => [
                'pt' => '<p>Cadastre os <strong>critérios</strong> (documentos) que podem ser exigidos dos fornecedores, por exemplo "Alvará de funcionamento", "CND federal", "Licença de operação".</p>'
                    . '<ul><li>Clique em <strong>+</strong>, informe o <strong>Critério</strong> e, se quiser, o texto em inglês, e clique em Salvar.</li>'
                    . '<li>A coluna <strong>Tipos</strong> mostra em quantos tipos de fornecedor o critério é exigido. Os critérios de cada tipo, com o nível de exigência, são definidos em Homologação de Fornecedores › Tipos de Fornecedores.</li>'
                    . '<li>A coluna <strong>Em uso</strong> soma os tipos e os documentos de fornecedores que usam o critério. Critério em uso não pode ser excluído: retire-o dos tipos antes.</li>'
                    . '<li>Alterar o texto vale para todos os tipos e documentos que usam o critério.</li></ul>'
                    . '<p>O que cada perfil pode fazer é definido em Segurança › Permissões.</p>',
                'en' => '<p>Register the <strong>criteria</strong> (documents) that can be required from suppliers, for example "Operating permit", "Federal tax clearance", "Operating license".</p>'
                    . '<ul><li>Click <strong>+</strong>, enter the <strong>Criterion</strong> and, optionally, the English text, and click Save.</li>'
                    . '<li>The <strong>Types</strong> column shows how many supplier types require the criterion. The criteria of each type, with the requirement level, are defined in Supplier Approval › Supplier Types.</li>'
                    . '<li>The <strong>In use</strong> column adds up the types and supplier documents that use the criterion. A criterion in use cannot be deleted: remove it from the types first.</li>'
                    . '<li>Changing the text applies to every type and document that uses the criterion.</li></ul>'
                    . '<p>What each profile can do is defined in Security › Permissions.</p>',
                'es' => '<p>Registre los <strong>criterios</strong> (documentos) que pueden exigirse a los proveedores, por ejemplo "Licencia de funcionamiento", "Certificado de regularidad fiscal", "Licencia de operación".</p>'
                    . '<ul><li>Haga clic en <strong>+</strong>, informe el <strong>Criterio</strong> y, si desea, el texto en inglés, y haga clic en Guardar.</li>'
                    . '<li>La columna <strong>Tipos</strong> muestra en cuántos tipos de proveedor se exige el criterio. Los criterios de cada tipo, con el nivel de exigencia, se definen en Homologación de Proveedores › Tipos de Proveedores.</li>'
                    . '<li>La columna <strong>En uso</strong> suma los tipos y los documentos de proveedores que usan el criterio. Un criterio en uso no puede eliminarse: quítelo de los tipos antes.</li>'
                    . '<li>Cambiar el texto vale para todos los tipos y documentos que usan el criterio.</li></ul>'
                    . '<p>Lo que cada perfil puede hacer se define en Seguridad › Permisos.</p>',
            ],
        ],
    ],
    'fo_tipofornecedores' => [
        'legado' => 'foTiposdeFornecedor (hpr_configuracoes, codtransacao 138; abas 139 Critérios com 140 Nível de exigência, 141 Palavras chave)',
        'pk' => 'CODTIPOFORNECEDOR',
        'dsc' => 'DSCTIPO',
        'view' => 'vw_homologacao_tipos_fornecedores_cadastro_grid',
        'rotulo' => 'Tipo de fornecedor',
        'menu' => ['codigo' => 'HOMOLOGACAO_TIPOS_FORNECEDORES', 'transacao' => 'homologacaofornecedores_tiposfornecedores',
            'pt' => 'Tipos de Fornecedores', 'en' => 'Supplier types', 'es' => 'Tipos de proveedores', 'icone' => 'bi-diagram-3'],
        'extras' => [
            'NECESSITA_AUDITORIA' => 'c.`necessita_auditoria`',
            'QTD_CRITERIOS' => '(SELECT COUNT(*) FROM `fo_tipofornecedoresxcriteriofornecedores` v WHERE v.`codtipofornecedor` = c.`codtipofornecedor`)',
            'QTD_OBRIGATORIOS' => "(SELECT COUNT(*) FROM `fo_tipofornecedoresxcriteriofornecedores` v WHERE v.`codtipofornecedor` = c.`codtipofornecedor` AND v.`nivel_de_exigencia` = 'Obrigatório')",
            'QTD_PALAVRASCHAVE' => '(SELECT COUNT(*) FROM `fo_tipofornecedoresxpalavraschave` v WHERE v.`codtipofornecedor` = c.`codtipofornecedor`)',
            'QTD_FORNECEDORES' => '(SELECT COUNT(DISTINCT v.`codfornecedor`) FROM `fo_fornecedoresxtipofornecedores` v WHERE v.`codtipofornecedor` = c.`codtipofornecedor`)',
        ],
        'refs_extra' => ['fo_fornecedorxcriterios' => 'codtipo'],
        'usos' => [
            'fo_fornecedoresxtipofornecedores' => 'fornecedores',
            'fo_fornecedorxcriterios' => 'documentos dos fornecedores',
            'fo_fornecedorxcriterios_historico' => 'histórico de documentos',
            'fo_fornecedores' => 'fornecedores (tipo antigo)',
        ],
        // Vínculos do próprio tipo (abas do legado): não contam como uso e são excluídos junto com o tipo.
        'filhos' => [
            'fo_tipofornecedoresxcriteriofornecedores' => 'codtipofornecedor',
            'fo_tipofornecedoresxpalavraschave' => 'codtipofornecedor',
        ],
        'ajuda' => [
            'titulo' => ['pt' => 'Ajuda — Tipos de Fornecedores', 'en' => 'Help — Supplier types', 'es' => 'Ayuda — Tipos de proveedores'],
            'texto' => [
                'pt' => '<p>Cadastre os <strong>tipos de fornecedor</strong> (por exemplo "Destinação de resíduos", "Fornecimento de EPI") e defina o que cada um exige.</p>'
                    . '<ul><li>Clique em <strong>+</strong>, informe o <strong>Tipo de fornecedor</strong> e se ele <strong>necessita de auditoria</strong>, e clique em Salvar.</li>'
                    . '<li>Selecione uma linha e use o botão <strong>Critérios</strong>: à esquerda ficam os critérios disponíveis, à direita os exigidos do tipo. Marque e use as setas para vincular ou desvincular. Em cada critério vinculado escolha o <strong>nível de exigência</strong> (Obrigatório ou Desejável). A gravação é imediata.</li>'
                    . '<li>O botão <strong>Palavras-chave</strong> associa palavras do catálogo universal ao tipo, para facilitar a busca e a classificação.</li>'
                    . '<li>Os documentos de cada fornecedor são gerados a partir dos critérios dos tipos dele (Gestão de Fornecedores › Documentos). Tipos com <em>Necessita de auditoria = Sim</em> aparecem na aba Auditoria de homologação.</li>'
                    . '<li>A coluna <strong>Em uso</strong> mostra quantos fornecedores e documentos usam o tipo. Tipo em uso não pode ser excluído; ao excluir um tipo sem uso, os critérios e palavras-chave dele também são removidos.</li></ul>'
                    . '<p>Os critérios são cadastrados em Homologação de Fornecedores › Critérios dos Fornecedores. O que cada perfil pode fazer é definido em Segurança › Permissões.</p>',
                'en' => '<p>Register the <strong>supplier types</strong> (for example "Waste disposal", "PPE supply") and define what each one requires.</p>'
                    . '<ul><li>Click <strong>+</strong>, enter the <strong>Supplier type</strong> and whether it <strong>requires an audit</strong>, and click Save.</li>'
                    . '<li>Select a row and use the <strong>Criteria</strong> button: available criteria are on the left, the ones required by the type on the right. Check them and use the arrows to link or unlink. For each linked criterion choose the <strong>requirement level</strong> (Mandatory or Desirable). Changes are saved immediately.</li>'
                    . '<li>The <strong>Keywords</strong> button links words from the universal catalog to the type, to help search and classification.</li>'
                    . '<li>Each supplier\'s documents are generated from the criteria of its types (Supplier Management › Documents). Types with <em>Requires audit = Yes</em> appear in the Approval audit tab.</li>'
                    . '<li>The <strong>In use</strong> column shows how many suppliers and documents use the type. A type in use cannot be deleted; deleting an unused type also removes its criteria and keywords.</li></ul>'
                    . '<p>Criteria are registered in Supplier Approval › Supplier criteria. What each profile can do is defined in Security › Permissions.</p>',
                'es' => '<p>Registre los <strong>tipos de proveedor</strong> (por ejemplo "Destino de residuos", "Suministro de EPP") y defina lo que cada uno exige.</p>'
                    . '<ul><li>Haga clic en <strong>+</strong>, informe el <strong>Tipo de proveedor</strong> y si <strong>necesita auditoría</strong>, y haga clic en Guardar.</li>'
                    . '<li>Seleccione una fila y use el botón <strong>Criterios</strong>: a la izquierda los criterios disponibles, a la derecha los exigidos por el tipo. Márquelos y use las flechas para vincular o desvincular. En cada criterio vinculado elija el <strong>nivel de exigencia</strong> (Obligatorio o Deseable). El cambio se guarda de inmediato.</li>'
                    . '<li>El botón <strong>Palabras clave</strong> asocia palabras del catálogo universal al tipo, para facilitar la búsqueda y la clasificación.</li>'
                    . '<li>Los documentos de cada proveedor se generan a partir de los criterios de sus tipos (Gestión de Proveedores › Documentos). Los tipos con <em>Necesita auditoría = Sí</em> aparecen en la pestaña Auditoría de homologación.</li>'
                    . '<li>La columna <strong>En uso</strong> muestra cuántos proveedores y documentos usan el tipo. Un tipo en uso no puede eliminarse; al eliminar un tipo sin uso, sus criterios y palabras clave también se eliminan.</li></ul>'
                    . '<p>Los criterios se registran en Homologación de Proveedores › Criterios de los proveedores. Lo que cada perfil puede hacer se define en Seguridad › Permisos.</p>',
            ],
        ],
    ],
];
