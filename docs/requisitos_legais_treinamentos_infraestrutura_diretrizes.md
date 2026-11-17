# Relacionamentos do requisito legal com Treinamentos e Infraestrutura

Atualização: 26/08/2026.

O grid de requisitos legais possui dois botões contextuais na barra superior:
Treinamentos e Infraestrutura. Os botões só ficam habilitados após a seleção
de uma linha do requisito legal.

Cada botão abre um popup independente com iframe e uma transação PHP própria:

- `manutencaolegalrequisitoslegais_treinamentos.php`;
- `manutencaolegalrequisitoslegais_infraestrutura.php`.

As transações recebem `CODLEGISLACAO` e `CODUNIDADE`, exibem somente as
associações daquele requisito e usam o CRUD genérico com inclusão, exclusão,
confirmação, recarga, colunas e exportação. Os botões permanecem acima do
grid; não são criados botões de operação dentro das linhas.

No banco, as relações são armazenadas em `legislacaoxtreinamentos` e
`legislacaoxinfraestruturas`, com chave técnica, chave única para impedir
duplicidade e views `vw_modulolegal_requisitos_treinamentos_grid` e
`vw_modulolegal_requisitos_infraestrutura_grid`. A migração está em
`database/148_requisitos_treinamentos_infraestrutura.sql`.

As chaves técnicas são `AUTO_INCREMENT` e o CRUD genérico as trata como
`primary_auto`, sem exigir que o usuário informe esse valor na inclusão.

O PHP não executa SQL operacional diretamente: as telas usam as views e as
procedures universais do projeto. Sessão, tenant, idioma e permissões são
validados pelo CRUD genérico.
