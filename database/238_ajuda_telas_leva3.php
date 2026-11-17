<?php
declare(strict_types=1);

/*
 * Migração 238 — ajuda das telas (leva 3): levantamentos LAIA, LPRO e LAIS, seus cadastros auxiliares,
 * revisões e os três Correlacionamentos (Ambiental, Ocupacional, Social). Redigida a partir do código de cada tela.
 * Formato: transacao (nome do arquivo da tela) => [titulo pt/en/es, texto pt/en/es em HTML simples].
 */

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

// Cadastros auxiliares simples (uma descrição por linha). $o = [titulo, o que é, onde é usado] em pt/en/es.
$cadastro = static function (array $titulo, array $oque, array $uso, array $campo) use ($L): array {
    return [
        'titulo' => $L('Ajuda — ' . $titulo['pt'], 'Help — ' . $titulo['en'], 'Ayuda — ' . $titulo['es']),
        'texto' => $L(
            '<p>' . $oque['pt'] . ' ' . $uso['pt'] . '</p>'
            . '<ul><li>Clique em <strong>+</strong>, informe o campo <strong>' . $campo['pt'] . '</strong> e clique em Salvar.</li>'
            . '<li>Para alterar, edite o texto na linha e clique em Salvar. A alteração vale para todas as linhas dos levantamentos que usam este item.</li>'
            . '<li>Antes de excluir, verifique se o item não está em uso nos levantamentos.</li>'
            . '<li>Use os filtros das colunas para localizar um item e <strong>Exportar</strong> para gerar a planilha.</li></ul>',
            '<p>' . $oque['en'] . ' ' . $uso['en'] . '</p>'
            . '<ul><li>Click <strong>+</strong>, fill in the <strong>' . $campo['en'] . '</strong> field and click Save.</li>'
            . '<li>To change it, edit the text in the row and click Save. The change applies to every survey row that uses this item.</li>'
            . '<li>Before deleting, check that the item is not used in the surveys.</li>'
            . '<li>Use the column filters to find an item and <strong>Export</strong> to generate the spreadsheet.</li></ul>',
            '<p>' . $oque['es'] . ' ' . $uso['es'] . '</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, informe el campo <strong>' . $campo['es'] . '</strong> y haga clic en Guardar.</li>'
            . '<li>Para modificarlo, edite el texto en la fila y haga clic en Guardar. El cambio vale para todas las filas de los levantamientos que usan este ítem.</li>'
            . '<li>Antes de eliminar, verifique que el ítem no esté en uso en los levantamientos.</li>'
            . '<li>Use los filtros de las columnas para encontrar un ítem y <strong>Exportar</strong> para generar la planilla.</li></ul>'
        ),
    ];
};

// Revisões (descrição + data).
$revisao = static function (array $titulo, array $de) use ($L): array {
    return [
        'titulo' => $L('Ajuda — ' . $titulo['pt'], 'Help — ' . $titulo['en'], 'Ayuda — ' . $titulo['es']),
        'texto' => $L(
            '<p>Registre o histórico de <strong>revisões</strong> ' . $de['pt'] . ': o que foi revisto e quando.</p>'
            . '<ul><li>Clique em <strong>+</strong>, descreva a <strong>Revisão</strong> (por exemplo, "Revisão anual" ou "Inclusão da nova linha de pintura"), informe a <strong>Data</strong> e clique em Salvar.</li>'
            . '<li>Para corrigir, edite a linha e clique em Salvar.</li>'
            . '<li>Mantenha uma revisão para cada atualização relevante: ela é a evidência de que o levantamento é analisado periodicamente.</li></ul>',
            '<p>Record the history of <strong>reviews</strong> ' . $de['en'] . ': what was reviewed and when.</p>'
            . '<ul><li>Click <strong>+</strong>, describe the <strong>Review</strong> (for example, "Annual review" or "New painting line added"), enter the <strong>Date</strong> and click Save.</li>'
            . '<li>To correct it, edit the row and click Save.</li>'
            . '<li>Keep one review for each relevant update: it is the evidence that the survey is analyzed periodically.</li></ul>',
            '<p>Registre el historial de <strong>revisiones</strong> ' . $de['es'] . ': qué se revisó y cuándo.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, describa la <strong>Revisión</strong> (por ejemplo, "Revisión anual" o "Inclusión de la nueva línea de pintura"), informe la <strong>Fecha</strong> y haga clic en Guardar.</li>'
            . '<li>Para corregirla, edite la fila y haga clic en Guardar.</li>'
            . '<li>Mantenga una revisión para cada actualización relevante: es la evidencia de que el levantamiento se analiza periódicamente.</li></ul>'
        ),
    ];
};

// Correlacionamentos (consulta da legislação por unidade com botões de detalhe).
$correlacionamento = static function (array $titulo, array $tema, array $botoes) use ($L): array {
    return [
        'titulo' => $L('Ajuda — ' . $titulo['pt'], 'Help — ' . $titulo['en'], 'Ayuda — ' . $titulo['es']),
        'texto' => $L(
            '<p>Consulta (somente leitura) das <strong>legislações</strong> ligadas ' . $tema['pt'] . ', por unidade.</p>'
            . '<ul><li>Cada linha mostra a Unidade, o Tipo, Número e Ano da lei, o Âmbito, o Assunto, os Artigos, o Nível de controle e as áreas (Meio Ambiente, Segurança, Qualidade, Social).</li>'
            . '<li>Use os filtros das colunas para localizar uma lei ou ver tudo o que se aplica a uma unidade.</li>'
            . '<li>Selecione uma linha para habilitar os botões de <strong>Correlacionamentos</strong>: ' . $botoes['pt'] . '. Cada botão abre a lista dos itens ligados àquela lei na unidade selecionada.</li>'
            . '<li>Esta tela não inclui nem altera dados. Para tratar o atendimento da lei, use Módulo Legal › Requisitos legais.</li>'
            . '<li><strong>Exportar</strong> gera a planilha com os filtros aplicados.</li></ul>',
            '<p>Read-only view of the <strong>legislation</strong> linked ' . $tema['en'] . ', by unit.</p>'
            . '<ul><li>Each row shows the Unit, the law Type, Number and Year, the Scope, the Subject, the Articles, the Control level and the areas (Environment, Safety, Quality, Social).</li>'
            . '<li>Use the column filters to find a law or see everything that applies to a unit.</li>'
            . '<li>Select a row to enable the <strong>Correlations</strong> buttons: ' . $botoes['en'] . '. Each button opens the list of items linked to that law in the selected unit.</li>'
            . '<li>This screen does not add or change data. To handle compliance with the law, use Legal Module › Legal requirements.</li>'
            . '<li><strong>Export</strong> generates the spreadsheet with the filters applied.</li></ul>',
            '<p>Consulta (solo lectura) de las <strong>legislaciones</strong> vinculadas ' . $tema['es'] . ', por unidad.</p>'
            . '<ul><li>Cada fila muestra la Unidad, el Tipo, Número y Año de la ley, el Ámbito, el Asunto, los Artículos, el Nivel de control y las áreas (Medio Ambiente, Seguridad, Calidad, Social).</li>'
            . '<li>Use los filtros de las columnas para encontrar una ley o ver todo lo que se aplica a una unidad.</li>'
            . '<li>Seleccione una fila para habilitar los botones de <strong>Correlaciones</strong>: ' . $botoes['es'] . '. Cada botón abre la lista de ítems vinculados a esa ley en la unidad seleccionada.</li>'
            . '<li>Esta pantalla no incluye ni modifica datos. Para tratar el cumplimiento de la ley, use Módulo Legal › Requisitos legales.</li>'
            . '<li><strong>Exportar</strong> genera la planilla con los filtros aplicados.</li></ul>'
        ),
    ];
};

return [
    'modulolegal_laia' => [
        'titulo' => $L('Ajuda — LAIA', 'Help — LAIA', 'Ayuda — LAIA'),
        'texto' => $L(
            '<p>O <strong>LAIA</strong> (Levantamento de Aspectos e Impactos Ambientais) registra, por unidade, cada atividade, o aspecto ambiental que ela gera, o impacto e a avaliação de significância.</p>'
            . '<ul><li><strong>Incluir</strong>: clique em <strong>+</strong> e preencha Unidade, Área, Fonte/Atividade/Equipamento, Detalhamento, Aspecto e Impacto (as listas vêm de Levantamento Ambiental › Atividades, Aspectos, Impactos e Destinos). Clique em Salvar.</li>'
            . '<li><strong>Avaliação</strong>: informe Situação (Normal, Anormal, Emergencial), Tempo (Atual, Passado, Futuro), Influência (Direto, Indireto), Classe (Adverso, Benéfico), Destino, Escala, Severidade e Probabilidade de frequência. O <strong>Total</strong> e <strong>Aspectos significativos</strong> são calculados pelo sistema.</li>'
            . '<li>Marque <strong>Atendimento legislação</strong> e <strong>Visão partes interessadas</strong> quando se aplicarem e descreva a <strong>Disposição ou atenuante</strong> (o controle adotado).</li>'
            . '<li>Selecione uma linha para habilitar os <strong>Correlacionamentos</strong> (Requisitos legais, Controles e documentos, Inventário) e a <strong>Avaliação</strong> (Objetivos e metas e, quando houver, Questionários).</li>'
            . '<li>Use <strong>Duplicar</strong> para criar uma linha parecida (por exemplo, o mesmo aspecto em outra área) e ajuste só o que muda.</li></ul>'
            . '<p>Registre as revisões do levantamento em Levantamento Ambiental › Revisão do LAIA.</p>',
            '<p>The <strong>LAIA</strong> (Environmental Aspects and Impacts Survey) records, per unit, each activity, the environmental aspect it generates, the impact and the significance assessment.</p>'
            . '<ul><li><strong>Add</strong>: click <strong>+</strong> and fill in Unit, Area, Source/Activity/Equipment, Details, Aspect and Impact (the lists come from Environmental Survey › Activities, Aspects, Impacts and Destinations). Click Save.</li>'
            . '<li><strong>Assessment</strong>: enter Situation (Normal, Abnormal, Emergency), Time (Present, Past, Future), Influence (Direct, Indirect), Class (Adverse, Beneficial), Destination, Scale, Severity and Probability/Frequency. The <strong>Total</strong> and <strong>Significant aspects</strong> are calculated by the system.</li>'
            . '<li>Mark <strong>Legal compliance</strong> and <strong>Interested parties view</strong> when they apply and describe the <strong>Disposition or mitigation</strong> (the control adopted).</li>'
            . '<li>Select a row to enable the <strong>Correlations</strong> (Legal requirements, Controls and documents, Inventory) and the <strong>Assessment</strong> (Objectives and targets and, when available, Questionnaires).</li>'
            . '<li>Use <strong>Duplicate</strong> to create a similar row (for example, the same aspect in another area) and adjust only what changes.</li></ul>'
            . '<p>Record the survey reviews in Environmental Survey › LAIA Review.</p>',
            '<p>El <strong>LAIA</strong> (Levantamiento de Aspectos e Impactos Ambientales) registra, por unidad, cada actividad, el aspecto ambiental que genera, el impacto y la evaluación de significancia.</p>'
            . '<ul><li><strong>Incluir</strong>: haga clic en <strong>+</strong> y complete Unidad, Área, Fuente/Actividad/Equipo, Detalle, Aspecto e Impacto (las listas vienen de Levantamiento Ambiental › Actividades, Aspectos, Impactos y Destinos). Haga clic en Guardar.</li>'
            . '<li><strong>Evaluación</strong>: informe Situación (Normal, Anormal, Emergencia), Tiempo (Actual, Pasado, Futuro), Influencia (Directo, Indirecto), Clase (Adverso, Benéfico), Destino, Escala, Severidad y Probabilidad/Frecuencia. El <strong>Total</strong> y los <strong>Aspectos significativos</strong> los calcula el sistema.</li>'
            . '<li>Marque <strong>Cumplimiento legal</strong> y <strong>Visión de partes interesadas</strong> cuando correspondan y describa la <strong>Disposición o atenuante</strong> (el control adoptado).</li>'
            . '<li>Seleccione una fila para habilitar las <strong>Correlaciones</strong> (Requisitos legales, Controles y documentos, Inventario) y la <strong>Evaluación</strong> (Objetivos y metas y, cuando haya, Cuestionarios).</li>'
            . '<li>Use <strong>Duplicar</strong> para crear una fila parecida (por ejemplo, el mismo aspecto en otra área) y ajuste solo lo que cambia.</li></ul>'
            . '<p>Registre las revisiones del levantamiento en Levantamiento Ambiental › Revisión del LAIA.</p>'
        ),
    ],

    'modulolegal_lpro' => [
        'titulo' => $L('Ajuda — LPRO', 'Help — LPRO', 'Ayuda — LPRO'),
        'texto' => $L(
            '<p>O <strong>LPRO</strong> (Levantamento de Perigos e Riscos Ocupacionais) registra, por unidade e atividade, os perigos à saúde e segurança do trabalho, os riscos associados e as medidas de controle.</p>'
            . '<ul><li><strong>Incluir</strong>: clique em <strong>+</strong> e preencha Unidade, Área, Fonte/Atividade/Equipamento, Detalhamento, Perigo e Risco ocupacional (listas de Levantamento Ocupacional › Atividades, Perigos e Riscos). Clique em Salvar.</li>'
            . '<li><strong>Classificação</strong>: Tipo de atividade (Direta, Indireta, Visitantes…), Situação (Rotineira, Não rotineira, Emergencial), Abrangência (Interno, Vizinhança) e Natureza do perigo (Físicos, Químicos, Biológicos, Ergonômicos, Acidentes, Elétricos, Emergência).</li>'
            . '<li><strong>Avaliação</strong>: informe Severidade, Ocorrência e Detecção. O <strong>Total</strong> e a <strong>Tolerância</strong> são calculados pelo sistema.</li>'
            . '<li><strong>Hierarquia de controles</strong>: responda Sim/Não para eliminar ou substituir o risco, medidas de engenharia, medidas administrativas e EPIs, e descreva a <strong>Disposição ou atenuante</strong>.</li>'
            . '<li>Selecione uma linha para habilitar os <strong>Correlacionamentos</strong> (Requisitos legais, Controles e documentos, Infraestrutura, Controles administrativos, EPIs) e a <strong>Avaliação</strong> (Objetivos e metas e, quando houver, Questionários).</li></ul>'
            . '<p>Registre as revisões do levantamento em Levantamento Ocupacional › Revisão do LPRO.</p>',
            '<p>The <strong>LPRO</strong> (Occupational Hazards and Risks Survey) records, per unit and activity, the occupational health and safety hazards, the associated risks and the control measures.</p>'
            . '<ul><li><strong>Add</strong>: click <strong>+</strong> and fill in Unit, Area, Source/Activity/Equipment, Details, Occupational hazard and risk (lists from Occupational Survey › Activities, Hazards and Risks). Click Save.</li>'
            . '<li><strong>Classification</strong>: Activity type (Direct, Indirect, Visitors…), Situation (Routine, Non-routine, Emergency), Scope (Internal, Neighborhood) and Hazard nature (Physical, Chemical, Biological, Ergonomic, Accidents, Electrical, Emergency).</li>'
            . '<li><strong>Assessment</strong>: enter Severity, Occurrence and Detection. The <strong>Total</strong> and the <strong>Tolerance</strong> are calculated by the system.</li>'
            . '<li><strong>Hierarchy of controls</strong>: answer Yes/No for eliminating or substituting the risk, engineering measures, administrative measures and PPE, and describe the <strong>Disposition or mitigation</strong>.</li>'
            . '<li>Select a row to enable the <strong>Correlations</strong> (Legal requirements, Controls and documents, Infrastructure, Administrative controls, PPE) and the <strong>Assessment</strong> (Objectives and targets and, when available, Questionnaires).</li></ul>'
            . '<p>Record the survey reviews in Occupational Survey › LPRO Review.</p>',
            '<p>El <strong>LPRO</strong> (Levantamiento de Peligros y Riesgos Laborales) registra, por unidad y actividad, los peligros para la salud y seguridad en el trabajo, los riesgos asociados y las medidas de control.</p>'
            . '<ul><li><strong>Incluir</strong>: haga clic en <strong>+</strong> y complete Unidad, Área, Fuente/Actividad/Equipo, Detalle, Peligro y Riesgo laboral (listas de Levantamiento Ocupacional › Actividades, Peligros y Riesgos). Haga clic en Guardar.</li>'
            . '<li><strong>Clasificación</strong>: Tipo de actividad (Directa, Indirecta, Visitantes…), Situación (Rutinaria, No rutinaria, Emergencia), Alcance (Interno, Vecindad) y Naturaleza del peligro (Físicos, Químicos, Biológicos, Ergonómicos, Accidentes, Eléctricos, Emergencia).</li>'
            . '<li><strong>Evaluación</strong>: informe Severidad, Ocurrencia y Detección. El <strong>Total</strong> y la <strong>Tolerancia</strong> los calcula el sistema.</li>'
            . '<li><strong>Jerarquía de controles</strong>: responda Sí/No para eliminar o sustituir el riesgo, medidas de ingeniería, medidas administrativas y EPP, y describa la <strong>Disposición o atenuante</strong>.</li>'
            . '<li>Seleccione una fila para habilitar las <strong>Correlaciones</strong> (Requisitos legales, Controles y documentos, Infraestructura, Controles administrativos, EPP) y la <strong>Evaluación</strong> (Objetivos y metas y, cuando haya, Cuestionarios).</li></ul>'
            . '<p>Registre las revisiones del levantamiento en Levantamiento Ocupacional › Revisión del LPRO.</p>'
        ),
    ],

    'modulolegal_lais' => [
        'titulo' => $L('Ajuda — LAIS', 'Help — LAIS', 'Ayuda — LAIS'),
        'texto' => $L(
            '<p>O <strong>LAIS</strong> (Levantamento de Aspectos e Impactos Sociais) registra, por unidade, como as atividades da empresa afetam as partes interessadas (comunidade, colaboradores, clientes, órgãos públicos…).</p>'
            . '<ul><li><strong>Incluir</strong>: clique em <strong>+</strong> e preencha Unidade, Parte interessada, Modo de ocorrência (descreva como acontece), Aspecto social e Impacto social (listas de Levantamento Social › Parte Interessada, Aspectos Sociais e Impactos Sociais). Clique em Salvar.</li>'
            . '<li><strong>Avaliação</strong>: Situação (Normal, Anormal, Acidental), Tempo (Atual, Passado, Futuro), Influência (Direto, Indireto), Classificação (Adverso, Benéfico) e notas de 1 a 3 para Escala, Severidade e Duração. O <strong>Resultado</strong> e <strong>Aspectos significativos</strong> são calculados pelo sistema.</li>'
            . '<li>Marque <strong>Atendimento legislação</strong> e <strong>Visão partes interessadas</strong> quando se aplicarem e descreva a <strong>Disposição ou atenuante</strong>.</li>'
            . '<li>Selecione uma linha para habilitar os <strong>Correlacionamentos</strong> (Requisitos legais, Controles e documentos) e a <strong>Avaliação</strong> (Objetivos e metas e, quando houver, Questionários).</li></ul>'
            . '<p>Registre as revisões do levantamento em Levantamento Social › Revisões LAIS.</p>',
            '<p>The <strong>LAIS</strong> (Social Aspects and Impacts Survey) records, per unit, how the company\'s activities affect stakeholders (community, employees, customers, public bodies…).</p>'
            . '<ul><li><strong>Add</strong>: click <strong>+</strong> and fill in Unit, Stakeholder, Occurrence mode (describe how it happens), Social aspect and Social impact (lists from Social Survey › Stakeholder, Social Aspects and Social Impacts). Click Save.</li>'
            . '<li><strong>Assessment</strong>: Situation (Normal, Abnormal, Accidental), Time (Present, Past, Future), Influence (Direct, Indirect), Classification (Adverse, Beneficial) and scores from 1 to 3 for Scale, Severity and Duration. The <strong>Result</strong> and <strong>Significant aspects</strong> are calculated by the system.</li>'
            . '<li>Mark <strong>Legal compliance</strong> and <strong>Stakeholder view</strong> when they apply and describe the <strong>Disposition or mitigation</strong>.</li>'
            . '<li>Select a row to enable the <strong>Correlations</strong> (Legal requirements, Controls and documents) and the <strong>Assessment</strong> (Objectives and targets and, when available, Questionnaires).</li></ul>'
            . '<p>Record the survey reviews in Social Survey › LAIS Reviews.</p>',
            '<p>El <strong>LAIS</strong> (Levantamiento de Aspectos e Impactos Sociales) registra, por unidad, cómo las actividades de la empresa afectan a las partes interesadas (comunidad, colaboradores, clientes, organismos públicos…).</p>'
            . '<ul><li><strong>Incluir</strong>: haga clic en <strong>+</strong> y complete Unidad, Parte interesada, Modo de ocurrencia (describa cómo sucede), Aspecto social e Impacto social (listas de Levantamiento Social › Parte interesada, Aspectos Sociales e Impactos Sociales). Haga clic en Guardar.</li>'
            . '<li><strong>Evaluación</strong>: Situación (Normal, Anormal, Accidental), Tiempo (Actual, Pasado, Futuro), Influencia (Directo, Indirecto), Clasificación (Adverso, Benéfico) y notas de 1 a 3 para Escala, Severidad y Duración. El <strong>Resultado</strong> y los <strong>Aspectos significativos</strong> los calcula el sistema.</li>'
            . '<li>Marque <strong>Cumplimiento legal</strong> y <strong>Visión de las partes interesadas</strong> cuando correspondan y describa la <strong>Disposición o atenuante</strong>.</li>'
            . '<li>Seleccione una fila para habilitar las <strong>Correlaciones</strong> (Requisitos legales, Controles y documentos) y la <strong>Evaluación</strong> (Objetivos y metas y, cuando haya, Cuestionarios).</li></ul>'
            . '<p>Registre las revisiones del levantamiento en Levantamiento Social › Revisiones LAIS.</p>'
        ),
    ],

    'manutencaolegal_atividades' => $cadastro(
        $L('Atividades', 'Activities', 'Actividades'),
        $L('Cadastre as <strong>fontes, atividades e equipamentos</strong> da empresa (por exemplo, "Pintura", "Caldeira", "Manutenção de empilhadeiras").',
           'Register the company\'s <strong>sources, activities and equipment</strong> (for example, "Painting", "Boiler", "Forklift maintenance").',
           'Registre las <strong>fuentes, actividades y equipos</strong> de la empresa (por ejemplo, "Pintura", "Caldera", "Mantenimiento de montacargas").'),
        $L('A mesma lista aparece nos levantamentos Ambiental (LAIA), Ocupacional (LPRO) e Social, no campo Fonte/Atividade/Equipamento.',
           'The same list appears in the Environmental (LAIA), Occupational (LPRO) and Social surveys, in the Source/Activity/Equipment field.',
           'La misma lista aparece en los levantamientos Ambiental (LAIA), Ocupacional (LPRO) y Social, en el campo Fuente/Actividad/Equipo.'),
        $L('Atividade', 'Activity', 'Actividad')
    ),
    'manutencaolegal_aspectos' => $cadastro(
        $L('Aspectos', 'Aspects', 'Aspectos'),
        $L('Cadastre os <strong>aspectos ambientais</strong>: elementos das atividades que interagem com o meio ambiente (por exemplo, "Geração de resíduos oleosos", "Emissão de material particulado", "Consumo de água").',
           'Register the <strong>environmental aspects</strong>: elements of the activities that interact with the environment (for example, "Oily waste generation", "Particulate emission", "Water consumption").',
           'Registre los <strong>aspectos ambientales</strong>: elementos de las actividades que interactúan con el medio ambiente (por ejemplo, "Generación de residuos oleosos", "Emisión de material particulado", "Consumo de agua").'),
        $L('São usados no campo Aspecto do LAIA e nos correlacionamentos com a legislação.',
           'They are used in the LAIA Aspect field and in the correlations with legislation.',
           'Se usan en el campo Aspecto del LAIA y en las correlaciones con la legislación.'),
        $L('Aspecto', 'Aspect', 'Aspecto')
    ),
    'manutencaolegal_impactos' => $cadastro(
        $L('Impactos', 'Impacts', 'Impactos'),
        $L('Cadastre os <strong>impactos ambientais</strong>: a alteração causada no meio ambiente por um aspecto (por exemplo, "Contaminação do solo", "Alteração da qualidade do ar", "Redução de recursos naturais").',
           'Register the <strong>environmental impacts</strong>: the change caused to the environment by an aspect (for example, "Soil contamination", "Air quality change", "Depletion of natural resources").',
           'Registre los <strong>impactos ambientales</strong>: la alteración causada al medio ambiente por un aspecto (por ejemplo, "Contaminación del suelo", "Alteración de la calidad del aire", "Reducción de recursos naturales").'),
        $L('São usados no campo Impacto do LAIA e nos correlacionamentos com a legislação.',
           'They are used in the LAIA Impact field and in the correlations with legislation.',
           'Se usan en el campo Impacto del LAIA y en las correlaciones con la legislación.'),
        $L('Impacto', 'Impact', 'Impacto')
    ),
    'manutencaolegal_destinos' => $cadastro(
        $L('Destinos', 'Destinations', 'Destinos'),
        $L('Cadastre os <strong>destinos</strong> dados aos aspectos ambientais (por exemplo, "Aterro industrial", "Reciclagem", "Coprocessamento", "Rede de esgoto").',
           'Register the <strong>destinations</strong> given to the environmental aspects (for example, "Industrial landfill", "Recycling", "Co-processing", "Sewer system").',
           'Registre los <strong>destinos</strong> dados a los aspectos ambientales (por ejemplo, "Relleno industrial", "Reciclaje", "Coprocesamiento", "Red de alcantarillado").'),
        $L('São usados no campo Destino do LAIA.', 'They are used in the LAIA Destination field.', 'Se usan en el campo Destino del LAIA.'),
        $L('Destino', 'Destination', 'Destino')
    ),
    'manutencaolegal_perigos' => $cadastro(
        $L('Perigos', 'Hazards', 'Peligros'),
        $L('Cadastre os <strong>perigos ocupacionais</strong>: fontes ou situações com potencial de causar lesão ou doença (por exemplo, "Ruído", "Trabalho em altura", "Produtos químicos").',
           'Register the <strong>occupational hazards</strong>: sources or situations with potential to cause injury or illness (for example, "Noise", "Work at height", "Chemicals").',
           'Registre los <strong>peligros laborales</strong>: fuentes o situaciones con potencial de causar lesión o enfermedad (por ejemplo, "Ruido", "Trabajo en altura", "Productos químicos").'),
        $L('São usados no campo Perigo ocupacional do LPRO e no Correlacionamento Ocupacional.',
           'They are used in the LPRO Occupational hazard field and in the Occupational Correlation.',
           'Se usan en el campo Peligro laboral del LPRO y en la Correlación Ocupacional.'),
        $L('Perigo', 'Hazard', 'Peligro')
    ),
    'manutencaolegal_riscos' => $cadastro(
        $L('Riscos', 'Risks', 'Riesgos'),
        $L('Cadastre os <strong>riscos ocupacionais</strong>: o dano que pode resultar de um perigo (por exemplo, "Perda auditiva", "Queda", "Intoxicação").',
           'Register the <strong>occupational risks</strong>: the harm that may result from a hazard (for example, "Hearing loss", "Fall", "Poisoning").',
           'Registre los <strong>riesgos laborales</strong>: el daño que puede resultar de un peligro (por ejemplo, "Pérdida auditiva", "Caída", "Intoxicación").'),
        $L('São usados no campo Risco ocupacional do LPRO e no Correlacionamento Ocupacional.',
           'They are used in the LPRO Occupational risk field and in the Occupational Correlation.',
           'Se usan en el campo Riesgo laboral del LPRO y en la Correlación Ocupacional.'),
        $L('Risco', 'Risk', 'Riesgo')
    ),
    'moduloleglais_aspectossociais' => $cadastro(
        $L('Aspectos Sociais', 'Social Aspects', 'Aspectos Sociales'),
        $L('Cadastre os <strong>aspectos sociais</strong>: elementos das atividades que afetam pessoas e comunidades (por exemplo, "Tráfego de caminhões", "Geração de empregos", "Ruído noturno").',
           'Register the <strong>social aspects</strong>: elements of the activities that affect people and communities (for example, "Truck traffic", "Job creation", "Night noise").',
           'Registre los <strong>aspectos sociales</strong>: elementos de las actividades que afectan a personas y comunidades (por ejemplo, "Tránsito de camiones", "Generación de empleos", "Ruido nocturno").'),
        $L('São usados no campo Aspecto social do LAIS e no Correlacionamento Social.',
           'They are used in the LAIS Social aspect field and in the Social Correlation.',
           'Se usan en el campo Aspecto social del LAIS y en la Correlación Social.'),
        $L('Aspecto Social', 'Social Aspect', 'Aspecto Social')
    ),
    'moduloleglais_impactossociais' => $cadastro(
        $L('Impactos Sociais', 'Social Impacts', 'Impactos Sociales'),
        $L('Cadastre os <strong>impactos sociais</strong>: a consequência de um aspecto social para as partes interessadas (por exemplo, "Incômodo à vizinhança", "Aumento da renda local").',
           'Register the <strong>social impacts</strong>: the consequence of a social aspect for stakeholders (for example, "Disturbance to neighbors", "Increase in local income").',
           'Registre los <strong>impactos sociales</strong>: la consecuencia de un aspecto social para las partes interesadas (por ejemplo, "Molestia al vecindario", "Aumento de la renta local").'),
        $L('São usados no campo Impacto social do LAIS e no Correlacionamento Social.',
           'They are used in the LAIS Social impact field and in the Social Correlation.',
           'Se usan en el campo Impacto social del LAIS y en la Correlación Social.'),
        $L('Impacto Social', 'Social Impact', 'Impacto Social')
    ),
    'moduloleglais_partesinteressadas' => $cadastro(
        $L('Parte Interessada', 'Stakeholder', 'Parte interesada'),
        $L('Cadastre as <strong>partes interessadas</strong>: pessoas ou grupos afetados pelas atividades da empresa ou que influenciam suas decisões (por exemplo, "Comunidade do entorno", "Colaboradores", "Órgão ambiental", "Clientes").',
           'Register the <strong>stakeholders</strong>: people or groups affected by the company\'s activities or that influence its decisions (for example, "Surrounding community", "Employees", "Environmental agency", "Customers").',
           'Registre las <strong>partes interesadas</strong>: personas o grupos afectados por las actividades de la empresa o que influyen en sus decisiones (por ejemplo, "Comunidad del entorno", "Colaboradores", "Organismo ambiental", "Clientes").'),
        $L('São usadas no campo Partes interessadas do LAIS.', 'They are used in the LAIS Stakeholders field.', 'Se usan en el campo Partes interesadas del LAIS.'),
        $L('Parte Interessada', 'Stakeholder', 'Parte interesada')
    ),

    'manutencaolegal_revisaolaia' => $revisao($L('Revisão do LAIA', 'LAIA Review', 'Revisión del LAIA'),
        $L('do LAIA (Levantamento de Aspectos e Impactos Ambientais)', 'of the LAIA (Environmental Aspects and Impacts Survey)', 'del LAIA (Levantamiento de Aspectos e Impactos Ambientales)')),
    'manutencaolegal_revisaolpro' => $revisao($L('Revisão do LPRO', 'LPRO Review', 'Revisión del LPRO'),
        $L('do LPRO (Levantamento de Perigos e Riscos Ocupacionais)', 'of the LPRO (Occupational Hazards and Risks Survey)', 'del LPRO (Levantamiento de Peligros y Riesgos Laborales)')),
    'moduloleglais_revisoes' => $revisao($L('Revisões LAIS', 'LAIS Reviews', 'Revisiones LAIS'),
        $L('do LAIS (Levantamento de Aspectos e Impactos Sociais)', 'of the LAIS (Social Aspects and Impacts Survey)', 'del LAIS (Levantamiento de Aspectos e Impactos Sociales)')),

    'modulolegal_correlacionamnetoambiental' => $correlacionamento(
        $L('Correlacionamento Ambiental', 'Environmental Correlation', 'Correlación Ambiental'),
        $L('a aspectos, impactos e atividades ambientais', 'to environmental aspects, impacts and activities', 'a aspectos, impactos y actividades ambientales'),
        $L('Aspectos, Impactos, Atividades, Licenças e Documentos', 'Aspects, Impacts, Activities, Licenses and Documents', 'Aspectos, Impactos, Actividades, Licencias y Documentos')
    ),
    'modulolegal_correlacionamentoocupacional' => $correlacionamento(
        $L('Correlacionamento Ocupacional', 'Occupational Correlation', 'Correlación Ocupacional'),
        $L('a perigos, riscos e atividades ocupacionais', 'to occupational hazards, risks and activities', 'a peligros, riesgos y actividades laborales'),
        $L('Perigos, Riscos, Atividades ocupacionais, Licenças e Documentos relacionados', 'Hazards, Risks, Occupational activities, Related licenses and documents', 'Peligros, Riesgos, Actividades laborales, Licencias y Documentos relacionados')
    ),
    'modulolegal_correlacionamentosocial' => $correlacionamento(
        $L('Correlacionamento Social', 'Social Correlation', 'Correlación Social'),
        $L('a aspectos e impactos sociais', 'to social aspects and impacts', 'a aspectos e impactos sociales'),
        $L('Aspectos sociais, Impactos sociais e Documentos relacionados', 'Social aspects, Social impacts and Related documents', 'Aspectos sociales, Impactos sociales y Documentos relacionados')
    ),
];
