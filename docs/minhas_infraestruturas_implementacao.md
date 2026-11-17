# Minhas Infraestruturas

Foi criada a transação independente `minhas_infraestruturas.php`, abaixo de Módulo Legal. A tela usa `vw_minhas_infraestrutura_grid` e aplica obrigatoriamente o escopo do usuário logado.

A view considera atribuições ativas diretamente para o usuário e atribuições feitas a grupos dos quais ele participa. O cadastro geral permanece em `ml_infraestrutura`; a tela direcionada não permite incluir, duplicar ou excluir infraestruturas.

Arquivos, atributos, palavras-chave, preferências de colunas e exportação reutilizam os componentes comuns do grid. A alteração foi registrada na migração `154_minhas_infraestruturas.sql`.
