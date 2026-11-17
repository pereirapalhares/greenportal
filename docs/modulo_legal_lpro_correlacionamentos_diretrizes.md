# LPRO — correlacionamentos legados

Foram migrados e implementados os seis grids contextuais definidos nos arquivos legados `LPROXLegislacao.php`, `LPROXDocumentos.php`, `LPROXObjetivosMetas.php`, `LPROXInfraEstrutura.php`, `LPROXControlesAdministrativos.php` e `LPROXEPIS.php`.

## Grids

- Requisitos legais: Legislação e Texto da Lei; filtro por Perigo ou Risco e Unidade.
- Controles e documentos: Código, Título, Arquivo, Perigo e Risco; filtro por Perigo ou Risco e Unidade.
- Objetivos e metas: Código do Objetivo, Item Atendido da Política, Objetivo, Meta, Indicador, Líder e Estratégico/Tático; filtro por Perigo ou Risco e Unidade.
- Infraestrutura: Infraestrutura; filtro por Atividade e Unidade.
- Controles administrativos: Controle administrativo; filtro por Atividade e Unidade.
- EPIs: EPI; filtro por Atividade e Unidade.

Os campos técnicos e as chaves contextuais ficam ocultos. Os grids usam `legacy_order` e `legacy_fixed`, preservando a ordem e a visibilidade do legado.

## Chaves contextuais

Os seis botões do grid principal enviam `CODPERIGO`, `CODRISCO`, `CODATIVIDADE` e `CODUNIDADE`. `CODLPRO` identifica a linha principal, mas não substitui os relacionamentos usados pelo legado.

Perigo e risco são tratados como contexto OR quando ambos são enviados. Atividade e unidade são filtros adicionais quando aplicáveis.

## Banco

O script `database/081_modulo_legal_lpro_relacionamentos.sql` migra as tabelas legadas para `gmodelo_dbp` e cria as views:

- `vw_lpro_requisitos_legais_grid`;
- `vw_lpro_controles_documentos_grid`;
- `vw_lpro_objetivos_metas_grid`;
- `vw_lpro_infraestrutura_grid`;
- `vw_lpro_controles_administrativos_grid`;
- `vw_lpro_epis_grid`.

A migração foi executada no banco `gmodelo_dbp` e as seis views foram verificadas com registros retornados.
