<?php
declare(strict_types=1);

/*
 * Migração 365 — Ajuda (F1 / ?) da Home › Gestão de pendências: guias e "Sobre esta tela" do assunto "inicio"
 * (Home e minhas atividades). Juntado ao Guia do usuário por scripts/aplicar-migracao-365-gestao-pendencias.php.
 */

if (!defined('GREEN_GUIA_USUARIO')) { http_response_code(404); exit; }

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$P = static fn(array $texto, array $ver = [], string $alvo = '', bool $auto = true): array
    => ['texto' => $texto, 'ver' => $ver ?: $L('', '', ''), 'alvo' => $alvo, 'tela' => '', 'quem' => 'gestor', 'auto' => $auto];
$telas = ['gestao_pendencias', 'arquivo:gestao_pendencias.php'];

return [
    'inicio' => [
        'guias' => [
            [
                'id' => 'inicio-gestao-pendencias',
                'titulo' => $L('Ocultar ou limpar pendências de uma pessoa', 'Hide or clear a person\'s pending items', 'Ocultar o limpiar pendientes de una persona'),
                'resumo' => $L('Esconder pendências que não fazem mais sentido, com motivo e log; elas voltam se o prazo mudar.', 'Hide pending items that no longer make sense, with reason and log; they return if the deadline changes.', 'Ocultar pendientes que ya no tienen sentido, con motivo y registro; vuelven si cambia el plazo.'),
                'antes' => $L('Para ADMINISTRADOR, ADM DA EMPRESA e gestores (só a equipe). O registro no módulo não muda: só a pendência some.', 'For ADMINISTRADOR, ADM DA EMPRESA and managers (their team only). The module record does not change: only the pending item disappears.', 'Para ADMINISTRADOR, ADM DA EMPRESA y gestores (solo su equipo). El registro del módulo no cambia: solo desaparece el pendiente.'),
                'depois' => $L('A pessoa recebe um alerta com o motivo. Tudo fica na aba Log; para desfazer, use Reativar na aba Ocultas e desligadas.', 'The person gets an alert with the reason. Everything stays in the Log tab; to undo, use Reactivate in the Hidden and switched off tab.', 'La persona recibe una alerta con el motivo. Todo queda en la pestaña Registro; para deshacer, use Reactivar en la pestaña Ocultas y desactivadas.'),
                'perfis' => ['adm', 'gestor'], 'telas' => $telas,
                'passos' => [
                    $P($L('Em **Pessoa**, escolha de quem são as pendências.', 'In **Person**, choose whose pending items to see.', 'En **Persona**, elija de quién son los pendientes.'),
                        $L('Aparece a lista das pendências dela, com a situação de cada uma.', 'Their pending items list appears, with the status of each.', 'Aparece la lista de sus pendientes, con la situación de cada uno.'), 'css:#gpDono'),
                    $P($L('Use os filtros (pilar, **Visíveis**/Vencidas, busca) para achar o que quer tratar.', 'Use the filters (pillar, **Visible**/Overdue, search) to find what you want to handle.', 'Use los filtros (pilar, **Visibles**/Vencidas, búsqueda) para encontrar lo que quiere tratar.'), [], 'css:#gpSit'),
                    $P($L('Marque as pendências e clique em **Ocultar selecionadas** — ou em **Limpar todas as filtradas** para ocultar tudo o que está na lista.', 'Tick the pending items and click **Hide selected** — or **Clear all filtered** to hide everything in the list.', 'Marque los pendientes y haga clic en **Ocultar seleccionadas** — o en **Limpiar todas las filtradas** para ocultar todo lo de la lista.'), [], 'css:[data-gp-acao="ocultar"]'),
                    $P($L('Escreva o **Motivo** (ex.: "Licença encerrada: a unidade foi desativada em outubro.") e clique em **Confirmar**.', 'Write the **Reason** (e.g.: "License closed: the unit was deactivated in October.") and click **Confirm**.', 'Escriba el **Motivo** (ej.: "Licencia cerrada: la unidad fue desactivada en octubre.") y haga clic en **Confirmar**.'),
                        $L('As pendências passam a "Oculta" e somem de Minhas pendências, do sino, da Agenda e dos Próximos prazos da pessoa.', 'The items become "Hidden" and disappear from the person\'s My pending items, bell, Agenda and Upcoming deadlines.', 'Los pendientes pasan a "Oculta" y desaparecen de Mis pendientes, la campana, la Agenda y los Próximos plazos de la persona.'), 'css:#gpMotivo', false),
                ],
            ],
            [
                'id' => 'inicio-gestao-nao-gerar',
                'titulo' => $L('Desligar as pendências de um registro ou de uma pessoa', 'Switch off pending items of a record or a person', 'Desactivar los pendientes de un registro o de una persona'),
                'resumo' => $L('Fazer um registro deixar de gerar pendências para todos, ou parar as pendências de alguém em férias ou afastado.', 'Make a record stop generating pending items for everyone, or pause someone\'s pending items during vacation or leave.', 'Hacer que un registro deje de generar pendientes para todos, o pausar los de alguien de vacaciones o de licencia.'),
                'antes' => $L('Desligar não resolve nada no módulo: use quando a pendência não se aplica (ex.: equipamento desativado) ou a pessoa está fora.', 'Switching off does not solve anything in the module: use it when the item does not apply (e.g. equipment deactivated) or the person is away.', 'Desactivar no resuelve nada en el módulo: úselo cuando el pendiente no se aplica (ej.: equipo desactivado) o la persona está fuera.'),
                'depois' => $L('O registro fica sem pendências até ser reativado; a pessoa, até a data escolhida (ou até reativar). Tudo vai para o Log e o responsável é avisado.', 'The record has no pending items until reactivated; the person, until the chosen date (or reactivation). Everything goes to the Log and the owner is notified.', 'El registro queda sin pendientes hasta reactivarse; la persona, hasta la fecha elegida (o hasta reactivar). Todo va al Registro y se avisa al responsable.'),
                'perfis' => ['adm', 'gestor'], 'telas' => $telas,
                'passos' => [
                    $P($L('Escolha a **Pessoa** e marque uma pendência do registro que não deve mais gerar pendências.', 'Choose the **Person** and tick one pending item of the record that should stop generating pending items.', 'Elija la **Persona** y marque un pendiente del registro que no debe generar más pendientes.'), [], 'css:#gpDono'),
                    $P($L('Clique em **Não gerar para o registro**, escreva o motivo e confirme.', 'Click **Do not generate for the record**, write the reason and confirm.', 'Haga clic en **No generar para el registro**, escriba el motivo y confirme.'),
                        $L('A pendência passa a "Registro sem pendências" — para todos os responsáveis desse registro.', 'The item becomes "Record without pending items" — for every owner of that record.', 'El pendiente pasa a "Registro sin pendientes" — para todos los responsables de ese registro.'), 'css:[data-gp-acao="registro"]', false),
                    $P($L('Para uma pessoa em férias ou afastada, clique em **Não gerar para a pessoa**, escolha a data de volta (opcional), escreva o motivo e confirme.', 'For a person on vacation or leave, click **Do not generate for the person**, choose the return date (optional), write the reason and confirm.', 'Para una persona de vacaciones o de licencia, haga clic en **No generar para la persona**, elija la fecha de regreso (opcional), escriba el motivo y confirme.'), [], 'css:[data-gp-acao="usuario"]', false),
                    $P($L('Para desfazer, abra a aba **Ocultas e desligadas** e clique em **Reativar** na linha.', 'To undo, open the **Hidden and switched off** tab and click **Reactivate** on the row.', 'Para deshacer, abra la pestaña **Ocultas y desactivadas** y haga clic en **Reactivar** en la fila.'), [], 'css:[data-bs-target="#gp-regras"]', false),
                ],
            ],
        ],
        'telas' => [
            [
                'chaves' => $telas,
                'oque' => $L('Gestão de pendências: o ADM (todos) e o gestor (a equipe) escolhem uma pessoa, veem as pendências dela e podem ocultá-las, limpá-las em lote ou desligar as pendências de um registro ou da pessoa — sempre com motivo, log e aviso ao responsável.', 'Pending items management: the ADM (everyone) and the manager (their team) choose a person, see their pending items and can hide them, clear them in bulk or switch off the pending items of a record or of the person — always with reason, log and notice to the owner.', 'Gestión de pendientes: el ADM (todos) y el gestor (su equipo) eligen una persona, ven sus pendientes y pueden ocultarlos, limpiarlos en lote o desactivar los de un registro o de la persona — siempre con motivo, registro y aviso al responsable.'),
                'pode' => [
                    ['texto' => $L('Escolher a pessoa', 'Choose the person', 'Elegir la persona'), 'alvo' => 'css:#gpDono'],
                    ['texto' => $L('Ocultar as pendências marcadas', 'Hide the ticked items', 'Ocultar los pendientes marcados'), 'alvo' => 'css:[data-gp-acao="ocultar"]'],
                    ['texto' => $L('Limpar todas as filtradas', 'Clear all filtered', 'Limpiar todas las filtradas'), 'alvo' => 'css:[data-gp-acao="limpar"]'],
                    ['texto' => $L('Não gerar mais para o registro', 'Stop generating for the record', 'No generar más para el registro'), 'alvo' => 'css:[data-gp-acao="registro"]'],
                    ['texto' => $L('Não gerar para a pessoa até uma data', 'Stop generating for the person until a date', 'No generar para la persona hasta una fecha'), 'alvo' => 'css:[data-gp-acao="usuario"]'],
                    ['texto' => $L('Reativar e consultar o Log', 'Reactivate and check the Log', 'Reactivar y consultar el Registro'), 'alvo' => 'css:[data-bs-target="#gp-regras"]'],
                ],
                'antes' => $L('Só aparece para ADMINISTRADOR, ADM DA EMPRESA e gestores. O gestor vê ele e a equipe (quem tem ele como superior, em todos os níveis).', 'Only shown to ADMINISTRADOR, ADM DA EMPRESA and managers. The manager sees themselves and their team (people who have them as superior, at every level).', 'Solo aparece para ADMINISTRADOR, ADM DA EMPRESA y gestores. El gestor ve a sí mismo y a su equipo (quien lo tiene como superior, en todos los niveles).'),
                'depois' => $L('O que for ocultado ou desligado some de Minhas pendências, do sino, da Agenda, dos Próximos prazos e do Calendário. O registro no módulo não muda.', 'What is hidden or switched off disappears from My pending items, the bell, Agenda, Upcoming deadlines and Calendar. The module record does not change.', 'Lo ocultado o desactivado desaparece de Mis pendientes, la campana, la Agenda, los Próximos plazos y el Calendario. El registro del módulo no cambia.'),
                'duvidas' => [
                    ['p' => $L('A pendência oculta volta?', 'Does a hidden item come back?', '¿El pendiente oculto vuelve?'),
                        'r' => $L('Volta sozinha se o prazo dela mudar (ex.: a licença foi renovada e venceu de novo). Pendência sem prazo fica oculta até ser reativada.', 'It comes back by itself if its deadline changes (e.g. the license was renewed and expired again). An item without deadline stays hidden until reactivated.', 'Vuelve sola si su plazo cambia (ej.: la licencia se renovó y venció de nuevo). Un pendiente sin plazo queda oculto hasta reactivarse.')],
                    ['p' => $L('Qual a diferença entre ocultar e não gerar?', 'What is the difference between hide and stop generating?', '¿Cuál es la diferencia entre ocultar y no generar?'),
                        'r' => $L('Ocultar vale para aquela pendência daquela pessoa e volta se o prazo mudar. Não gerar para o registro vale para todos os responsáveis do registro, até reativar. Não gerar para a pessoa vale para tudo dela, até a data escolhida.', 'Hide applies to that item of that person and returns if the deadline changes. Stop generating for the record applies to every owner of the record, until reactivated. Stop generating for the person applies to everything of theirs, until the chosen date.', 'Ocultar vale para ese pendiente de esa persona y vuelve si cambia el plazo. No generar para el registro vale para todos los responsables del registro, hasta reactivar. No generar para la persona vale para todo lo suyo, hasta la fecha elegida.')],
                    ['p' => $L('A pessoa fica sabendo?', 'Is the person told?', '¿La persona se entera?'),
                        'r' => $L('Sim: recebe um alerta (e e-mail, conforme as preferências dela) com o que foi feito, por quem e o motivo. Quando você mexe nas suas próprias pendências, não há aviso.', 'Yes: they get an alert (and e-mail, per their preferences) with what was done, by whom and the reason. When you change your own items, there is no notice.', 'Sí: recibe una alerta (y correo, según sus preferencias) con lo que se hizo, por quién y el motivo. Cuando usted cambia sus propios pendientes, no hay aviso.')],
                    ['p' => $L('Por que não aparecem pendências de um módulo?', 'Why do a module\'s items not appear?', '¿Por qué no aparecen pendientes de un módulo?'),
                        'r' => $L('A lista usa as suas permissões: se o seu perfil não vê a tela de um módulo, as pendências dele não aparecem aqui.', 'The list uses your permissions: if your profile cannot see a module screen, its items do not appear here.', 'La lista usa sus permisos: si su perfil no ve la pantalla de un módulo, sus pendientes no aparecen aquí.')],
                ],
            ],
        ],
    ],
];
