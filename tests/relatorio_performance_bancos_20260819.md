===============================================================================
GREEN PORTAL - RELATORIO DE ANALISE DE PERFORMANCE DE BANCOS DE DADOS
Bancos analisados: gmodelo_dbp, glegislacao_dbp, guniversal_dbp
Servidor: MySQL 8.4.7 Community Server / phpMyAdmin 5.2.3
Data da analise: 19/08/2026
Metodo: consultas diretas ao information_schema e SHOW VARIABLES via phpMyAdmin
===============================================================================

-------------------------------------------------------------------------------
0. VISAO GERAL DOS BANCOS
-------------------------------------------------------------------------------

Banco            | Tabelas | Nao-InnoDB | Colacao != utf8mb4_0900_ai_ci | Dados   | Indices
------------------|---------|------------|--------------------------------|---------|--------
gmodelo_dbp       | 81      | 6          | 9                              | 3.8 MB  | 1.6 MB
glegislacao_dbp   | 18      | 0          | 0                              | 90.5 MB | 13.8 MB
guniversal_dbp    | 3       | 0          | 0                              | 0.0 MB  | 0.1 MB

Os tres bancos tem o collation padrao do SCHEMA corretamente configurado como
utf8mb4_0900_ai_ci (confirmado via information_schema.schemata). Isso significa
que os problemas de colacao encontrados sao pontuais, em tabelas legadas
especificas, e nao um erro de configuracao geral do banco - novas tabelas
criadas sem COLLATE explicito ja herdam o padrao correto.

guniversal_dbp esta enxuto, totalmente InnoDB, com colacao correta e todas as
tabelas indexadas - nenhum problema encontrado neste banco.

===============================================================================
1. CRITICO
===============================================================================

1.1 Tabelas sem NENHUM indice (nem sequer chave primaria) - 19 tabelas

Consulta ao information_schema.statistics mostra 19 tabelas, em dois dos tres
bancos, que nao possuem absolutamente nenhum indice cadastrado - nem PRIMARY
KEY. Isso significa que toda leitura, atualizacao ou exclusao nessas tabelas
faz table scan completo, e que nada impede duplicidade de linhas (nao ha
garantia de unicidade, o que e especialmente grave em tabelas de
relacionamento N:N).

glegislacao_dbp (3 tabelas):
  - legislacaoxpalavraschave   36.656 linhas  <- tabela grande, provavelmente
                                                  usada em busca de legislacao
                                                  por palavra-chave; sem indice
                                                  isso e busca linear a cada
                                                  consulta
  - legislacaopaixlegislacaofilha   484 linhas
  - unidades                          32 linhas

gmodelo_dbp (16 tabelas, todas de relacionamento/apoio):
  - documentosxaspectos              461 linhas
  - documentosximpactos                3 linhas
  - lais_documentosxaspectos           2 linhas
  - lais_documentosximpactos           2 linhas
  - li_licencasxpalavraschave          5 linhas
  - lpro_abrangencia                   3 linhas
  - lpro_natureza_do_perigo            7 linhas
  - lpro_situacao                      3 linhas
  - lpro_tipo_de_atividade             7 linhas
  - mm_medicaoxlaudo                  32 linhas
  - mm_medicaoxlegislacao             44 linhas
  - mm_medicaoxpalavrachave            0 linhas
  - mm_monitoramentoxlegislacao       25 linhas
  - objetivosmetasxaspectos           85 linhas
  - objetivosmetasxaspectossociais     4 linhas
  - vlaiaxlegislacao                  966 linhas  (tambem esta na lista 1.2,
                                                     ver abaixo)

Sugestao: adicionar PRIMARY KEY (surrogate id ou chave composta, conforme o
padrao ja usado nas demais tabelas xrelacionamento do projeto) e, quando
aplicavel, indices/FOREIGN KEY nas colunas usadas em JOIN. Priorizar
legislacaoxpalavraschave pelo volume de linhas e por ser candidata natural a
uso em busca textual.

-------------------------------------------------------------------------------
1.2 gmodelo_dbp: 6 tabelas em MyISAM (deveriam ser InnoDB)

  - documentosxunidades      55 linhas
  - legislacaoxchecklist    184 linhas
  - legislacaoxdocumentos    36 linhas
  - legislacaoxempresa     1.724 linhas
  - planodeacao               1 linha
  - vlaiaxlegislacao         966 linhas

MyISAM nao suporta transacoes, FOREIGN KEY, nem recuperacao apos falha (crash
recovery), e usa lock em nivel de tabela (nao de linha). Isso contraria as
diretrizes do projeto, que definem PDO com transacoes e integridade
referencial como padrao. Em um sistema de compliance legal (onde perda de
dados ou escrita parcial e inaceitavel), manter tabelas em MyISAM e um risco
real, nao apenas uma questao de performance.

Sugestao: ALTER TABLE ... ENGINE=InnoDB nas 6 tabelas acima, testando em
ambiente de homologacao antes (MyISAM->InnoDB pode revelar duplicidades que o
MyISAM nunca bloqueou, ver item 1.1).

-------------------------------------------------------------------------------
1.3 gmodelo_dbp: 9 tabelas com colacao divergente (latin1_swedish_ci)

  - documentos                37 linhas
  - documentosxaspectos      461 linhas
  - documentosxatividades    100 linhas
  - documentosximpactos        3 linhas
  - objetivosmetasxaspectos   85 linhas
  - documentosxunidades       55 linhas
  - legislacaoxchecklist     184 linhas
  - legislacaoxdocumentos     36 linhas
  - planodeacao                1 linha

Todas essas tabelas usam latin1_swedish_ci em vez de utf8mb4_0900_ai_ci (o
padrao definido nas diretrizes do projeto e ja aplicado no restante do
banco). Colacao divergente entre tabelas relacionadas forca conversao
implicita em JOINs e comparacoes (o que impede o uso de indice mesmo quando
ele existe) e, mais grave, risca perda/corrupcao de caracteres para qualquer
acentuacao ou caractere fora do Latin-1 - o mesmo tipo de problema ja
identificado no relatorio de codigo anterior (character corrompido em
ModuloLegalRequisitosGrid.php, linha 80: "Código do §mbito").

Sugestao: converter as 9 tabelas para utf8mb4_0900_ai_ci
(ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci),
com backup previo e checagem de dados apos a conversao, pois strings ja
gravadas incorretamente em latin1 podem exigir tratamento manual.

===============================================================================
2. ALTO
===============================================================================

2.1 slow_query_log desativado

  slow_query_log = OFF
  long_query_time = 10.000000 (segundos)

Hoje nao ha nenhuma visibilidade sobre quais consultas estao lentas em
producao. Mesmo que o log seja ativado, o limiar padrao de 10s e alto demais
para um portal cujas proprias diretrizes definem grids com debounce de 1000ms
e paginacao de 25 linhas - uma consulta de grid que leva 2-3s ja e um
problema de experiencia, mas nao apareceria no slow log.

Sugestao: ativar slow_query_log em homologacao (e, com cautela, em producao)
e reduzir long_query_time para algo entre 1 e 2 segundos, revisando
periodicamente o log gerado.

-------------------------------------------------------------------------------
2.2 glegislacao_dbp: possiveis estatisticas desatualizadas em tabelas grandes

A tabela legislacaoxalteracao (26.015 linhas, indice ix_lxa_legislacao_data
sobre codlegislacao+data_da_alteracao) aparece no information_schema.tables
com index_length = 0.00 MB, o que e inconsistente com a existencia de um
indice secundario sobre uma tabela desse tamanho. Isso e um forte indicio de
estatisticas persistentes desatualizadas (comum quando ANALYZE TABLE nunca
foi executado apos cargas grandes de dados), e nao necessariamente ausencia
real do indice.

Sugestao: executar ANALYZE TABLE nas tabelas maiores de glegislacao_dbp
(legislacao, legislacaoxalteracao, legislacaoxassunto, legislacaoxpergunta,
legislacaoxpalavraschave apos receber indice) para que o otimizador tenha
estatisticas corretas, e validar com EXPLAIN as consultas mais usadas pelas
telas de legislacao.

-------------------------------------------------------------------------------
2.3 glegislacao_dbp: tabelas grandes com apenas a chave primaria

  - assuntos    21.829 linhas - unico indice: PRIMARY (CODASSUNTO)
  - perguntas    9.292 linhas - unico indice: PRIMARY (CODPERGUNTA)

Diferente do item 1.1, essas tabelas TEM chave primaria (por isso o
index_length = 0.00 MB e esperado - no InnoDB a PK e a propria tabela,
clustered index). O ponto de atencao aqui e outro: se a aplicacao filtra ou
pesquisa essas tabelas por qualquer coluna alem da PK (nome do assunto, texto
da pergunta, etc.), essas buscas fazem table scan. Como nao foi feita analise
do codigo-fonte das telas que consultam essas tabelas nesta etapa, recomenda-
se validar com EXPLAIN os filtros realmente usados nas grids/telas de
Assuntos e Perguntas antes de decidir por novos indices.

===============================================================================
3. MEDIO
===============================================================================

3.1 innodb_log_file_size pequeno (20 MB)

Esse e o tamanho de cada arquivo de redo log do InnoDB. 20 MB e um valor
tipico de instalacoes MySQL 5.x antigas; em versoes recentes (MySQL 8.4) o
padrao recomendado costuma ser bem maior. Um redo log pequeno aumenta a
frequencia de checkpoints, o que pode gerar picos de I/O em cargas de escrita
mais intensas (importaçoes em lote, migracao de dados, exportacoes Excel
gravando resultado intermediario etc.).

Sugestao: aumentar innodb_redo_log_capacity (equivalente atual ao antigo
innodb_log_file_size x innodb_log_files_in_group) para 256-512 MB, ajustando
conforme espaco em disco disponivel no servidor.

-------------------------------------------------------------------------------
3.2 innodb_buffer_pool_size no valor padrao (256 MB)

Hoje os tres bancos somam ~95 MB de dados + indices, entao o buffer pool
atual (256 MB) comporta tudo em memoria sem problema - isto NAO e um problema
no momento. Fica como ponto de atencao para o futuro: a medida que
glegislacao_dbp crescer (ja e o maior banco, 90+ MB), o buffer pool deve ser
revisado para continuar comportando o working set em memoria (regra geral:
60-70% da RAM disponivel no servidor dedicado ao MySQL).

-------------------------------------------------------------------------------
3.3 tmp_table_size / max_heap_table_size em 16 MB

As diretrizes do projeto mencionam uso de GROUP_CONCAT nas views de grid
(ex.: vw_legislacao_grid). Consultas com GROUP_CONCAT, GROUP BY ou ORDER BY
que excedam 16 MB de tabela temporaria em memoria fazem spill para disco
(tabela temporaria em disco), o que e sensivelmente mais lento.

Sugestao: quando o ambiente estiver sob carga real, monitorar a variavel de
status Created_tmp_disk_tables; se estiver alta, aumentar tmp_table_size e
max_heap_table_size (ex.: para 64 MB) e reavaliar.

===============================================================================
4. OBSERVACOES / PONTOS POSITIVOS
-------------------------------------------------------------------------------

  - innodb_file_per_table = ON: cada tabela InnoDB tem seu proprio arquivo
    .ibd, facilitando manutencao, backup granular e recuperacao de espaco.

  - innodb_flush_log_at_trx_commit = 1: configuracao mais segura (durabilidade
    total ACID a cada commit). Para um sistema de compliance legal, esta e a
    escolha correta e NAO deve ser relaxada para 2 ou 0 em troca de
    performance, mesmo que isso custe algum throughput de escrita.

  - guniversal_dbp esta bem modelado: enxuto, 100% InnoDB, colacao correta,
    todas as tabelas indexadas.

  - A maioria das tabelas dos tres bancos (65 de 81 em gmodelo_dbp; 15 de 18
    em glegislacao_dbp) ja esta em InnoDB, com colacao correta e indices
    presentes - os problemas encontrados sao localizados, nao sistemicos.

  - table_open_cache (4000), max_connections (151) e max_allowed_packet
    (64 MB) estao em valores razoaveis, sem necessidade de ajuste imediato.

===============================================================================
5. PROXIMOS PASSOS SUGERIDOS (EM ORDEM DE PRIORIDADE)
-------------------------------------------------------------------------------

  1. [CRITICO] Adicionar PRIMARY KEY (e indices/FK quando aplicavel) as 19
     tabelas sem nenhum indice (item 1.1), priorizando
     legislacaoxpalavraschave pelo volume de linhas.

  2. [ALTO] Converter as 6 tabelas MyISAM de gmodelo_dbp para InnoDB (item
     1.2), testando em homologacao por causa do risco de duplicidades
     ocultas.

  3. [ALTO] Corrigir a colacao das 9 tabelas latin1_swedish_ci de gmodelo_dbp
     para utf8mb4_0900_ai_ci (item 1.3), com backup previo.

  4. [ALTO] Ativar slow_query_log em homologacao com long_query_time menor
     (1-2s) para identificar consultas lentas reais (item 2.1).

  5. [MEDIO] Rodar ANALYZE TABLE nas tabelas grandes de glegislacao_dbp e
     validar com EXPLAIN as consultas das telas de Legislacao, Assuntos e
     Perguntas (itens 2.2 e 2.3).

  6. [MEDIO] Revisar innodb_redo_log_capacity antes da entrada em producao
     (item 3.1).

  7. [BAIXO] Monitorar buffer pool e tabelas temporarias em disco assim que
     houver carga real de uso, e ajustar innodb_buffer_pool_size /
     tmp_table_size conforme necessario (itens 3.2 e 3.3).

===============================================================================
FIM DO RELATORIO
===============================================================================
