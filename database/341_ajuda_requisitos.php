<?php
declare(strict_types=1);

/*
 * Migração 341 — texto da Ajuda (botão "?") de Requisitos legais sobre o nível de atendimento (%) e o risco, e notas curtas nas
 * ajudas dos cadastros dos pilares. O runner (scripts/aplicar-migracao-341-requisitos-risco.php) põe o trecho entre as marcas
 * <!-- ml341 --> e <!-- /ml341 --> no fim do texto atual da transação (ajuda_transacoes do universal), sem apagar o que já existe;
 * rodando de novo, só o trecho entre as marcas é trocado.
 * Formato: transacao => [titulo pt/en/es (usado só se a ajuda ainda não existir), texto pt/en/es em HTML simples].
 */

require_once dirname(__DIR__) . '/classes/RequisitoRisco.php';

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

// Tabelas dos critérios (português; os critérios são os do documento do Ricardo)
$tabelaCriterios = static function (string $area, string $titulo) use ($h): string {
    $rot = $area === 'PROBABILIDADE' ? RequisitoRisco::ROTULOS_PROBABILIDADE : RequisitoRisco::ROTULOS_SEVERIDADE;
    $t = '<p><em>' . $h($titulo) . '</em></p><ul>';
    foreach (RequisitoRisco::CRITERIOS[$area] as $n => $c) $t .= '<li><strong>' . $n . ' – ' . $h($rot[$n]) . ':</strong> ' . $h($c) . '</li>';
    return $t . '</ul>';
};
$criteriosPt = $tabelaCriterios('LICENCAS', 'Licenças e autorizações') . $tabelaCriterios('MEDICAO', 'Medição e monitoramento')
    . $tabelaCriterios('TREINAMENTOS', 'Treinamento e capacitação') . $tabelaCriterios('INFRAESTRUTURA', 'Infraestrutura')
    . $tabelaCriterios('CONTROLES', 'Controles operacionais e administrativos') . $tabelaCriterios('HOMOLOGACAO', 'Fornecedores (homologação)')
    . $tabelaCriterios('PROBABILIDADE', 'Probabilidade (igual para todos os pilares)');

$classesPt = '<ul>'
    . '<li><strong>1 a 3 = Baixo:</strong> Manter controles e acompanhamento de rotina.</li>'
    . '<li><strong>4 a 6 = Moderado:</strong> Planejar melhorias e ações preventivas.</li>'
    . '<li><strong>8 a 9 = Alto:</strong> Priorizar ações corretivas e reforçar controles.</li>'
    . '<li><strong>12 a 16, ou Severidade 4 (qualquer probabilidade) = Crítico:</strong> Adotar medidas imediatas de redução do risco.</li>'
    . '</ul>';
$classesEn = '<ul>'
    . '<li><strong>1 to 3 = Low:</strong> Keep controls and routine monitoring.</li>'
    . '<li><strong>4 to 6 = Moderate:</strong> Plan improvements and preventive actions.</li>'
    . '<li><strong>8 to 9 = High:</strong> Prioritize corrective actions and reinforce controls.</li>'
    . '<li><strong>12 to 16, or Severity 4 (any probability) = Critical:</strong> Take immediate risk reduction measures.</li>'
    . '</ul>';
$classesEs = '<ul>'
    . '<li><strong>1 a 3 = Bajo:</strong> Mantener controles y seguimiento de rutina.</li>'
    . '<li><strong>4 a 6 = Moderado:</strong> Planificar mejoras y acciones preventivas.</li>'
    . '<li><strong>8 a 9 = Alto:</strong> Priorizar acciones correctivas y reforzar controles.</li>'
    . '<li><strong>12 a 16, o Severidad 4 (cualquier probabilidad) = Crítico:</strong> Adoptar medidas inmediatas de reducción del riesgo.</li>'
    . '</ul>';

$requisitosPt = '<p><strong>Nível de atendimento e risco do requisito legal</strong></p>'
    . '<p>Além do <strong>Atendido?</strong> (Sim, Não, Parcialmente atendido), que continua sendo preenchido pelo responsável como antes, cada requisito legal passa a ser avaliado automaticamente em <strong>duas dimensões independentes</strong>, calculadas a partir dos itens de gestão ligados a ele nos pilares (licenças, medição e monitoramento, treinamentos, infraestrutura, controles e fornecedores):</p>'
    . '<ol><li><strong>% de atendimento</strong> — quanto das obrigações aplicáveis está atendido;</li><li><strong>Risco</strong> — o tamanho da consequência se a obrigação falhar.</li></ol>'
    . '<p><strong>As duas dimensões não se misturam.</strong> Um requisito pode estar 100% atendido e ter risco Crítico (as obrigações estão em dia, mas falhar nelas seria grave), ou 20% atendido e ter risco Baixo. Por isso o grid, a situação do requisito e os painéis mostram as duas lado a lado.</p>'
    . '<p><strong>1. % de atendimento</strong></p>'
    . '<p>% de atendimento = itens aplicáveis <em>atendidos</em> ÷ itens <em>aplicáveis</em> do requisito na unidade.</p>'
    . '<ul><li><strong>Atendido</strong>: item com situação <strong>Ok</strong> ou <strong>Atenção</strong> (ex.: licença válida; licença vencida com renovação protocolada no prazo; medição em dia).</li>'
    . '<li><strong>Não atendido</strong>: item com situação <strong>Falha</strong> (ex.: medição prevista e não registrada; treinamento sem responsável) ou <strong>Vencido</strong> (licença vencida sem protocolo de renovação — antes esta situação aparecia como "Crítico" e mudou de nome para não ser confundida com o risco Crítico).</li>'
    . '<li><strong>Aplicável</strong>: todo item ligado ao requisito, menos os marcados como <strong>Não se aplica</strong> (veja o item 5).</li></ul>'
    . '<p>Exemplo: um requisito com 3 licenças e 2 controles, com 2 licenças e 2 controles Ok, tem 4 de 5 itens atendidos = <strong>80%</strong>.</p>'
    . '<p>A <strong>Situação calculada</strong> (Atendido, Parcialmente atendido, Não atendido, Sem evidências vinculadas) continua com as mesmas regras, agora sem os itens "não se aplica": algum item Vencido = Não atendido; nenhuma Falha = Atendido; nenhum item atendido = Não atendido; o resto = Parcialmente atendido; sem itens aplicáveis = Sem evidências vinculadas.</p>'
    . '<p><strong>2. Risco de cada item</strong></p>'
    . '<p>Cada item dos cadastros dos pilares recebe <strong>Severidade</strong> (1 a 4, com critério próprio de cada pilar) e <strong>Probabilidade</strong> (1 a 4, mesmo critério para todos). Os dois campos são <strong>obrigatórios</strong> ao incluir o item e ao alterar um item que ainda não tem risco; o sistema calcula a pontuação e a classificação:</p>'
    . $classesPt
    . '<p>Os campos ficam nas telas Licenças, Plano de medição e monitoramento, Treinamentos, Infraestrutura, Controle de documentos e Fornecedores (e nas telas "Minhas…"). Passe o mouse no título da coluna para ver os critérios. O risco da licença vale para todas as versões dela. Cada alteração fica guardada com o usuário e a data.</p>'
    . '<p><strong>Critérios de severidade e de probabilidade</strong></p>' . $criteriosPt
    . '<p><strong>3. Risco do requisito</strong></p>'
    . '<p>É o <strong>pior risco entre os itens aplicáveis</strong> do requisito na unidade, atendidos ou não (Crítico &gt; Alto &gt; Moderado &gt; Baixo). Exemplo: 3 licenças e 2 controles, um de risco Crítico, dois Altos e dois Moderados = risco do requisito <strong>Crítico</strong>. Se nenhum item aplicável tem risco avaliado, o risco do requisito aparece como <strong>Não avaliado</strong>.</p>'
    . '<p><strong>4. Pendências críticas (atenção imediata)</strong></p>'
    . '<p>Pendência crítica é um item aplicável <strong>não atendido</strong> (Falha ou Vencido) <strong>com risco Crítico</strong>. É o número em vermelho dos painéis: mesmo com um % de atendimento alto, uma pendência crítica pede ação imediata. Os requisitos com pendência crítica ficam em destaque e o painel lista os itens e os <strong>responsáveis</strong> por eles.</p>'
    . '<p><strong>5. Não se aplica</strong></p>'
    . '<p>Quando um item está ligado ao requisito mas a obrigação não se aplica a ele naquela unidade, abra a <strong>Situação</strong> do requisito, na lista "Itens de gestão considerados" clique em <strong>Não se aplica</strong> no item e escreva a justificativa (pelo menos 10 caracteres). O item sai do % de atendimento, do risco do requisito e das pendências, e continua aparecendo na lista, riscado, com a justificativa, quem marcou e quando. Para voltar a contar, clique em <strong>Volta a se aplicar</strong>. Marcações e desmarcações ficam no histórico. A marcação vale só para aquele requisito e unidade.</p>'
    . '<p><strong>6. Risco não avaliado</strong></p>'
    . '<p>Os itens cadastrados antes desta mudança (e os que ainda vêm do sistema anterior) não têm severidade e probabilidade. Eles aparecem como <strong>Risco não avaliado</strong> e entram nos painéis como pendência de cadastro, até que alguém informe os dois campos no cadastro do item. Nenhum valor é suposto automaticamente.</p>'
    . '<p><strong>7. Onde ver e como rastrear</strong></p>'
    . '<ul><li><strong>Grid de Requisitos legais</strong>: colunas <strong>% atendimento</strong>, <strong>Risco</strong>, <strong>Pendências críticas</strong> e <strong>Itens sem risco</strong>, com filtro. A coluna Motivo da situação calculada resume a conta (ex.: "Atendimento 80% (4 de 5 itens aplicáveis). Risco: Crítico; 1 pendência crítica.").</li>'
    . '<li><strong>Situação do requisito</strong> (botão Situação): mostra o % com a conta, o risco, as pendências críticas e, item a item, o pilar, a situação, o motivo, a severidade × probabilidade, o risco e o "não se aplica" — é a explicação de por que o sistema chegou ao % e ao risco.</li>'
    . '<li><strong>Dashboard</strong> (aba Atendimento legal e Meu dashboard) e <strong>Relatório de Atendimento Legal</strong> (PDF e Excel): % de atendimento, requisitos por risco, a matriz <em>risco × % de atendimento</em> (em vermelho, risco Crítico sem atendimento total), o risco por unidade, as pendências críticas com os <strong>responsáveis em evidência</strong> e os itens com risco não avaliado.</li></ul>'
    . '<p><strong>8. Quando o cálculo é atualizado</strong></p>'
    . '<p>Ao salvar um item, o risco ou o "não se aplica", os requisitos ligados entram na fila de recálculo, processada pelo serviço do Agendador a cada 5 minutos; a tela de Situação recalcula o requisito na hora em que é aberta; e uma vez por dia é feito o cálculo completo (para o que muda só com a data, como vencimentos).</p>'
    . '<p class="text-muted small">Próxima etapa: a IA poderá sugerir a severidade e a probabilidade com justificativa, para o responsável técnico confirmar.</p>';

$requisitosEn = '<p><strong>Requirement compliance level and risk</strong></p>'
    . '<p>Besides <strong>Compliant?</strong> (Yes, No, Partially compliant), still filled in by the owner as before, each legal requirement is now assessed automatically in <strong>two independent dimensions</strong>, calculated from the management items linked to it in the pillars (licenses, measurement and monitoring, training, infrastructure, controls and suppliers):</p>'
    . '<ol><li><strong>Compliance %</strong> — how much of the applicable obligations is met;</li><li><strong>Risk</strong> — how serious the consequence would be if the obligation fails.</li></ol>'
    . '<p><strong>The two dimensions are not mixed.</strong> A requirement may be 100% compliant with Critical risk, or 20% compliant with Low risk.</p>'
    . '<p><strong>1. Compliance %</strong></p><p>Compliance % = applicable items <em>met</em> ÷ <em>applicable</em> items of the requirement in the unit. Met = status <strong>Ok</strong> or <strong>Attention</strong>; not met = <strong>Failure</strong> or <strong>Expired</strong> (license expired without renewal protocol — formerly shown as "Critical"). Applicable = all linked items except those marked <strong>Not applicable</strong>. Example: 3 licenses and 2 controls, with 2 licenses and 2 controls Ok = 4 of 5 = <strong>80%</strong>. The calculated status keeps the same rules, now without the not applicable items.</p>'
    . '<p><strong>2. Risk of each item</strong></p><p>Each item in the pillar registers gets <strong>Severity</strong> (1 to 4, criteria of each pillar) and <strong>Probability</strong> (1 to 4, same criteria for all). Both are <strong>required</strong> when adding an item or changing an item without risk; the system calculates the score and rating:</p>' . $classesEn
    . '<p>The criteria are shown when hovering over the column titles. The license risk applies to all its versions.</p>'
    . '<p><strong>3. Requirement risk</strong></p><p>The <strong>worst risk among the applicable items</strong> (Critical &gt; High &gt; Moderate &gt; Low), met or not. With no assessed item, the risk is <strong>Not assessed</strong>.</p>'
    . '<p><strong>4. Critical pending items</strong></p><p>An applicable item <strong>not met</strong> (Failure or Expired) <strong>with Critical risk</strong>. It is the red number in the dashboards and requires immediate action; the owners of these items are highlighted.</p>'
    . '<p><strong>5. Not applicable</strong></p><p>In the requirement <strong>Status</strong> screen, in "Management items considered", click <strong>Not applicable</strong> on the item and write the justification (at least 10 characters). The item leaves the %, the risk and the pending items, and stays in the list with the justification. Click <strong>Applies again</strong> to count it again. Both actions are kept in the history.</p>'
    . '<p><strong>6. Risk not assessed</strong></p><p>Items registered before this change have no severity and probability: they show as <strong>Risk not assessed</strong> and appear in the dashboards as a pending registration until both fields are filled in.</p>'
    . '<p><strong>7. Where to see it</strong></p><p>Grid columns <strong>Compliance %</strong>, <strong>Risk</strong>, <strong>Critical pending</strong> and <strong>Items without risk</strong>; the requirement Status screen explains item by item why the system reached the % and the risk; Dashboard and Legal Compliance Report show both dimensions.</p>'
    . '<p><strong>8. When it is updated</strong></p><p>Saving an item, its risk or a not applicable mark queues the linked requirements for recalculation (scheduler service every 5 minutes); the Status screen recalculates on opening; a full calculation runs once a day.</p>';

$requisitosEs = '<p><strong>Nivel de cumplimiento y riesgo del requisito legal</strong></p>'
    . '<p>Además de <strong>¿Atendido?</strong> (Sí, No, Parcialmente atendido), que el responsable sigue completando como antes, cada requisito legal pasa a evaluarse automáticamente en <strong>dos dimensiones independientes</strong>, calculadas a partir de los ítems de gestión vinculados en los pilares (licencias, medición y monitoreo, capacitaciones, infraestructura, controles y proveedores):</p>'
    . '<ol><li><strong>% de cumplimiento</strong> — cuánto de las obligaciones aplicables está cumplido;</li><li><strong>Riesgo</strong> — la gravedad de la consecuencia si la obligación falla.</li></ol>'
    . '<p><strong>Las dos dimensiones no se mezclan.</strong> Un requisito puede estar 100% cumplido con riesgo Crítico, o 20% cumplido con riesgo Bajo.</p>'
    . '<p><strong>1. % de cumplimiento</strong></p><p>% = ítems aplicables <em>cumplidos</em> ÷ ítems <em>aplicables</em> del requisito en la unidad. Cumplido = situación <strong>Ok</strong> o <strong>Atención</strong>; no cumplido = <strong>Falla</strong> o <strong>Vencido</strong> (licencia vencida sin protocolo de renovación, antes "Crítico"). Aplicable = todos los ítems vinculados menos los marcados <strong>No se aplica</strong>. Ejemplo: 3 licencias y 2 controles, con 2 licencias y 2 controles Ok = 4 de 5 = <strong>80%</strong>.</p>'
    . '<p><strong>2. Riesgo de cada ítem</strong></p><p>Cada ítem recibe <strong>Severidad</strong> (1 a 4, criterio de cada pilar) y <strong>Probabilidad</strong> (1 a 4, criterio común). Ambos son <strong>obligatorios</strong> al incluir el ítem o al modificar un ítem sin riesgo; el sistema calcula la puntuación y la clasificación:</p>' . $classesEs
    . '<p><strong>3. Riesgo del requisito</strong></p><p>El <strong>peor riesgo entre los ítems aplicables</strong> (Crítico &gt; Alto &gt; Moderado &gt; Bajo). Sin ítems evaluados: <strong>No evaluado</strong>.</p>'
    . '<p><strong>4. Pendientes críticos</strong></p><p>Ítem aplicable <strong>no cumplido</strong> (Falla o Vencido) <strong>con riesgo Crítico</strong>: requiere acción inmediata y sus responsables quedan destacados.</p>'
    . '<p><strong>5. No se aplica</strong></p><p>En la pantalla <strong>Situación</strong> del requisito, en "Ítems de gestión considerados", haga clic en <strong>No se aplica</strong> e informe la justificación (al menos 10 caracteres). El ítem sale del %, del riesgo y de los pendientes. <strong>Vuelve a aplicarse</strong> lo cuenta de nuevo. Todo queda en el historial.</p>'
    . '<p><strong>6. Riesgo no evaluado</strong></p><p>Los ítems registrados antes de este cambio no tienen severidad ni probabilidad: aparecen como <strong>Riesgo no evaluado</strong> y como pendiente en los paneles hasta completar los dos campos.</p>'
    . '<p><strong>7. Dónde verlo</strong></p><p>Columnas <strong>% cumplimiento</strong>, <strong>Riesgo</strong>, <strong>Pendientes críticos</strong> e <strong>Ítems sin riesgo</strong>; la pantalla Situación explica ítem por ítem el % y el riesgo; el Panel y el Informe de Atendimiento Legal muestran ambas dimensiones.</p>'
    . '<p><strong>8. Actualización</strong></p><p>Al guardar un ítem, su riesgo o un "no se aplica", los requisitos vinculados entran en la cola de recálculo (servicio cada 5 minutos); la pantalla Situación recalcula al abrir; una vez al día se hace el cálculo completo.</p>';

$notaPilarPt = static fn(string $area, string $nome): string => '<p><strong>Risco do item (Severidade × Probabilidade)</strong></p><p>Cada ' . $h($nome) . ' tem as colunas <strong>Severidade</strong> e <strong>Probabilidade</strong> (1 a 4), obrigatórias ao incluir e ao alterar um item que ainda não tem risco. O sistema calcula o <strong>Risco</strong>: 1–3 Baixo, 4–6 Moderado, 8–9 Alto, 12–16 ou Severidade 4 = Crítico. Passe o mouse no título da coluna para ver os critérios. O risco entra no cálculo do risco e das pendências críticas dos requisitos legais ligados ao item — veja a Ajuda de Requisitos legais.</p>';
$notaPilarEn = '<p><strong>Item risk (Severity × Probability)</strong></p><p>The <strong>Severity</strong> and <strong>Probability</strong> columns (1 to 4) are required when adding an item or changing an item without risk. The system calculates the <strong>Risk</strong>: 1–3 Low, 4–6 Moderate, 8–9 High, 12–16 or Severity 4 = Critical. Hover the column title to see the criteria. See the Legal requirements help.</p>';
$notaPilarEs = '<p><strong>Riesgo del ítem (Severidad × Probabilidad)</strong></p><p>Las columnas <strong>Severidad</strong> y <strong>Probabilidad</strong> (1 a 4) son obligatorias al incluir un ítem o al modificar un ítem sin riesgo. El sistema calcula el <strong>Riesgo</strong>: 1–3 Bajo, 4–6 Moderado, 8–9 Alto, 12–16 o Severidad 4 = Crítico. Vea la Ayuda de Requisitos legales.</p>';

$pilares = [
    'manutencaolegal_licencas' => ['licença', 'Licenças', 'Licenses', 'Licencias'],
    'minhas_licencas' => ['licença', 'Minhas licenças', 'My licenses', 'Mis licencias'],
    'medicaoemonitoramento_mm_monitoramento' => ['item do plano de medição e monitoramento', 'Plano de medição e monitoramento', 'Measurement and monitoring plan', 'Plan de medición y monitoreo'],
    'modulolegal_treinamentos' => ['treinamento', 'Treinamentos', 'Training', 'Capacitaciones'],
    'meus_treinamentos' => ['treinamento', 'Meus treinamentos', 'My training', 'Mis capacitaciones'],
    'modulolegal_infraestrutura' => ['item de infraestrutura', 'Infraestrutura', 'Infrastructure', 'Infraestructura'],
    'minhas_infraestruturas' => ['item de infraestrutura', 'Minhas infraestruturas', 'My infrastructure', 'Mis infraestructuras'],
    'modulolegal_controle' => ['controle', 'Controle de documentos', 'Document control', 'Control de documentos'],
    'meus_controles' => ['controle', 'Meus controles', 'My controls', 'Mis controles'],
    'homologacaofornecedor_fornecedor' => ['fornecedor', 'Fornecedores', 'Suppliers', 'Proveedores'],
];

$out = ['modulolegal_requisitoslegais' => ['titulo' => $L('Ajuda — Requisitos legais', 'Help — Legal requirements', 'Ayuda — Requisitos legales'), 'texto' => $L($requisitosPt, $requisitosEn, $requisitosEs)]];
// A tela de Situação do requisito (onde se marca "não se aplica" e se vê a conta item a item) tem o mesmo texto
$out['manutencaolegalrequisitoslegais_situacao'] = ['titulo' => $L('Ajuda — Situação do requisito legal', 'Help — Legal requirement status', 'Ayuda — Situación del requisito legal'), 'texto' => $L($requisitosPt, $requisitosEn, $requisitosEs)];
foreach ($pilares as $transacao => [$nome, $pt, $en, $es]) {
    $out[$transacao] = ['titulo' => $L('Ajuda — ' . $pt, 'Help — ' . $en, 'Ayuda — ' . $es), 'texto' => $L($notaPilarPt('', $nome), $notaPilarEn, $notaPilarEs)];
}
return $out;
