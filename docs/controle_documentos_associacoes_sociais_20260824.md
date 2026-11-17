# Validação das associações sociais do Controle

As tabelas sociais foram comparadas entre `green_modelo` e `gmodelo_dbp` antes da criação das views e dos combos.

## Resultado

As nove tabelas LAIS informadas já existem nos dois bancos. Os catálogos `lais_aspectossociais` e `lais_impactossociais`, além dos vínculos `lais_documentosxaspectos` e `lais_documentosximpactos`, possuem dados correspondentes e não foram encontrados registros órfãos em `gmodelo_dbp`. Por isso, não foi feita cópia redundante de dados.

A diferença técnica encontrada é que `gmodelo_dbp` possui chaves técnicas auto incremento nos vínculos sociais; o banco legado não possui essas colunas. O modelo de produção é o `gmodelo_dbp`, em InnoDB e utf8mb4.

## Integração

Foram criadas as views de catálogo `vw_controle_documentos_rel_aspectos_sociais` e `vw_controle_documentos_rel_impactos_sociais`, registradas na migração `CONTROLE_DOCUMENTOS_ASSOCIACOES_SOCIAIS_125`.

O grid de Controle exibe os resumos `ASPECTOS_SOCIAIS` e `IMPACTOS_SOCIAIS`. Cada associação é editada no modal CRUD padrão, com os botões acima do grid. A API usa exclusivamente as procedures `sp_crud_select`, `sp_crud_insert` e `sp_crud_delete`.

As tabelas `lais`, `lais_partes_interessadas`, `lais_revisao`, `lais_legislacaoxaspectossociais` e `lais_legislacaoximpactossociais` foram preservadas para funcionalidades LAIS e de legislação futuras; elas não são necessárias para a associação social atual dos documentos de Controle.
