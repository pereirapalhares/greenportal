<?php
declare(strict_types=1);

// Migração 355 — Ajuda da tela Resultado da análise de IA (trecho entre <!-- ml355 -->): aprovar escolhendo o destino.
return [
    'ia_potenciais_resultado' => [
        'titulo' => ['pt' => 'Resultado da análise de IA', 'en' => 'AI analysis result', 'es' => 'Resultado del análisis de IA'],
        'texto' => [
            'pt' => '<h6><strong>Aprovar licenças e documentos: vincular ao que já existe</strong></h6>'
                . '<p>Nas abas <strong>Licenças</strong> e <strong>Documentos</strong>, o botão Aprovar abre uma janela para escolher o destino do potencial:</p>'
                . '<ul>'
                . '<li><strong>Licenças — Vincular a licenças existentes:</strong> a IA identifica o tipo (ex.: "Licença Ambiental"), mas a empresa pode ter várias licenças desse tipo. Marque uma ou várias licenças já cadastradas; o requisito passa a ser atendido por todas elas. A lista mostra as licenças da unidade do requisito (marque "Todas as unidades" para ver as demais) e pode ser filtrada pelo tipo documental.</li>'
                . '<li><strong>Licenças — Criar nova licença:</strong> cria a licença com o título sugerido pela IA, já com o tipo documental escolhido, e vincula ao requisito.</li>'
                . '<li><strong>Documentos — Vincular a documento existente:</strong> um mesmo procedimento pode atender vários controles (ex.: o procedimento de homologação de fornecedores cobre transporte, resíduos e dedetização). Escolha o documento já cadastrado e, se quiser, marque também os outros potenciais sugeridos desta aba: todos são aprovados apontando para o mesmo documento, sem duplicar o arquivo.</li>'
                . '<li><strong>Documentos — Criar novo documento:</strong> cria o documento de controle (código IA-n) e vincula ao requisito. Os outros potenciais marcados apontam para esse mesmo documento novo.</li>'
                . '</ul>'
                . '<p>Se o requisito já estava vinculado ao registro escolhido, o vínculo não é duplicado. Cada destino fica registrado (quem aprovou, quando, se foi novo ou existente) e o vínculo do documento de controle aparece em Documentos compartilhados com origem "confirmado pela IA". Monitoramento, treinamento e infraestrutura continuam criando um registro novo na aprovação.</p>',
            'en' => '<h6><strong>Approving licenses and documents: link to what already exists</strong></h6>'
                . '<p>In the <strong>Licenses</strong> and <strong>Documents</strong> tabs, the Approve button opens a window to choose where the potential goes:</p>'
                . '<ul>'
                . '<li><strong>Licenses — Link to existing licenses:</strong> the AI identifies the type (e.g. "Environmental License"), but the company may hold several licenses of that type. Tick one or more existing licenses; the requirement is then covered by all of them. The list shows the licenses of the requirement\'s unit (tick "All units" to see the others) and can be filtered by document type.</li>'
                . '<li><strong>Licenses — Create new license:</strong> creates the license with the title suggested by the AI, already with the chosen document type, and links it to the requirement.</li>'
                . '<li><strong>Documents — Link to an existing document:</strong> one procedure may cover several controls (e.g. the supplier approval procedure covers transport, waste and pest control). Choose the existing document and, if you wish, also tick the other suggested potentials in this tab: all of them are approved pointing to the same document, without duplicating the file.</li>'
                . '<li><strong>Documents — Create new document:</strong> creates the control document (code IA-n) and links it to the requirement. The other ticked potentials point to this same new document.</li>'
                . '</ul>'
                . '<p>If the requirement was already linked to the chosen record, the link is not duplicated. Each destination is recorded (who approved, when, new or existing) and the control document link appears in Shared documents with origin "confirmed by AI". Monitoring, training and infrastructure still create a new record on approval.</p>',
            'es' => '<h6><strong>Aprobar licencias y documentos: vincular a lo que ya existe</strong></h6>'
                . '<p>En las pestañas <strong>Licencias</strong> y <strong>Documentos</strong>, el botón Aprobar abre una ventana para elegir el destino del potencial:</p>'
                . '<ul>'
                . '<li><strong>Licencias — Vincular a licencias existentes:</strong> la IA identifica el tipo (p. ej. "Licencia Ambiental"), pero la empresa puede tener varias licencias de ese tipo. Marque una o varias licencias ya registradas; el requisito pasa a ser atendido por todas ellas. La lista muestra las licencias de la unidad del requisito (marque "Todas las unidades" para ver las demás) y puede filtrarse por tipo documental.</li>'
                . '<li><strong>Licencias — Crear nueva licencia:</strong> crea la licencia con el título sugerido por la IA, ya con el tipo documental elegido, y la vincula al requisito.</li>'
                . '<li><strong>Documentos — Vincular a un documento existente:</strong> un mismo procedimiento puede atender varios controles (p. ej. el procedimiento de homologación de proveedores cubre transporte, residuos y fumigación). Elija el documento ya registrado y, si quiere, marque también los otros potenciales sugeridos de esta pestaña: todos se aprueban apuntando al mismo documento, sin duplicar el archivo.</li>'
                . '<li><strong>Documentos — Crear nuevo documento:</strong> crea el documento de control (código IA-n) y lo vincula al requisito. Los otros potenciales marcados apuntan a ese mismo documento nuevo.</li>'
                . '</ul>'
                . '<p>Si el requisito ya estaba vinculado al registro elegido, el vínculo no se duplica. Cada destino queda registrado (quién aprobó, cuándo, nuevo o existente) y el vínculo del documento de control aparece en Documentos compartidos con origen "confirmado por la IA". Monitoreo, capacitación e infraestructura siguen creando un registro nuevo al aprobar.</p>',
        ],
    ],
];
