# Validação do Módulo Legal — 22/09/2026

## Escopo executado

- Requisitos Legais: filtros, paginação, seleção, alteração do nível de controle e isolamento de contexto.
- Situação: inclusão, edição, duplicação, exclusão e histórico.
- Relacionamentos contextuais: abertura dos popups de situação, licenças, controles, medição e monitoramento, checklist, alterações, treinamentos, infraestrutura e homologação.
- CRUD: licenças, controles e medição/monitoramento aprovados com inclusão, alteração, duplicação e exclusão de registros de teste.
- Permissões: validação do perfil GESTOR, incluindo concessão temporária, bloqueio de menu/acesso direto e restauração da permissão original.
- Auditoria: registros de teste ativos removidos; históricos de auditoria preservados.

## Observações

- O Checklist não utiliza o CRUD genérico; funciona como grade de perguntas com botão Confirmar. Deve ser validado como fluxo específico, não como inclusão/duplicação/exclusão.
- Treinamentos e Infraestrutura utilizam o CRUD genérico e gravaram corretamente as relações de teste. O roteiro automatizado antigo não aguardou corretamente o recarregamento do grid após o salvamento; as relações foram conferidas no banco e removidas.
- A referência incorreta `ml_treinamenos` foi corrigida para `ml_treinamentos`.
- O CRUD contextual passou a importar `CODLEGISLACAO` e `CODUNIDADE` diretamente da URL para os filtros internos. Com isso, o teste atualizado confirmou persistência e exclusão nos dois popups.
- Exportação, PDF, leitura do padrão, colunas e solicitação de análise de IA responderam corretamente. O PDF foi aberto pelo visualizador `greenPortalPdfModal`; a exportação foi processada e gerou arquivo XLSX; a IA gerou alerta com link de acompanhamento.
- A matriz visual dos perfis EMITENTE, REVISOR, APROVADOR e VISITANTE confirmou menu de Requisitos oculto e HTTP 403 no acesso direto.
- A view de requisitos possui 1.523 registros, zero registros sem unidade e zero duplicidades por `CODIGO + CODUNIDADE`. Foram encontrados 23 códigos repetidos quando a unidade não é considerada, situação esperada para requisitos compartilhados entre unidades.
- A matriz CRUD confirmou GESTOR como somente leitura: alteração retorna 405 e exclusão não produz alteração. EMITENTE, REVISOR, APROVADOR e VISITANTE recebem 403 no acesso direto.
- Homologação, Alterações e os demais oito endpoints contextuais responderam HTTP 200, sem Fatal error, SQLSTATE ou PDOException, mantendo o contexto da legislação e unidade.
- A exportação 56 foi acompanhada pelo endpoint de status e concluída com 100%, gerando `Requisitos_legais_20260922_152249_Administrador_Modelo.xlsx`.
- O botão Exportar recebeu `data-permission-locked="1"` para impedir que o script genérico remova o bloqueio visual de perfis sem permissão.

## Pendências para a próxima rodada

1. Checklist: associação temporária da pergunta 144 confirmada via popup, verificada após recarga e removida com restauração do estado original.
2. Filtros combinados: cada alteração aguardou a resposta `count_rows` HTTP 200; os resultados foram 102, 102 e 1 registro, retornando a 1.095 após limpar os filtros.

## Homologação complementar — 22/09/2026

- LAIA, Correlacionamento Ambiental, Correlacionamento Ocupacional, Correlacionamento Social, Licenças e Condicionantes responderam HTTP 200 sem Fatal error, SQLSTATE ou PDOException.
- Regressão do CRUD de Situação aprovada, incluindo inclusão, edição, duplicação, exclusão, responsáveis e limpeza dos registros ativos de teste.
- Os popups contextuais foram rechecados com legislação 8 e unidade 1.
- Os seis perfis tiveram evidências visuais capturadas em `tests/evidencias-perfis-modulo-legal-20260922`.
- A tela de Requisitos foi validada em viewport móvel de 390px: sem overflow horizontal e sem botões visíveis sem título ou aria-label.
- O arquivo de exportação XLSX existe com 634.292 bytes; o endpoint de status informa `CONCLUIDA`, `percentual=100` e `active=false`.
- O filtro por unidade Modelo Manaus retornou 25 registros, todos com a unidade selecionada.

## Pendências para produção

1. Executar homologação com usuários de negócio para confirmar regras funcionais específicas de LAIA e correlacionamentos.
2. Executar os cenários específicos de Licenças e Condicionantes com dados de negócio controlados; a verificação técnica de tela, grid, seleção e popups foi aprovada.
3. Repetir o teste de isolamento em um segundo tenant/empresa quando houver dados equivalentes disponíveis. O inventário atual possui somente Modelo ativa; os demais tenants estão inativos e não foram alterados.

## Fechamento da homologação complementar — 22/09/2026

### LAIA, correlacionamentos, licenças e condicionantes

- LAIA e os três correlacionamentos (ambiental, ocupacional e social) foram acessados autenticadamente e responderam HTTP 200, sem Fatal error, SQLSTATE ou PDOException.
- Licenças e Condicionantes foram acessados autenticadamente e responderam HTTP 200, sem erro de renderização ou banco.
- A evidência atual é de smoke técnico e regressão automatizada. A aprovação funcional final ainda depende da execução por usuários de negócio.

### Segurança, permissões e isolamento

- A matriz visual foi repetida para ADMIN, GESTOR, EMITENTE, REVISOR, APROVADOR e VISITANTE.
- Para Requisitos Legais, GESTOR permanece somente leitura na configuração atual; tentativa de alteração retorna HTTP 405 e exclusão não altera o banco.
- EMITENTE, REVISOR, APROVADOR e VISITANTE não exibem o menu de Requisitos e recebem HTTP 403 no acesso direto.
- O filtro por unidade Modelo Manaus retornou 25 registros, todos pertencentes à unidade selecionada.
- O isolamento entre empresas não pôde ser concluído: não há segundo tenant ativo com dados equivalentes. Não foram feitas alterações de status ou dados para forçar o cenário.

### Retenção e limpeza

- Foi criada a diretriz `docs/retencao_exportacoes_alertas_diretrizes.md`.
- Foi implementado `scripts/manutencao-retencao-exportacoes-alertas.php`, com modo `dry-run` padrão e exclusão explícita por `--execute`.
- Política definida: exportações concluídas/erro por 30 dias; somente alertas lidos por 90 dias; itens pendentes, processando e não lidos são preservados.
- Simulação em `gmodelo_dbp`: 12 exportações elegíveis e 0 alertas elegíveis; 0 arquivos e 0 registros foram removidos.

## Decisão e critérios para publicação

**Status: HOMOLOGAÇÃO TÉCNICA APROVADA COM PENDÊNCIAS DE ACEITE FUNCIONAL.** A publicação deve permanecer condicionada aos seguintes gates:

1. aceite formal de usuários de negócio para LAIA, correlacionamentos, Licenças e Condicionantes;
2. execução do isolamento em um segundo tenant ativo, com confirmação de que empresa, unidade e usuário não atravessam dados;
3. agendamento da rotina de retenção após validação do primeiro `dry-run` em ambiente semelhante à produção;
4. execução final da matriz de permissões e tentativa de acesso direto no ambiente de publicação;
5. revisão/aprovação do relatório pelo responsável do projeto e registro das pendências acima como condição de go-live.
