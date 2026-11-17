# Dashboard geral

Atualização: 26/08/2026.

O `principal/dashboard.php` apresenta informações gerais da empresa selecionada
no tenant. Ele não deve restringir os indicadores ao usuário autenticado.

- Licenças, situações legais e planos de ação representam a empresa inteira.
- Alertas representam os alertas gerais não lidos do tenant.
- Não conformidades representam as ocorrências abertas da empresa inteira.
- A Home (`home.php`) continua responsável pelas pendências específicas do
  usuário logado.
- As permissões continuam controlando quais módulos e indicadores podem ser
  exibidos.
- O PHP consulta somente views por meio de `sp_crud_select`.
