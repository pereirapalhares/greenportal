# Tuning de performance dos bancos `_dbp`

Data da avaliação: 26/08/2026.

## Escopo

Foram avaliados `gmodelo_dbp`, `glegislacao_dbp` e `guniversal_dbp`, com foco nos grids, relacionamentos do Módulo Legal, documentos e consultas usadas pelos serviços do GreenPortal.

## Diagnóstico

- MySQL 8.4.7, com `performance_schema` e log de consultas lentas habilitados.
- `gmodelo_dbp`: aproximadamente 34 MB; `glegislacao_dbp`: aproximadamente 110 MB; `guniversal_dbp`: aproximadamente 0,2 MB.
- A maior parte das tabelas está em InnoDB.
- Existem tabelas legadas MyISAM em `gmodelo_dbp`; elas não foram convertidas automaticamente para evitar alteração de comportamento sem evidência de uso.
- A procedure `sp_crud_select` não utiliza `SQL_NO_CACHE`; ocorrências observadas de `SELECT SQL_NO_CACHE` pertencem a diagnósticos/testes.
- Algumas tabelas de relacionamento não possuíam índice reverso ou composto adequado para filtros por entidade relacionada e unidade.

## Alterações aplicadas

A migration `database/146_tuning_indices_dbp.sql` foi aplicada e registrada em `gmodelo_dbp.registros_migracao` com status `APLICADO`.

Foram criados índices idempotentes em:

- relacionamentos de documentos por palavra-chave, unidade, atividade, perigo e risco;
- relacionamentos de legislação por legislação/unidade para documentos, aspectos, atividades, impactos, perigos, riscos e licenças.

Também foram atualizadas as estatísticas das tabelas críticas com `ANALYZE TABLE`.

## Validação

O `EXPLAIN` de consulta por palavra-chave passou a utilizar `ix_dxp_palavra_documento` com acesso `ref` e `Using index`.

## Itens para monitoramento futuro

1. Avaliar conversão controlada das tabelas MyISAM para InnoDB após confirmar que não existem integrações legadas dependentes do mecanismo.
2. Medir consultas reais no log lento durante uso normal, sem basear decisões apenas em diagnósticos com `SQL_NO_CACHE`.
3. Revisar o plano da view `vw_modulolegal_requisitos_grid` com filtros reais de empresa/unidade; a consulta sem filtro materializa muitos registros e não representa o fluxo normal do usuário.
4. Ajustar `innodb_buffer_pool_size` somente após medir a memória disponível no servidor de produção.
