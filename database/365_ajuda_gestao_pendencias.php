<?php
declare(strict_types=1);

// Migração 365 — Ajuda da transação (ajuda_transacoes) da Home › Gestão de pendências, entre <!-- ml365 --> … <!-- /ml365 -->.
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

$pt = '<p><strong>Para que serve</strong></p>'
    . '<p>Os gestores e o ADM podem tirar da frente das pessoas as pendências que não fazem mais sentido, sem mexer no registro do módulo, sempre com <strong>motivo</strong>, <strong>log</strong> e <strong>aviso ao responsável</strong> (GER-18.1, 18.2 e 18.3).</p>'
    . '<p><strong>Quem usa</strong>: ADMINISTRADOR e ADM DA EMPRESA (todos os usuários ativos) e o gestor (ele e a equipe: quem tem ele como superior no cadastro do usuário, em todos os níveis).</p>'
    . '<p><strong>As ações</strong></p><ul>'
    . '<li><strong>Ocultar selecionadas</strong>: as pendências marcadas somem para a pessoa. <em>Voltam sozinhas se o prazo mudar</em> (ex.: a licença foi renovada e venceu de novo); sem prazo, ficam ocultas até reativar;</li>'
    . '<li><strong>Limpar todas as filtradas</strong>: o mesmo, para todas as pendências visíveis com os filtros atuais (limpeza em lote, um registro só no Log);</li>'
    . '<li><strong>Não gerar para o registro</strong>: o registro da pendência marcada (a licença, o monitoramento, o fornecedor…) deixa de gerar pendências para <em>todos</em> os responsáveis, até reativar;</li>'
    . '<li><strong>Não gerar para a pessoa</strong>: nada gera pendência para a pessoa até a data escolhida (vazia = até reativar) — férias, afastamento.</li></ul>'
    . '<p><strong>Como usar</strong></p><ol>'
    . '<li>Em <strong>Pessoa</strong>, escolha de quem são as pendências;</li>'
    . '<li>Filtre (pilar, Visíveis/Escondidas/Vencidas, busca) e marque as pendências;</li>'
    . '<li>Clique na ação, escreva o <strong>motivo</strong> (obrigatório) e <strong>Confirmar</strong>.</li></ol>'
    . '<p><strong>Onde vale</strong>: Minhas pendências, sino, Agenda, Próximos prazos e Calendário da barra lateral. O registro no módulo não muda (a licença continua vencida na tela de Licenças).</p>'
    . '<p><strong>Desfazer e consultar</strong>: a aba <strong>Ocultas e desligadas</strong> mostra as regras (em vigor, vencidas e reativadas) e o botão <strong>Reativar</strong> (pede motivo). A aba <strong>Log</strong> mostra quem fez o quê, quando, de quem era a pendência, quantas e o motivo.</p>'
    . '<p><strong>Aviso</strong>: a pessoa recebe um alerta (e e-mail, conforme as preferências dela) com o que foi feito, por quem e o motivo; quando você mexe nas suas próprias pendências não há aviso.</p>'
    . '<p>A lista usa as suas permissões: módulos que o seu perfil não vê não aparecem.</p>';
$en = '<p><strong>What it is for</strong></p><p>Managers and the ADM can remove from people\'s view pending items that no longer make sense, without changing the module record, always with <strong>reason</strong>, <strong>log</strong> and <strong>notice to the owner</strong> (GER-18.1, 18.2 and 18.3). Who: ADMINISTRADOR and ADM DA EMPRESA (all active users) and managers (themselves and their team, at every level).</p>'
    . '<p><strong>Actions</strong>: <strong>Hide selected</strong> (they come back if the deadline changes); <strong>Clear all filtered</strong> (bulk hide); <strong>Do not generate for the record</strong> (the record stops generating items for every owner until reactivated); <strong>Do not generate for the person</strong> (until the chosen date or reactivation). Choose the <strong>Person</strong>, filter, tick, click the action, write the reason and confirm.</p>'
    . '<p>It applies to My pending items, the bell, Agenda, Upcoming deadlines and Calendar. Undo in <strong>Hidden and switched off</strong> (Reactivate); everything is in the <strong>Log</strong>. The person gets an alert, except when you change your own items.</p>';
$es = '<p><strong>Para qué sirve</strong></p><p>Los gestores y el ADM pueden quitar de la vista de las personas los pendientes que ya no tienen sentido, sin cambiar el registro del módulo, siempre con <strong>motivo</strong>, <strong>registro</strong> y <strong>aviso al responsable</strong> (GER-18.1, 18.2 y 18.3). Quién: ADMINISTRADOR y ADM DA EMPRESA (todos los usuarios activos) y el gestor (él y su equipo, en todos los niveles).</p>'
    . '<p><strong>Acciones</strong>: <strong>Ocultar seleccionadas</strong> (vuelven si cambia el plazo); <strong>Limpiar todas las filtradas</strong> (ocultar en lote); <strong>No generar para el registro</strong> (el registro deja de generar pendientes para todos los responsables hasta reactivar); <strong>No generar para la persona</strong> (hasta la fecha elegida o reactivar). Elija la <strong>Persona</strong>, filtre, marque, haga clic en la acción, escriba el motivo y confirme.</p>'
    . '<p>Vale para Mis pendientes, la campana, la Agenda, los Próximos plazos y el Calendario. Deshaga en <strong>Ocultas y desactivadas</strong> (Reactivar); todo queda en el <strong>Registro</strong>. La persona recibe una alerta, salvo cuando usted cambia sus propios pendientes.</p>';

return [
    'gestao_pendencias' => ['titulo' => $L('Gestão de pendências', 'Pending items management', 'Gestión de pendientes'), 'texto' => $L($pt, $en, $es)],
];
