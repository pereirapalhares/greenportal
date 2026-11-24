<?php
declare(strict_types=1);
// Migração 405 — Ajuda dos ícones de status e da barra de etapas (onda 1 da Revisão G, 24/11/2026).
// Trecho <!-- ml405 --> acrescentado à ajuda (ajuda_transacoes do universal) das telas dos módulos.
// 'comum': texto igual em todas; 'telas': transação => [título (se a tela ainda não tiver ajuda), etapas do módulo, ícones do módulo].

$comum = [
    'pt' => '<p><strong>Ícones de status</strong></p><p>Cada linha começa com um ícone que resume a situação do registro. Passe o mouse sobre ele para ver o motivo. '
        . 'Ao lado da paginação, a legenda mostra quantas linhas da página estão em cada situação.</p>'
        . '<ul><li><b>Verde com visto</b>: em dia / atendido / encerrado.</li><li><b>Amarelo com exclamação</b>: atenção (prazo de reação atingido, parcialmente atendido).</li>'
        . '<li><b>Vermelho com relógio</b>: vencido.</li><li><b>Vermelho escuro com bandeira</b>: crítico (não atendido, falha, risco alto).</li>'
        . '<li><b>Azul com seta</b>: em andamento no prazo.</li><li><b>Cinza com interrogação</b>: não avaliado ou sem data.</li><li><b>Cinza-claro com traço</b>: inativo.</li></ul>'
        . '<p><strong>Etapas do processo</strong></p><p>Acima do grid fica a barra com as etapas do processo de gestão deste módulo. Selecione uma linha para ver em que etapa ela está:</p>'
        . '<ul><li><b>Verde</b>: etapa feita (verde-claro: etapa que já passou, mas sem o campo preenchido).</li><li><b>Azul</b>: etapa atual — a próxima etapa obrigatória a fazer.</li>'
        . '<li><b>Número em cinza</b>: pendente. <b>Contorno tracejado</b>: etapa facultativa.</li><li><b>Traço</b>: não se aplica a este registro ou ainda não disponível no cadastro.</li></ul>'
        . '<p>A seta no canto da barra recolhe ou mostra as etapas (o portal lembra a sua escolha neste navegador).</p>',
    'en' => '<p><strong>Status icons</strong></p><p>Each row starts with an icon that summarizes the record status. Hover over it to see why. '
        . 'Next to the pagination, the legend shows how many rows of the page are in each status.</p>'
        . '<ul><li><b>Green check</b>: up to date / met / closed.</li><li><b>Yellow exclamation</b>: attention (reaction date reached, partially met).</li>'
        . '<li><b>Red clock</b>: expired.</li><li><b>Dark red flag</b>: critical (not met, failure, high risk).</li>'
        . '<li><b>Blue arrow</b>: in progress, on time.</li><li><b>Grey question mark</b>: not assessed or no date.</li><li><b>Light grey dash</b>: inactive.</li></ul>'
        . '<p><strong>Process stages</strong></p><p>Above the grid is the bar with the management process stages of this module. Select a row to see its stage:</p>'
        . '<ul><li><b>Green</b>: done (light green: stage passed without the field filled in).</li><li><b>Blue</b>: current stage — the next required step.</li>'
        . '<li><b>Grey number</b>: pending. <b>Dashed outline</b>: optional stage.</li><li><b>Dash</b>: not applicable or not yet available in the form.</li></ul>'
        . '<p>The arrow in the corner of the bar hides or shows the stages (remembered in this browser).</p>',
    'es' => '<p><strong>Iconos de estado</strong></p><p>Cada fila empieza con un icono que resume la situación del registro. Pase el mouse sobre él para ver el motivo. '
        . 'Junto a la paginación, la leyenda muestra cuántas filas de la página están en cada situación.</p>'
        . '<ul><li><b>Verde con visto</b>: al día / cumplido / cerrado.</li><li><b>Amarillo con exclamación</b>: atención (fecha de reacción alcanzada, parcialmente cumplido).</li>'
        . '<li><b>Rojo con reloj</b>: vencido.</li><li><b>Rojo oscuro con bandera</b>: crítico (no cumplido, falla, riesgo alto).</li>'
        . '<li><b>Azul con flecha</b>: en curso, en plazo.</li><li><b>Gris con interrogación</b>: no evaluado o sin fecha.</li><li><b>Gris claro con guion</b>: inactivo.</li></ul>'
        . '<p><strong>Etapas del proceso</strong></p><p>Encima de la cuadrícula está la barra con las etapas del proceso de gestión de este módulo. Seleccione una fila para ver su etapa:</p>'
        . '<ul><li><b>Verde</b>: etapa hecha (verde claro: etapa que ya pasó, sin el campo completado).</li><li><b>Azul</b>: etapa actual — la próxima etapa obligatoria.</li>'
        . '<li><b>Número en gris</b>: pendiente. <b>Contorno discontinuo</b>: etapa opcional.</li><li><b>Guion</b>: no aplica o aún no disponible en el registro.</li></ul>'
        . '<p>La flecha en la esquina de la barra oculta o muestra las etapas (se recuerda en este navegador).</p>',
];

$t = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$licencas = $t('<p><b>Neste módulo:</b> o ícone segue a Situação da licença (válida, em protocolo, vencida, sem data, entrega periódica) e a data de reação. As linhas não são mais pintadas. Licenças em protocolo mostram o fluxo do protocolo (abre o protocolo, carrega a nova licença, encerra o protocolo, inativa a antiga).</p>',
    '<p><b>In this module:</b> the icon follows the License status and the reaction date. Rows are no longer colored. Licenses under renewal show the renewal flow.</p>',
    '<p><b>En este módulo:</b> el icono sigue la Situación de la licencia y la fecha de reacción. Las filas ya no se pintan. Las licencias en protocolo muestran el flujo del protocolo.</p>');
$legal = $t('<p><b>Neste módulo:</b> o ícone segue a Situação consolidada do requisito (atendido, parcialmente, não atendido, indefinido). As 10 etapas vão de receber a lei até definir a próxima avaliação; condicionantes e plano de ação só contam quando existem.</p>',
    '<p><b>In this module:</b> the icon follows the consolidated status of the requirement. The 10 stages go from receiving the law to setting the next review; conditions and action plans only count when they exist.</p>',
    '<p><b>En este módulo:</b> el icono sigue la Situación consolidada del requisito. Las 10 etapas van de recibir la ley a definir la próxima evaluación.</p>');
$medicao = $t('<p><b>Neste módulo:</b> o ícone segue a Data da medição e a Data de reação. A etapa "carrega a evidência" aparece como ainda não disponível até existir o campo de evidência.</p>',
    '<p><b>In this module:</b> the icon follows the measurement and reaction dates. The "uploads the evidence" stage shows as not yet available until the evidence field exists.</p>',
    '<p><b>En este módulo:</b> el icono sigue la fecha de medición y la de reacción. La etapa "carga la evidencia" aparece como aún no disponible.</p>');
$pilar = $t('<p><b>Neste módulo:</b> o ícone mostra a pior situação legal do item nos requisitos a que ele está vinculado (a mesma da Home do módulo); sem vínculo, fica cinza. Etapas como validade, prazo de guarda e plano de manutenção aparecem como ainda não disponíveis até os novos campos do cadastro.</p>',
    '<p><b>In this module:</b> the icon shows the worst legal status of the item across its linked requirements (same as the module home); without links it is grey. Stages such as due date, retention and maintenance plan show as not yet available until the new fields exist.</p>',
    '<p><b>En este módulo:</b> el icono muestra la peor situación legal del ítem en los requisitos vinculados; sin vínculo, queda gris.</p>');
$fornecedor = $t('<p><b>Neste módulo:</b> o ícone segue a Data da próxima avaliação (vence em até 30 dias = atenção) e se o fornecedor está ativado. As etapas de auditoria aparecem como ainda não disponíveis (o módulo de Auditoria ainda não existe).</p>',
    '<p><b>In this module:</b> the icon follows the next evaluation date (within 30 days = attention) and whether the supplier is active. Audit stages show as not yet available.</p>',
    '<p><b>En este módulo:</b> el icono sigue la fecha de la próxima evaluación (hasta 30 días = atención) y si el proveedor está activado.</p>');
$funcionario = $t('<p><b>Neste módulo:</b> o ícone indica se a integração foi realizada (Data de realização da integração). As etapas de documentos e treinamentos ficam feitas quando todos os critérios e treinamentos ativos do funcionário têm o dado; critérios "Desligado", "Afastado" e "NAP" não contam.</p>',
    '<p><b>In this module:</b> the icon shows whether the induction was done. Document and training stages are done when all active criteria and trainings of the employee have the data.</p>',
    '<p><b>En este módulo:</b> el icono indica si la integración fue realizada. Las etapas de documentos y capacitaciones quedan hechas cuando todos los criterios y capacitaciones activos tienen el dato.</p>');
$rnc = $t('<p><b>Neste módulo:</b> o ícone mostra encerrada, em andamento no prazo, prazo vencido ou risco alto. A posição na barra segue a etapa do fluxo da NC; etapas que ficaram para trás sem o campo preenchido aparecem em verde-claro.</p>',
    '<p><b>In this module:</b> the icon shows closed, in progress on time, overdue or high risk. The bar position follows the NC workflow stage.</p>',
    '<p><b>En este módulo:</b> el icono muestra cerrada, en curso en plazo, plazo vencido o riesgo alto. La posición en la barra sigue la etapa del flujo de la NC.</p>');

return [
    'comum' => $comum,
    'telas' => [
        'manutencaolegal_licencas' => [$t('Licenças', 'Licenses', 'Licencias'), $licencas],
        'minhas_licencas' => [$t('Minhas licenças', 'My licenses', 'Mis licencias'), $licencas],
        'modulolegal_requisitoslegais' => [$t('Requisitos legais', 'Legal requirements', 'Requisitos legales'), $legal],
        'meus_requisitos' => [$t('Meus requisitos legais', 'My legal requirements', 'Mis requisitos legales'), $legal],
        'medicaoemonitoramento_mm_monitoramento' => [$t('Plano de Medição e Monitoramento', 'Measurement and Monitoring Plan', 'Plan de Medición y Monitoreo'), $medicao],
        'minhas_medicoes' => [$t('Minhas medições', 'My measurements', 'Mis mediciones'), $medicao],
        'modulolegal_controle' => [$t('Controles', 'Controls', 'Controles'), $pilar],
        'meus_controles' => [$t('Meus controles', 'My controls', 'Mis controles'), $pilar],
        'modulolegal_treinamentos' => [$t('Treinamentos', 'Trainings', 'Capacitaciones'), $pilar],
        'meus_treinamentos' => [$t('Meus treinamentos', 'My trainings', 'Mis capacitaciones'), $pilar],
        'modulolegal_infraestrutura' => [$t('Infraestrutura', 'Infrastructure', 'Infraestructura'), $pilar],
        'minhas_infraestruturas' => [$t('Minhas infraestruturas', 'My infrastructure', 'Mis infraestructuras'), $pilar],
        'homologacaofornecedor_fornecedor' => [$t('Gestão de fornecedores', 'Supplier management', 'Gestión de proveedores'), $fornecedor],
        'meus_fornecedores' => [$t('Meus fornecedores', 'My suppliers', 'Mis proveedores'), $fornecedor],
        'homologacaofornecedor_funcionario' => [$t('Funcionários dos fornecedores', 'Supplier employees', 'Empleados de proveedores'), $funcionario],
        'meus_funcionarios' => [$t('Meus funcionários', 'My employees', 'Mis empleados'), $funcionario],
        'naoconformidades_listageralocorrencias' => [$t('Lista geral de ocorrências', 'All occurrences', 'Lista general de ocurrencias'), $rnc],
        'naoconformidades_minhasocorrencias' => [$t('Minhas ocorrências', 'My occurrences', 'Mis ocurrencias'), $rnc],
    ],
];
