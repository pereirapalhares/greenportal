# Meus Treinamentos

Foi criada a transação independente `meus_treinamentos.php` no Módulo Legal.

O cadastro principal continua em `ml_treinamentos`. A visão do usuário é
formada por `vw_minhas_treinamentos_grid`, que relaciona cada treinamento a
uma atribuição ativa em `responsabilidade_atribuicoes` com
`ENTIDADE = 'ml_treinamentos'`.

A tela é restrita ao usuário logado e mantém o padrão do grid de Treinamentos,
com consulta, edição permitida pelo perfil, arquivos, palavras-chave e
exportação. A inclusão e a exclusão ficam disponíveis no cadastro geral de
Treinamentos, evitando criar registros sem responsável.

O menu, a view e as permissões foram instalados pela migração
`database/151_minhas_treinamentos.sql`.
