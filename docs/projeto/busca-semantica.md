# Busca semântica e reavaliação por mudança de lei (migração 346, c560)

## Decisões do usuário
- Entram os cadastros e o texto dos arquivos (OCR concluído em green_ocr_jobs).
- Os resultados aparecem junto na Pesquisa de documentos, com a chave "Também pelo sentido" (lembrada por usuário em localStorage).
- A reavaliação quando uma lei muda entrou nesta mesma etapa.

## Como funciona
- **Embeddings**: OpenAI /embeddings. O modelo vem de `GPT_AI_EMBEDDING_MODEL` (padrão text-embedding-3-small) e as dimensões de `GPT_AI_EMBEDDING_DIMENSOES` (padrão 256).
  - O vetor é quantizado em int8 e gravado em `busca_embedding` (VARBINARY + norma, passado em hex com UNHEX).
  - O cosseno é calculado em PHP (classes/BuscaSemantica.php).
- **Indexação**: GreenPortal-BuscaWorker (Agendador do Windows, sem porta). Depois do índice por palavra, chama `indexar(BUSCA_SEMANTICA_LOTE)`, padrão 1000.
  - Só revetoriza o que mudou (hash SHA1 do texto em vw_busca_semantica_textos).
  - Fica parado sem chave de IA ou com o taxímetro BLOQUEADO.
  - Contexto no IaTaximetro: BUSCA_SEMANTICA.
- **Pesquisa**: o filtro `filtros.semantico` [{id,s}] em sp_busca_pesquisar_negocios (264) usa relevância = semelhança × 100.
  - A API traz, só na 1ª página de uma pesquisa por texto, até 10 negócios parecidos que não vieram pelas palavras. Cada um tem `por_sentido` e `semelhanca`.
  - O mínimo de semelhança vem de `BUSCA_SEMANTICA_MINIMO` (padrão 0.35).
  - As permissões são as mesmas da pesquisa.
  - O vetor da consulta fica em cache de sessão por 30 min.
- **Reavaliação por lei** (classes/LeiReavaliacao.php, também no busca-worker):
  - Lê vw_lei_alteracoes, uma view sobre a base GLEGISLACAO (legislacaoxalteracao). A marca d'água fica em lei_reav_controle; na ativação ela vai para o MAX atual, então alterações antigas não geram reavaliação.
  - Lei nos requisitos: lista os requisitos da própria lei e os itens parecidos (≥ 45%, top 8). Os responsáveis do requisito (legislacaoxsituacao) recebem a tarefa LEI_REAVALIACAO (15 dias). Os responsáveis dos itens parecidos recebem alerta.
  - Lei fora dos requisitos com item parecido ≥ 55%: a tarefa vai para o ADM DA EMPRESA.
  - Sem nada parecido: SEM_IMPACTO.
  - Cada alteração roda numa transação: se falhar, é tentada de novo na próxima execução.
  - A tela é principal/modulolegal_reavaliacao_leis.php (menu MODULOLEGAL_REAVALIACAO_LEIS, ao lado de Requisitos legais, permissões copiadas dele). O detalhe abre em ?codreav=N.

## Arquivos
- database/346_busca_semantica.sql, database/346_ajuda_busca_semantica.php, scripts/aplicar-migracao-346-busca-semantica.php (com --simular, que estima tokens e custo)
- classes/BuscaSemantica.php, classes/LeiReavaliacao.php, classes/IaTaximetro.php (tipos novos)
- scripts/busca-worker.php, principal/api/busca_documentos.php, principal/busca_documentos.php, assets/js/busca-documentos.js (v14), assets/css/busca-documentos.css (v11)
- database/264_busca_documentos.sql (só sp_busca_pesquisar_negocios)

## Testes (MySQL 8 local + IA simulada)
- "barulho da fábrica" → plano de ruído 52%.
- "captação de água de poço" → outorga 64%.
- "combate a incêndio" → Brigada 76%.
- Reavaliação:
  - lei requisito: tarefa para o responsável e alerta para o dono da licença parecida;
  - portaria fora dos requisitos (55%): tarefa para o ADM;
  - lei sem relação: SEM_IMPACTO;
  - a 2ª execução não refaz nada.
