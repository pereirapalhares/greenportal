# CRUD independente de Controle

## Fonte legada

A configuração usada como chave foi `hpr_configuracoes.configuracoes_transacoes.dsctransacao = 'mlDocumentos'` (`codtransacao = 124`). Ela aponta para a tabela `documentos`, com chave `CODDOCUMENTO`, layout `2E`, filtro habilitado e os campos de grade:

| Campo | Rótulo legado | Configuração | Destino |
|---|---|---|---|
| `CODIGO` | Código do Documento | texto, obrigatório | editável |
| `TITULO` | Título | texto, obrigatório | editável |
| `ARQUIVO` | Arquivos | link | editável |

O `cquery` legado retorna `CODDOCUMENTO`, `CODIGO`, `TITULO` e um alias de arquivos. No portal atual, o CRUD utiliza a coluna persistida `ARQUIVO` diretamente, evitando que um alias de consulta seja confundido com uma coluna gravável.

## Implementação

- Menu `CONTROLE_DOCUMENTOS`, filho de `MODULO_LEGAL`, ativo e apontando para `principal/modulolegal_controle.php`.
- Tela independente baseada em `ManutencaoLegalCadastroGrid`.
- View `gmodelo_dbp.vw_controle_documentos_grid` para leitura do grid.
- Operações de inclusão, edição, duplicação, exclusão, recarga e exportação usam as procedures CRUD existentes.
- Permissão inicial somente para o perfil `ADMINISTRADOR`, com todas as operações do CRUD e exportação habilitadas.
- Migração/registro: `database/122_crud_controle_documentos.sql`, código `CONTROLE_DOCUMENTOS_CRUD_122`.

## Relacionamentos legados identificados

### Operação pelos botões do grid

Cada documento possui botões específicos de relacionamento. O botão abre um modal vinculado ao `CODDOCUMENTO` da linha, lista as associações atuais, permite incluir uma nova associação e permite excluir uma associação existente. A API valida sessão, permissão, tabela/campo permitido e evita duplicidades.

Endpoint: `principal/api/controle_documentos_relacionamentos.php`. O padrão detalhado está em `docs/controle_documentos_associacoes_diretrizes.md`; a modal utiliza um grid CRUD, com todos os botões acima do grid.

Adequação às diretrizes: os botões ficam na barra de ações acima do grid, e não dentro das linhas. As relações 1:N são exibidas em colunas-resumo separadas por ponto e vírgula. A modal apresenta itens relacionados e não relacionados com marcação/desmarcação; o botão Confirmar sincroniza inclusões e exclusões sem recarregar a página.

Migração complementar: `database/123_controle_documentos_grid_diretriz.sql`, registro `CONTROLE_DOCUMENTOS_GRID_DIRETRIZ_123`.

As abas configuradas para `mlDocumentos` são `mlDocumentosXUnidades`, `mlDocumentosXAtividades`, `mlDocumentosXPalavrasChave`, `mlDocumentosXAspectos`, `mlDocumentosXImpactos`, `mlDocumentosXPerigos`, `mlDocumentosXRiscos`, `mlDocumentosXAspectoSocial` e `mlDocumentosXImpactoSocial`. Os sete primeiros relacionamentos já possuem tabelas migradas para `gmodelo_dbp`; as duas abas sociais dependem das tabelas sociais correspondentes e ficaram fora do primeiro CRUD independente.

As configurações de filtros normalizados das transações filhas (unidades, atividades, aspectos, impactos, perigos, riscos e palavras-chave) foram preservadas como referência para a próxima etapa. A tela principal não copia as tabelas de `hpr_configuracoes`; ela usa a transação legada como contrato de migração e registra esse vínculo no histórico de migrações.
