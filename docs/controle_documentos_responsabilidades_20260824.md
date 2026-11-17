# Responsáveis do Controle

O Controle utiliza o modelo comum de responsabilidades do `gmodelo_dbp`.

## Modelo de dados

Cada vínculo é gravado em `responsabilidade_atribuicoes` com:

- `CODMODULO` referente ao módulo `CONTROLE`;
- `ENTIDADE = 'documentos'`;
- `REGISTRO_ID = CODDOCUMENTO`;
- destino `USUARIO` ou `GRUPO`;
- prioridade, indicador de principal e vigência.

A view `vw_responsabilidade_controle_documentos` fornece os vínculos ativos para o modal e o resumo `RESPONSAVEIS` do grid.

## Regras

- `ADM_DA_EMPRESA` pode incluir e excluir responsáveis;
- `GESTOR` pode consultar, mas não alterar;
- somente usuários e grupos ativos podem ser escolhidos;
- exclusões são históricas, alterando `ATIVO` para zero;
- o PHP usa somente views, procedures `sp_crud_select`, `sp_crud_insert`, `sp_crud_update` e `sp_responsabilidade_validar_duplicidade`.

A implementação foi registrada pela migração `CONTROLE_DOCUMENTOS_RESPONSABILIDADES_127`.
