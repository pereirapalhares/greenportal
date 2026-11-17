# Agenda de tarefas do usuário

Atualização: 26/08/2026.

O card de próximas tarefas da `home.php` abre a transação independente
`principal/painel_calendario.php`. Essa tela consulta `vw_dashboard_situacoes`
e `vw_dashboard_planos_acao` por meio de `sp_crud_select`, filtrando pelo
usuário autenticado.

A agenda exibe calendário mensal, eventos por data e uma lista das tarefas do
mês. Situações não atendidas e planos de ação não concluídos são apresentados
como programados ou atrasados. O dashboard geral não deve assumir essa função.
