# Acompanhamento da homologação — Módulo Legal

**Projeto:** Green Portal  
**Empresa principal:** Modelo  
**Banco de trabalho:** `gmodelo_dbp`  
**Data de referência:** 22/09/2026  
**Status geral:** homologação técnica aprovada com pendências de aceite funcional e isolamento multiempresa.

## Objetivo

Registrar em um único ponto o que foi analisado, corrigido, validado e ainda precisa ser concluído no Módulo Legal, evitando perda de foco entre as rodadas de testes.

## Escopo atual

- Requisitos legais e situação dos requisitos.
- LAIA.
- Correlacionamentos ambientais, ocupacionais e sociais.
- Licenças e Condicionantes.
- Controles, Medição e Monitoramento, Checklist, Alterações, Treinamentos, Infraestrutura e Homologação.
- Exportação, PDF, colunas, alertas e análise por IA.
- Permissões por perfil, segurança, isolamento por unidade e empresa.
- Desempenho, filtros, paginação, duplicidades e registros sem unidade.

## Linha do tempo resumida

### 1. Preparação

- Diretrizes do projeto e documentos do Módulo Legal foram estudados.
- Chrome foi disponibilizado via CDP na porta local `9222`.
- Foram definidos usuários de teste e perfis: ADMINISTRADOR, GESTOR, EMITENTE, REVISOR, APROVADOR e VISITANTE.
- Testes devem usar dados controlados e remover registros operacionais temporários ao final.

### 2. Correções realizadas

- Corrigida a referência `ml_treinamenos` para `ml_treinamentos`.
- Ajustado o CRUD contextual para importar `CODLEGISLACAO` e `CODUNIDADE` da URL.
- Corrigido o salvamento de controles e o fluxo de exportação de Requisitos.
- Corrigido o bloqueio visual do botão Exportar para perfis sem permissão, evitando que o JavaScript genérico removesse o estado desabilitado.
- Confirmada a proteção de operações sem permissão no backend.

### 3. Validações realizadas

- CRUD de Situação: inclusão, edição, duplicação, exclusão, responsáveis e restauração.
- Checklist: confirmação, persistência, recarga e restauração do estado original.
- Filtros combinados: atualização AJAX aguardada explicitamente após cada alteração.
- Treinamentos e Infraestrutura: gravação, persistência e limpeza dos dados de teste.
- Popups contextuais: situação, licenças, controles, medição, checklist, alterações, treinamentos, infraestrutura e homologação.
- LAIA, correlacionamentos, Licenças e Condicionantes: smoke técnico autenticado sem erros HTTP, PHP ou banco.
- Exportação: processamento assíncrono concluído, status 100% e arquivo XLSX gerado.
- PDF, configuração de colunas, leitura do padrão e solicitação de análise por IA.
- Perfis: evidências visuais capturadas para todos os usuários de teste.
- Responsividade: tela de Requisitos validada em 390px sem overflow horizontal.
- Integridade: 1.523 requisitos, zero registros sem unidade e zero duplicidades por `CODIGO + CODUNIDADE`.
- Unidade: filtro Modelo Manaus retornou somente registros da unidade selecionada.
- Segurança: acesso direto, tentativa de alteração e tentativa de exclusão sem permissão.

## Estado por frente

| Frente | Estado | Observação |
|---|---|---|
| Requisitos Legais | Aprovado tecnicamente | CRUD, filtros, unidade, exportação e permissões validados. |
| Situação | Aprovado | Fluxo completo validado com limpeza dos testes. |
| Checklist | Aprovado tecnicamente | Confirmar e restaurar validados. |
| Treinamentos | Aprovado tecnicamente | Persistência validada; roteiro antigo foi corrigido conceitualmente para aguardar recarga. |
| Infraestrutura | Aprovado tecnicamente | Persistência validada; roteiro antigo não aguardava adequadamente a recarga. |
| LAIA | Pendente aceite funcional | Smoke técnico aprovado; falta usuário de negócio. |
| Correlacionamentos | Pendente aceite funcional | Smoke técnico e CRUD contextual histórico aprovados; falta aceite de negócio. |
| Licenças | Aprovado tecnicamente | Tela, grid, seleção e popups sem erro. |
| Condicionantes | Pendente aceite funcional | Smoke técnico aprovado; regras específicas precisam de dados de negócio controlados. |
| Exportações e alertas | Aprovado tecnicamente | Status e arquivo verificados; retenção implementada em dry-run. |
| Segundo tenant | Bloqueado | Não existe outro tenant ativo equivalente; nenhum tenant foi ativado artificialmente. |

## Retenção e limpeza

Documentos relacionados:

- [retencao_exportacoes_alertas_diretrizes.md](retencao_exportacoes_alertas_diretrizes.md)
- [manutencao-retencao-exportacoes-alertas.php](../scripts/manutencao-retencao-exportacoes-alertas.php)

Política definida:

- Exportações concluídas ou com erro: 30 dias.
- Alertas lidos: 90 dias.
- Exportações pendentes/processando e alertas não lidos: preservados.
- Exclusão física limitada ao diretório `exports/`.
- Execução padrão em simulação; exclusão exige `--execute`.

Última simulação: 12 exportações elegíveis, nenhum alerta elegível e nenhuma exclusão realizada.

## Rodada de execução — 22/09/2026

| Tarefa | Resultado da rodada | Situação |
|---|---|---|
| Homologação de LAIA com negócio | Fluxos técnicos acessados sem erro; aceite de negócio requer participantes reais | Pendente externo |
| Homologação dos correlacionamentos | Ambiental, ocupacional e social acessados sem erro; aceite funcional requer participantes reais | Pendente externo |
| Licenças e Condicionantes | Tela, grid, seleção, relacionamentos e endpoints verificados | Pendente de regras de negócio |
| Segundo tenant | Não executado para evitar ativação artificial; somente Modelo está ativo | Bloqueado por ambiente |
| Retenção | `dry-run` repetido: 12 exportações elegíveis, 0 alertas, 0 exclusões | Concluído |
| Segurança e permissões | Matriz dos seis perfis, acesso direto e operações sem permissão verificados | Concluído tecnicamente |
| Aprovação formal | Relatório consolidado, aguardando aprovação do responsável do projeto | Pendente externo |
| Responsáveis e critérios | Registrados na matriz abaixo | Concluído documentalmente |
| Checklist de produção | Preparado abaixo | Concluído documentalmente |

## Matriz de responsáveis, prazo e aceite

| Entrega | Responsável sugerido | Prazo | Critério de aceite | Estado |
|---|---|---|---|---|
| LAIA | Usuário de negócio / responsável Legal | A definir | Cenários reais executados e aceite registrado | Pendente |
| Correlacionamentos | Usuários Ambiental, Ocupacional e Social | A definir | Inclusão, consulta, alteração e regras aprovadas | Pendente |
| Licenças e Condicionantes | Responsável Legal | A definir | Regras de validade, vínculo, unidade e condicionantes aprovadas | Pendente |
| Segundo tenant | Infraestrutura / administrador de ambientes | A definir | Dados de duas empresas não se cruzam | Bloqueado |
| Retenção | Infraestrutura / DBA | Antes do go-live | `dry-run` aprovado, agendamento monitorado e rollback definido | Pendente |
| Segurança | Segurança / administrador | Antes do go-live | Matriz e acessos diretos aprovados em ambiente de publicação | Pendente |
| Relatório final | Responsável do projeto | Antes do go-live | Aprovação formal registrada | Pendente |

## Checklist final de publicação

- [ ] Aceite de negócio registrado para LAIA.
- [ ] Aceite de negócio registrado para os três correlacionamentos.
- [ ] Regras de Licenças e Condicionantes aprovadas.
- [ ] Segundo tenant ativo e teste de isolamento concluído.
- [ ] Backup e plano de reversão confirmados.
- [ ] Matriz de permissões revisada no ambiente de publicação.
- [ ] Acesso direto a URLs sensíveis testado.
- [ ] Rotina de retenção executada em `dry-run` e agendada.
- [ ] Monitoramento de exportações e alertas confirmado.
- [ ] Evidências anexadas ao relatório.
- [ ] Pendências classificadas com responsável e prazo.
- [ ] Aprovação formal do relatório obtida.
- [ ] Autorização de go-live registrada.

## Rodada de fechamento — 22/09/2026

| Item solicitado | Execução possível nesta sessão | Resultado |
|---|---|---|
| Aceite funcional de LAIA | Não é possível substituir a avaliação de usuários de negócio | Aguardando responsável Legal |
| Aceite dos correlacionamentos | Evidência técnica existente; aceite humano não pode ser simulado | Aguardando usuários Ambiental, Ocupacional e Social |
| Aprovação de Licenças e Condicionantes | Verificação técnica concluída; aprovação das regras depende do responsável funcional | Aguardando aceite |
| Segundo tenant ativo | O inventário disponível não possui outro tenant ativo equivalente | Bloqueado; não ativar empresa sem autorização |
| Retenção | `dry-run` executado novamente; rotina pronta para agendamento | Agendamento pendente de Infraestrutura/DBA |
| Matriz de permissões | Matriz técnica já repetida para os seis perfis | Requer confirmação no ambiente de publicação |
| Aprovação formal do relatório | Não pode ser assinada pelo agente executor | Aguardando responsável do projeto |
| Responsáveis e prazos | Responsáveis sugeridos registrados; prazos dependem do calendário do projeto | Aguardando definição formal |
| Checklist de go-live | Checklist concluído documentalmente, mas permanece bloqueado por itens obrigatórios | Não autorizar publicação |

### Decisão de go-live

**GO-LIVE NÃO AUTORIZADO nesta rodada.** Permanecem bloqueadores objetivos: aceite funcional humano, segundo tenant para isolamento, confirmação no ambiente de publicação e aprovação formal do relatório. O checklist deve ser reaberto e marcado somente após evidência de cada aceite.

## Rodada administrativa — 22/09/2026

As ações abaixo dependem de pessoas, calendário, infraestrutura ou autoridade formal. Elas foram registradas, mas não podem ser consideradas concluídas pelo agente executor:

| Nº | Ação | Responsável necessário | Dependência | Estado |
|---:|---|---|---|---|
| 1 | Agendar aceite de LAIA | Responsável do projeto / usuário Legal | Agenda e participantes | Aguardando agendamento |
| 2 | Agendar aceite dos correlacionamentos | Responsáveis Ambiental, Ocupacional e Social | Agenda e dados de negócio | Aguardando agendamento |
| 3 | Aprovar Licenças e Condicionantes | Responsável funcional Legal | Execução e assinatura do aceite | Aguardando aprovação |
| 4 | Disponibilizar segundo tenant | Infraestrutura / DBA | Tenant ativo e dados equivalentes | Bloqueado |
| 5 | Executar isolamento entre tenants | QA / Segurança | Item 4 concluído | Bloqueado |
| 6 | Agendar retenção | Infraestrutura / DBA | Aprovação do `dry-run` e janela operacional | Aguardando aprovação |
| 7 | Repetir permissões em publicação | QA / Segurança / Administrador | Ambiente de publicação disponível | Aguardando ambiente |
| 8 | Definir responsáveis e prazos | Gerente do projeto | Calendário e aceite dos responsáveis | Aguardando definição |
| 9 | Aprovar relatório | Responsável do projeto | Pendências 1–8 tratadas ou formalmente aceitas | Aguardando assinatura |
| 10 | Revalidar checklist e autorizar go-live | Comitê/responsável de publicação | Todos os gates anteriores | Bloqueado |

### Resultado da rodada

Nenhuma convocação, aprovação, ativação de tenant ou autorização de publicação foi simulada. O status permanece **GO-LIVE NÃO AUTORIZADO** até existirem evidências formais para os itens 1 a 10.

## Homologação simulada por usuários de teste — 22/09/2026

Execução realizada via Chrome/CDP com a senha de teste autorizada, sem criação ou alteração de registros:

- 7 logins aprovados.
- 42 acessos diretos verificados: 6 rotas por usuário.
- 0 erros Fatal, PDOException, SQLSTATE ou Call Stack.
- ADMIN: 6 rotas liberadas com HTTP 200 — LAIA, Ambiental, Ocupacional, Social, Licenças e Condicionantes.
- UEDITOR, UREVISOR, UAPROVADOR, GESTOR, RESPONSAVEL e VISITANTE: 36 acessos bloqueados com HTTP 403, conforme a configuração atual.
- Screenshots individuais gerados para os sete usuários.

Resultado: **APROVADO TECNICAMENTE / ACEITE FUNCIONAL SIMULADO**. Esta execução comprova o comportamento técnico dos perfis, mas não substitui a aprovação formal de usuários de negócio.

Evidências:

- Resultado JSON: `tests/resultado-homologacao-simulada-modulo-legal-20260922.json`
- Capturas: `tests/evidencias-homologacao-simulada-modulo-legal-20260922`
- Script reproduzível: `scripts/teste-homologacao-simulada-modulo-legal-cdp.js`

## Revisão da matriz e CRUD por perfil — 22/09/2026

A matriz real do banco `gmodelo_dbp` foi consultada para LAIA, correlacionamentos e Licenças:

- ADMINISTRADOR: visualização, inclusão, edição e exclusão habilitadas nos módulos principais.
- GESTOR: sem visualização nos módulos LAIA/correlacionamentos; Licenças permanece sem visualização, com exportação/PDF configurados no banco, mas inacessíveis sem a permissão de visualizar.
- EMITENTE, REVISOR, APROVADOR e VISITANTE_DOCUMENTOS: sem visualização ou operações CRUD nos módulos analisados.
- Condicionantes é relacionamento contextual de Licenças; não possui autorização de menu independente.

Conclusão: os 36 HTTP 403 observados estão coerentes com a matriz atualmente cadastrada. Não foram alteradas permissões, pois não havia divergência comprovada entre a configuração e o comportamento esperado documentado. Por isso, não houve necessidade de uma nova rodada após ajuste.

O CRUD foi validado por autorização: ADMINISTRADOR permanece habilitado; os demais perfis não chegam ao endpoint autorizado e, portanto, não podem incluir, editar ou excluir. A validação destrutiva foi evitada para perfis sem permissão e nenhum dado foi alterado.

O isolamento entre unidades do tenant Modelo permanece aprovado na evidência anterior: Modelo Manaus retornou somente registros da unidade selecionada. O isolamento entre empresas continua bloqueado pela inexistência de segundo tenant ativo equivalente. O ambiente semelhante à produção e a aprovação formal continuam externos a esta execução.

## Pendências prioritárias

1. Agendar usuários de negócio para homologar LAIA, correlacionamentos, Licenças e Condicionantes.
2. Obter um segundo tenant ativo com dados equivalentes e repetir o teste de isolamento.
3. Executar a rotina de retenção em ambiente semelhante à produção, inicialmente em `dry-run`.
4. Repetir a matriz de segurança no ambiente de publicação.
5. Fazer revisão e aprovação formal do relatório final.

## Critérios de publicação

A publicação somente deve ocorrer quando:

- o aceite funcional estiver registrado;
- o isolamento entre dois tenants tiver evidência aprovada;
- a matriz de permissões estiver aprovada no ambiente de publicação;
- a retenção estiver agendada e monitorada;
- as pendências restantes tiverem responsável, prioridade e prazo;
- o relatório final tiver aprovação do responsável do projeto.

## Regra para próximas rodadas

Cada nova rodada deve registrar neste documento:

1. data e responsável;
2. cenário executado;
3. dados de teste utilizados;
4. resultado esperado e resultado obtido;
5. evidência gerada;
6. correção aplicada, se houver;
7. impacto nas pendências e no critério de produção.

## Documentos de evidência

- [Relatório técnico detalhado](validacao_modulo_legal_20260922.md)
- [Diretrizes de retenção](retencao_exportacoes_alertas_diretrizes.md)
- Evidências visuais: `tests/evidencias-perfis-modulo-legal-20260922`
- Evidência responsiva: `tests/evidencias-responsivo-20260922/requisitos-mobile.png`
- Evidências da regressão de Situação: `tests/evidencias-regressao-crud-situacao-20260922`
