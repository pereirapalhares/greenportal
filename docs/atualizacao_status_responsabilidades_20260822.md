# Atualização de status — responsabilidades

**Data:** 22/08/2026  
**Tenant:** `gmodelo_dbp` — Empresa Modelo

## Encerramento da etapa

Foram concluídas as homologações PER-006, PER-007, PER-008 e PER-009. A frente
de Homologação de Fornecedores, incluindo Gestão de Fornecedores, Gestão de
Funcionários e responsabilidades por Treinamento, está aprovada funcionalmente.

Também foi corrigido e homologado o filtro de coluna `NOME` da grade Gestão de
Funcionários. O reteste foi executado por Playwright, com 17 verificações
aprovadas e 8 evidências visuais em
`tests/evidencias_reteste_filtro_nome_funcionarios`.

## Documentos de referência

- `docs/matriz_geral_permissoes_responsabilidades_20260822.md`;
- `tests/relatorio_homologacao_PER006_responsabilidades_monitoramento_20260821.md`;
- `tests/relatorio_homologacao_PER007_responsabilidades_fornecedor_20260821.md`;
- `tests/relatorio_homologacao_PER008_responsabilidades_funcionario_20260822.md`;
- `tests/relatorio_homologacao_PER009_responsabilidades_treinamento_20260822.md`;
- `tests/evidencias_reteste_filtro_nome_funcionarios/resultado.json`.

## Próxima frente

Antes de implementar outro módulo, deve ser definida a origem legada de
`INFRAESTRUTURA` ou `CONTROLE`. Como essa decisão ainda não foi aprovada, não
deve haver migração, SQL ou interface nova para esses módulos.
