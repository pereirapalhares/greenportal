# Histórico de situação dos requisitos legais

## Objetivo

Registrar alterações e exclusões de situações dos requisitos legais, mantendo
o estado completo anterior, o usuário e a data da operação.

O histórico específico de licenças fica fora desta etapa.

## Persistência

Origem legada: `E:\wamp\www\configuracoes\situacaoxhistorico.php`, tabela
`green_modelo.situacaoxhistorico`.

Destino: `gmodelo_dbp.situacaoxhistorico`, com as migrações:

- `database/136_migrar_historico_situacao_requisitos.sql`;
- `database/140_historico_situacao_registro_anterior.sql`.

A migração 140 acrescenta `registroanterior` como JSON, contendo o registro
completo de `legislacaoxsituacao` imediatamente antes da alteração ou
exclusão.

## Regras de auditoria

Antes de uma alteração com mudança real ou antes de uma exclusão, são gravados:

- `CODSITUACAO`: código da situação afetada;
- `DESCRICAO`: `dscsituacao` anterior;
- `codusuario`: usuário autenticado;
- `DATAHISTORICO`: data corrente;
- `registroanterior`: registro anterior completo em JSON.

O `CODHISTORICO` é gerado pelo banco. Inclusões não geram histórico, pois não
possuem registro anterior.

## Consulta e popup

O fluxo é `modulolegal_requisitoslegais.php` → situação → histórico. O
histórico abre em popup independente, sobre o popup de situação, por iframe.
Ao fechar o histórico, o popup de situação permanece aberto. O título usa o
formato `<código da legislação> - <descrição da situação>`.

A view `vw_modulolegal_requisitos_situacaohistorico_grid` usa `LEFT JOIN` com
`legislacaoxsituacao`, preservando históricos de situações excluídas.

## Campos e permissões

Somente estas colunas são visíveis: Descrição, Data do Histórico e Usuário.
Na interface, a data usa `dd/mm/yyyy` em PT e ES e `mm/dd/yyyy` em EN. A
coluna `CODUSUARIO` permanece visível, com o cabeçalho Usuário, mas exibe o
nome cadastrado; o campo auxiliar `USUARIO` permanece oculto.
Os campos técnicos, incluindo `CODHISTORICO`, `CODSITUACAO` e
`registroanterior`, permanecem ocultos.

`modulolegal_requisitoslegais_situacaohistorico.php` é somente consulta: não
possui Novo, Salvar, Duplicar ou Excluir. O teste CRUD anterior deixou de ser
aplicável e deve ser substituído por teste da auditoria na alteração e
exclusão de situação.
