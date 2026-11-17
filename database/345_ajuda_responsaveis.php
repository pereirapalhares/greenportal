<?php
declare(strict_types=1);

// Migração 345 — Ajuda dos responsáveis automáticos (trecho entre <!-- ml345 --> no fim do texto atual de cada transação).
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$pt = '<p><strong>Responsáveis automáticos pelo perfil de atribuições</strong></p>'
    . '<p>O perfil aprovado pelo gestor é usado para indicar quem faz cada coisa:</p><ul>'
    . '<li><strong>Tarefa de potencial aprovado pela IA:</strong> vai para quem <strong>executa</strong> o pilar do potencial (licenças, monitoramento, treinamentos, infraestrutura, controles; influência = homologação de fornecedores) na unidade da análise. Quem pediu a análise recebe um aviso de para quem a tarefa foi;</li>'
    . '<li><strong>Item novo cadastrado sem responsável</strong> (licença, monitoramento, treinamento, infraestrutura ou controle): depois de 1 hora sem ninguém, o sistema indica quem executa o pilar na unidade e avisa a pessoa. Os itens que já existiam antes desta função ficam como estão.</li></ul>'
    . '<p>Mais de uma pessoa executa: vai para quem tem <strong>menos tarefas abertas</strong>; as outras acompanham. Quem não marcou unidades no perfil vale para todas as unidades.</p>'
    . '<ul><li><strong>Acompanha:</strong> recebe um alerta quando a tarefa é criada ou o item é atribuído;</li>'
    . '<li><strong>Aprova:</strong> recebe um alerta quando a tarefa é concluída (Home › Agenda, botão Concluir), para conferir.</li></ul>'
    . '<p>Ninguém executa o pilar: a tarefa fica com quem pediu a análise (o item fica sem responsável) e o ADM DA EMPRESA recebe um alerta para completar os perfis. O motivo da indicação fica na descrição da tarefa e na observação da responsabilidade criada.</p>';
$en = '<p><strong>Automatic owners from the responsibilities profile</strong></p><p>Tasks from AI potentials approved and new items registered without an owner go to whoever <strong>performs</strong> that pillar in the unit (the one with fewer open tasks). Followers are notified on creation; approvers when the task is completed (Agenda › Complete). With nobody in the profiles, the task stays with the requester and the company admin is notified.</p>';
$es = '<p><strong>Responsables automáticos por el perfil de atribuciones</strong></p><p>Las tareas de potenciales aprobados por la IA y los ítems nuevos sin responsable van a quien <strong>ejecuta</strong> el pilar en la unidad (el que tiene menos tareas abiertas). Quien acompaña recibe aviso al crear; quien aprueba, al concluir (Agenda › Concluir). Sin nadie en los perfiles, la tarea queda con quien pidió el análisis y se avisa al administrador.</p>';
$ptAg = '<p><strong>Concluir tarefa</strong></p><p>Selecione a tarefa e use o botão <strong>Concluir</strong> (ícone de visto) quando terminar. Quem aprova o pilar no perfil de atribuições recebe um alerta para conferir. Só o responsável pela tarefa pode concluí-la.</p>';
$enAg = '<p><strong>Complete task</strong></p><p>Select the task and use the <strong>Complete</strong> button when done. The pillar approvers get an alert to review it.</p>';
$esAg = '<p><strong>Concluir tarea</strong></p><p>Seleccione la tarea y use el botón <strong>Concluir</strong> al terminar. Quien aprueba el pilar recibe una alerta para revisar.</p>';
return [
    'perfil_atribuicoes_gestao' => ['titulo' => $L('Perfis de atribuições', 'Responsibility profiles', 'Perfiles de atribuciones'), 'texto' => $L($pt, $en, $es)],
    'perfil_atribuicoes' => ['titulo' => $L('Meu perfil de atribuições', 'My responsibilities profile', 'Mi perfil de atribuciones'), 'texto' => $L($pt, $en, $es)],
    'home_agenda' => ['titulo' => $L('Ajuda — Agenda', 'Help — Agenda', 'Ayuda — Agenda'), 'texto' => $L($ptAg . $pt, $enAg . $en, $esAg . $es)],
];
