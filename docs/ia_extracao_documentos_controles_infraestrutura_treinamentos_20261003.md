# Extração assistida de documentos — Controles, Infraestrutura e Treinamentos

Foi implantado o fluxo de análise assistida de PDFs para os tipos `controles`, `infraestrutura` e `treinamentos`.

Cada tipo utiliza sua própria tabela, prompt e mapeamento de campos:

| Tipo | Tabela | Prompt | Campos preenchidos |
|---|---|---|---|
| Controles | `documentos` | `CONTROLE_DOCUMENTO` | `CODIGO`, `TITULO` |
| Infraestrutura | `ml_infraestrutura` | `INFRAESTRUTURA_DOCUMENTO` | `DSCINFRAESTRUTURA` |
| Treinamentos | `ml_treinamentos` | `TREINAMENTO_DOCUMENTO` | `DSCTREINAMENTO` |

O usuário seleciona o PDF no gerenciamento de arquivos, solicita a análise, revisa a sugestão e confirma a gravação. O JSON da análise é armazenado no campo `DADOS_DOCUMENTO_IA` da respectiva tabela.

A migração `174_ia_extracao_documentos_controles_infraestrutura_treinamentos.sql` foi aplicada aos bancos `gmodelo_dbp` e `gmodelo2_dbp`. O processamento atual das transações não foi substituído; a IA apenas oferece uma sugestão antes da confirmação do usuário.

## Validações técnicas

- PHP sem erros de sintaxe nas classes e endpoints novos.
- Permissões continuam sendo verificadas no servidor.
- O registro e o arquivo são validados antes da análise.
- A gravação exige confirmação explícita do usuário.
