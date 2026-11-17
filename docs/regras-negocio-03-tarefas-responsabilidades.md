# Regras de negócio — Gestão de tarefas, responsabilidades e questionários

Atualizado em 24/10/2026. Documento de referência: o que o sistema faz hoje e as decisões do usuário. Legenda: [FEITO], [PENDENTE], [A CONFIRMAR].

Princípio geral: tudo fica no banco de cada empresa (tenant), com a mesma estrutura em todas. O catálogo de questionários é a exceção: fica no banco universal (`guniversal_dbp`). As telas leem por views (`sp_crud_select`) e gravam por procedures. O banco vem sempre da sessão; nenhum endpoint aceita banco, usuário ou empresa enviados pelo navegador.

## 1. Modelo de responsabilidades

- **RN-TAR-01** [FEITO] — A responsabilidade é uma camada separada do registro de gestão. Ela não substitui nem duplica a tabela operacional (ex.: `li_licencas` continua sendo a licença; a camada só diz quem responde por ela).
  Onde: `responsabilidade_atribuicoes`; origem: Decisão do usuário (20/08/2026), `decisoes_funcionais_responsabilidades_20260820.md`.
- **RN-TAR-02** [FEITO] — Módulos da camada: `REQUISITOS_LEGAIS`, `LICENCAS`, `MEDICAO_MONITORAMENTO`, `TREINAMENTO`, `INFRAESTRUTURA`, `CONTROLE` e `HOMOLOGACAO`. Cada módulo tem seus tipos (finalidades) em `responsabilidade_tipos`.
  Onde: `responsabilidade_modulos`, `database/093_modelo_responsabilidades_base.sql`; origem: modelo lógico 19/08/2026.
- **RN-TAR-03** [FEITO] — A Home trabalha com cinco pilares do Módulo Legal: Licenças, Medição e Monitoramento, Treinamentos, Infraestrutura e Controles. Requisitos legais e Homologação têm responsabilidade própria, fora desses cinco.
  Onde: `home_abas_pendencias_pilares_20261012.txt`, `ResponsavelAutomatico::AREAS`; origem: código.
- **RN-TAR-04** [FEITO] — Cada vínculo aponta para exatamente um destino: um usuário ou um grupo, nunca os dois e nunca nenhum.
  Onde: `responsabilidade_atribuicoes.TIPO_DESTINO`, `sp_responsabilidade_validar_destino`; origem: modelo lógico 19/08/2026.
- **RN-TAR-05** [FEITO] — O vínculo identifica o registro por `ENTIDADE` + `REGISTRO_ID` e tem origem (`DIRETA`, `TIPO`, `CATEGORIA` ou `GRUPO_PADRAO`), prioridade, indicador de principal e vigência (`VALIDO_DE`/`VALIDO_ATE`).
  Onde: `responsabilidade_atribuicoes`; origem: modelo lógico 19/08/2026.
- **RN-TAR-06** [FEITO] — Só usuário ativo e grupo ativo recebem novo vínculo. As listas de escolha mostram só ativos.
  Onde: `vw_responsabilidade_usuarios_ativos`, `vw_responsabilidade_grupos_ativos`; origem: plano da Fase 1 (19/08/2026).
- **RN-TAR-07** [FEITO] — A data final não pode ser anterior à inicial. Fora da vigência, o vínculo não conta como responsável atual, mas continua consultável.
  Onde: `sp_responsabilidade_validar_vigencia`; origem: modelo lógico 19/08/2026.
- **RN-TAR-08** [FEITO] — Não pode haver dois vínculos ativos com mesmo módulo, origem, escopo e destino em períodos sobrepostos.
  Onde: `sp_responsabilidade_validar_duplicidade`; origem: modelo lógico 19/08/2026.
- **RN-TAR-09** [FEITO] — Excluir é inativar (`ATIVO = 0`). O histórico nunca é apagado.
  Onde: telas de manutenção de responsabilidades; origem: matriz de permissões 21/08/2026.
- **RN-TAR-10** [FEITO] — Responsabilidades de usuário inativo ficam como histórico e aparecem como pendência de regularização ou delegação.
  Onde: `vw_responsabilidade_orfas`; origem: Decisão do usuário (19/08/2026).
- **RN-TAR-11** [FEITO] — Um grupo pode ter vários usuários e um usuário vários grupos, com vigência (`responsabilidade_grupoxusuario`). O campo legado `usuarios.codgrupo` continua valendo durante a transição.
  Onde: `vw_responsabilidade_grupoxusuario`; origem: modelo lógico 19/08/2026.
- **RN-TAR-12** [FEITO] — O grupo especial `TODOS` (`CODGRUPO = 99999`) significa todos os usuários ativos. É resolvido na view, sem gravar uma linha por usuário. Na tela aparece como "Todos os usuários ativos".
  Onde: views de responsabilidade; origem: Decisão do usuário (20/08/2026).
- **RN-TAR-13** [FEITO] — Responsável efetivo = vínculos diretos ao usuário + membros ativos dos grupos atribuídos, dentro da vigência. Quando há vários no mesmo item, todos são retornados; prioridade e principal só ordenam.
  Onde: `vw_responsabilidade_efetiva`, `ResponsibilityService::effectiveForUser`; origem: modelo lógico 19/08/2026.
- **RN-TAR-14** [FEITO] — Mais de um responsável principal no mesmo item é tratado como conflito e listado.
  Onde: `ResponsibilityService::conflicts`; origem: `p1-responsibility-service-20261010.md`.
- **RN-TAR-15** [FEITO] — A estrutura é replicada para cada nova empresa sem copiar dados da empresa Modelo (usuários, grupos, atribuições, delegações).
  Onde: `replicacao_responsabilidades_por_tenant_20260819.md`; origem: decisão de projeto 19/08/2026.

## 2. Origem e regras por pilar

- **RN-TAR-16** [FEITO] — Licenças: tipos `LICENCA_RESPONSAVEL`, `LICENCA_VALIDADE`, `LICENCA_REACAO`, `LICENCA_PROTOCOLO`, `LICENCA_ACESSO`. Cada campo legado (`codusuario`, `codgrupo`, `codgrupo_validade`, `codgrupo_reacao`, `codgrupo_protocolo`, `codgrupo_acesso`) vira um vínculo separado.
  Onde: `principal/licenca_responsabilidades*.php`, `vw_responsabilidade_licencas`; origem: Decisão do usuário (19 e 20/08/2026).
- **RN-TAR-17** [FEITO] — Licenças com versões: só a versão ATIVA entra nas pendências. A responsabilidade de qualquer versão da mesma licença vale para as outras (a renovação herda).
  Onde: `vw_minhas_licencas_grid` (migração 210), `ResponsavelAutomatico::viewItens`; origem: código.
- **RN-TAR-18** [FEITO] — Requisitos legais: a responsabilidade é por situação (`legislacaoxsituacao.CODSITUACAO`, tipo `REQUISITO_LEGAL`), não pela legislação nem pela unidade agrupada.
  Onde: `principal/requisito_responsabilidades*.php`; origem: `interface_consulta_responsabilidades_requisitos_20260820.md`.
- **RN-TAR-19** [FEITO] — Medição e Monitoramento: por `mm_monitoramento.CODMONITORAMENTO`, tipo `MONITORAMENTO`, destino usuário.
  Onde: `principal/monitoramento_responsabilidades*.php`; origem: homologação PER-006.
- **RN-TAR-20** [FEITO] — Treinamento (legado): `fo_funcionarioxtiposxtreinamentos.codusuario` é o responsável pelo registro de treinamento, não pelo funcionário.
  Onde: módulo `TREINAMENTO`; origem: Decisão do usuário (22/08/2026), `analise_responsabilidades_treinamento_20260822.md`.
- **RN-TAR-21** [FEITO] — Treinamentos, Infraestrutura e Controles do Módulo Legal: botão Responsabilidades na barra do grid, habilitado ao selecionar a linha, abre popup com usuário ou grupo, finalidade, prioridade e vigência.
  Onde: `treinamento_ml_responsabilidades.php`, `infraestrutura_ml_responsabilidades.php`, `controle_ml_responsabilidades.php`; origem: homologação 20/09/2026.
- **RN-TAR-22** [FEITO] — Controles: vínculo com `ENTIDADE = 'documentos'` e `REGISTRO_ID = CODDOCUMENTO`. ADM_DA_EMPRESA inclui e exclui; GESTOR só consulta.
  Onde: `vw_responsabilidade_controle_documentos`; origem: `controle_documentos_responsabilidades_20260824.md`.
- **RN-TAR-23** [FEITO] — Homologação: responsáveis pelo fornecedor e pela documentação do fornecedor ficam separados.
  Onde: módulo `HOMOLOGACAO`; origem: Decisão do usuário (20/08/2026).
- **RN-TAR-24** [FEITO] — Na carga do legado: `CODUSUARIO = 0` ou vazio = sem responsável; código inexistente vai para órfãos; nenhum vínculo legado é apagado ou alterado.
  Onde: scripts 097 a 100; origem: mapeamento 19/08/2026.

## 3. Permissões nas telas de responsabilidade

- **RN-TAR-25** [FEITO] — Visualizar é obrigatório para consultar; Incluir controla o botão Adicionar; Editar controla os campos dos vínculos existentes; Excluir controla a lixeira.
  Onde: `assets/js/responsabilidades-permissoes.js`; origem: homologação PER-001 a PER-005.
- **RN-TAR-26** [FEITO] — Com Visualizar + Excluir (sem Incluir e sem Editar), a manutenção abre só para excluir.
  Onde: telas `*_responsabilidades_edicao.php`; origem: correção PER-004 (21/08/2026).
- **RN-TAR-27** [FEITO] — O servidor valida tudo de novo; sem Visualizar, o acesso direto à URL dá 403. Alteração sem permissão é ignorada e informada, sem mensagem de sucesso falsa.
  Onde: endpoints de responsabilidade; origem: PER-006 e homologação 20/09/2026.
- **RN-TAR-28** [FEITO] — Mensagens nunca expõem SQL.
  Onde: endpoints de manutenção; origem: correção após relatório visual (20/08/2026).

## 4. Delegação e substituição de responsável

- **RN-TAR-29** [FEITO] — Delegação e substituição não alteram a atribuição original: criam um responsável adicional durante a vigência.
  Onde: `responsabilidade_delegacoes`, `responsabilidade_substituicoes`; origem: modelo lógico 19/08/2026.
- **RN-TAR-30** [FEITO] — A substituição exige atribuição de origem, módulo, quem pede, motivo e exatamente um destino (usuário ou grupo). Nasce PENDENTE.
  Onde: `ResponsibilityService::createSubstitution`; origem: `p1-responsibility-service-20261010.md`.
- **RN-TAR-31** [FEITO] — Aprovar muda para ATIVA; revogar muda para REVOGADA. Só pedidos PENDENTE ou ATIVA mudam. Cada ação é auditada.
  Onde: `ResponsibilityService::changeStatus`; origem: código.
- **RN-TAR-32** [FEITO] — Substituição ativa e vigente conta como responsabilidade do substituto (usuário ou membros do grupo).
  Onde: `ResponsibilityService::effectiveForUser`; origem: código.
- **RN-TAR-33** [FEITO] — A tela de substituição tem menu e permissão inicial só para ADMINISTRADOR.
  Onde: `principal/responsabilidade_substituicao.php` (migração 185); origem: `p1-responsibility-service-20261010.md`.

## 5. Perfil de atribuições

- **RN-TAR-34** [FEITO] — Cada usuário responde o questionário `PERFIL_ATRIBUICOES` (catálogo universal). As respostas viram itens estruturados: unidade, pilar + papel, tema, categoria e respostas livres.
  Onde: `PerfilAtribuicoesService`, `perfil_atribuicoes_itens` (migração 268); origem: código.
- **RN-TAR-35** [FEITO] — Por pilar, o usuário escolhe o papel: EXECUTA, APROVA ou ACOMPANHA.
  Onde: perguntas `<BLOCO>.PILAR.<PILAR>`; origem: código.
- **RN-TAR-36** [FEITO] — O usuário marca as unidades em que atua. Sem unidade marcada, vale para todas.
  Onde: pergunta `GERAL.UNIDADES`, `vw_resp_auto_candidatos`; origem: código.
- **RN-TAR-37** [FEITO] — Blocos: GERAL (todos) e um por módulo (`MODULO_LEGAL`, `HOMOLOGACAO_FORNECEDORES`, `NAO_CONFORMIDADES`). O usuário só responde o bloco de um módulo que a empresa tem e cuja tela o perfil de acesso dele vê (ADMINISTRADOR responde todos os da empresa).
  Onde: `PerfilAtribuicoesService::blocosDoUsuario`; origem: código.
- **RN-TAR-38** [FEITO] — Só responde quem tem "Aplicar questionário" marcado no perfil de acesso principal.
  Onde: `perfis.aplicar_questionario` (Segurança › Perfil); origem: código.
- **RN-TAR-39** [FEITO] — Perguntas obrigatórias sem resposta impedem o envio. Opções fora da lista são descartadas.
  Onde: `PerfilAtribuicoesService::enviar`; origem: código.
- **RN-TAR-40** [FEITO] — O envio fica PENDENTE_APROVACAO e vai para o gestor do usuário (`usuarios.codusuario_superior`). Um novo envio substitui o pendente ou devolvido anterior (SUBSTITUIDO); o histórico fica.
  Onde: `sp_perfil_atrib_enviar`; origem: migração 268.
- **RN-TAR-41** [FEITO] — O gestor aprova ou devolve. Devolver exige motivo. Aprovar torna o perfil vigente por 12 meses e o vigente anterior deixa de valer.
  Onde: `sp_perfil_atrib_decidir`, `PerfilAtribuicoesService::MESES_REVISAO`; origem: migração 268.
- **RN-TAR-42** [FEITO] — O gestor decide só a própria equipe. ADMINISTRADOR e ADM_DA_EMPRESA (com a permissão da tela) veem todos (Visualizar) e decidem qualquer perfil (Editar). O GESTOR nunca faz o papel do ADM_DA_EMPRESA. Ninguém decide o próprio perfil, exceto o ADMINISTRADOR.
  Onde: `principal/api/perfil_atribuicoes.php`, `perfil_atribuicoes_gestao.php`; origem: código.
- **RN-TAR-43** [FEITO] — O usuário precisa responder quando: nunca respondeu, foi devolvido, a revisão anual venceu (`valido_ate`) ou entrou um bloco novo (módulo ou acesso novo). Enquanto aguarda o gestor, não precisa.
  Onde: `PerfilAtribuicoesService::situacao`; origem: código.
- **RN-TAR-44** [FEITO] — Versão nova do questionário não obriga a responder de novo: o perfil aprovado vale na versão em que foi respondido; a nova entra na próxima resposta.
  Onde: `PerfilAtribuicoesService::situacao`; origem: código.
- **RN-TAR-45** [FEITO] — Com perfil pendente, a tela abre como popup no login, com "Lembrar depois" (volta no próximo login). Também fica em Minha conta.
  Onde: `principal/perfil_atribuicoes.php`, `api/perfil_atribuicoes.php` (action=adiar); origem: código.
- **RN-TAR-46** [FEITO] — O gestor vê o envio na versão do questionário que o usuário respondeu, mesmo que a empresa já use outra.
  Onde: `PerfilAtribuicoesService::catalogoVersao`; origem: código.
- **RN-TAR-47** [FEITO] — Os códigos de pergunta que alimentam itens estruturados (`PILAR`, `TEMAS`, `CATEGORIAS`, `GERAL.UNIDADES`) não podem mudar; o texto pode.
  Onde: `QuestionarioVersaoService::validarTrocaCodigo`; origem: código.

## 6. Responsáveis automáticos

- **RN-TAR-48** [FEITO] — O perfil de atribuições vigente indica o responsável em dois casos: tarefas de potenciais aprovados pela IA e itens novos dos cinco pilares cadastrados sem responsável.
  Onde: `ResponsavelAutomatico`, `AiApprovedTaskService`, `scripts/windows-service.php` (migração 345); origem: Decisão do usuário (23/10/2026).
- **RN-TAR-49** [FEITO] — Tipo do potencial → pilar: licença, monitoramento, treinamento, infraestrutura e controle vão ao pilar do Módulo Legal; influência vai a Homologação de fornecedores; outro tipo, a Requisitos legais.
  Onde: `ResponsavelAutomatico::POTENCIAL`, `paraTarefa`; origem: código.
- **RN-TAR-50** [FEITO] — Entre quem EXECUTA o pilar na unidade, vai para quem tem menos tarefas abertas (PENDENTE ou PROCESSANDO). Empate: menor código de usuário. Os demais que executam passam a acompanhar.
  Onde: `ResponsavelAutomatico::escolher`, `vw_resp_auto_candidatos`; origem: Decisão do usuário (23/10/2026).
- **RN-TAR-51** [FEITO] — Ninguém executa o pilar: a tarefa fica com quem pediu a análise e o ADM DA EMPRESA recebe alerta para completar os perfis. O item novo fica sem responsável e o ADM recebe alerta.
  Onde: `ResponsavelAutomatico::registrarTarefa`, `processarItens`; origem: Decisão do usuário (23/10/2026).
- **RN-TAR-52** [FEITO] — O alerta ao ADM vai aos ADM DA EMPRESA ativos; sem nenhum, ao ADMINISTRADOR.
  Onde: `vw_resp_auto_adm`, `ResponsavelAutomatico::administradores`; origem: código.
- **RN-TAR-53** [FEITO] — Enquanto a empresa não tiver nenhum perfil aprovado com pilar, o alerta de "ninguém no perfil" fica desligado. Liga sozinho no primeiro perfil aprovado.
  Onde: `ResponsavelAutomatico::administradores`; origem: Decisão do usuário (23/10/2026).
- **RN-TAR-54** [FEITO] — Quem ACOMPANHA recebe alerta quando a tarefa é criada ou o item é atribuído. Quem APROVA recebe alerta quando a tarefa é concluída.
  Onde: `ResponsavelAutomatico`, `api/tarefa_concluir.php`; origem: Decisão do usuário (23/10/2026).
- **RN-TAR-55** [FEITO] — Quem pediu a análise recebe aviso de para quem a tarefa foi (quando não é ele mesmo).
  Onde: `ResponsavelAutomatico::registrarTarefa`; origem: código.
- **RN-TAR-56** [FEITO] — Item novo sem responsável espera 1 hora (quem cadastrou pode indicar antes). Se ganhar responsável nesse tempo, encerra como "Já tinha responsável".
  Onde: `sp_resp_auto_pendentes`; origem: código.
- **RN-TAR-57** [FEITO] — Itens que já existiam quando a função foi instalada não recebem responsável automático.
  Onde: `resp_auto_controle.ULTIMO_ID`; origem: migração 345.
- **RN-TAR-58** [FEITO] — A indicação cria uma responsabilidade DIRETA, principal, a partir de hoje. O motivo da escolha fica na observação da responsabilidade e na descrição da tarefa.
  Onde: `sp_resp_auto_atribuir`, `AiApprovedTaskService`; origem: código.
- **RN-TAR-59** [FEITO] — Cada decisão fica registrada (AGUARDANDO, ATRIBUIDO, SEM_CANDIDATO, JA_TINHA). Alerta é único por pessoa e link (não repete).
  Onde: `resp_auto_registro`, `sp_resp_auto_alerta`; origem: código.
- **RN-TAR-60** [FEITO] — O link do alerta abre o cadastro do pilar já filtrado no item; a tela de destino confere a permissão.
  Onde: `principal/resp_auto_abrir.php`; origem: código.

## 7. Tarefas (green_tasks)

- **RN-TAR-61** [FEITO] — Toda tarefa tem chave de idempotência obrigatória e única na empresa. Repetir a criação não duplica.
  Onde: `TaskService::enqueue`, `database/183_green_tasks.sql`; origem: `green-tasks-task-scheduler.md`.
- **RN-TAR-62** [FEITO] — A tarefa tem tipo, origem (transação, entidade, registro), unidade, título, descrição, prioridade de 0 a 9, destino (usuário ou grupo), prazo e criador.
  Onde: `TaskService::enqueue`; origem: código.
- **RN-TAR-63** [FEITO] — Situações: PENDENTE, PROCESSANDO, CONCLUIDA, ERRO. O worker trava a tarefa por 10 minutos e tenta até 3 vezes; cada mudança vai para o histórico.
  Onde: `TaskService::claim`, `fail`, `green_task_history`; origem: `green-tasks-task-scheduler.md`.
- **RN-TAR-64** [FEITO] — O tipo NOTIFICACAO vira alerta, com o link `TASK:<codtarefa>` como chave (não duplica).
  Onde: `scripts/task-worker.php`; origem: `green-tasks-task-scheduler.md`.
- **RN-TAR-65** [FEITO] — Potencial aprovado pela IA gera tarefa `IA_APROVACAO` (chave `IA-POTENCIAL:<codpotencial>`), com unidade, prazo padrão de 7 dias, alerta e auditoria. Prioridade 8 com confiança da IA ≥ 80; senão 5.
  Onde: `AiApprovedTaskService` (migração 192); origem: `p1-conclusao-20261010.md` e código.
- **RN-TAR-66** [FEITO] — O responsável conclui a tarefa pela Agenda (botão Concluir), com observação opcional. Só o destinatário conclui, e só tarefa PENDENTE ou PROCESSANDO.
  Onde: `api/tarefa_concluir.php`, `sp_tarefa_concluir`; origem: migração 345.
- **RN-TAR-67** [FEITO] — A conclusão vai para o histórico e para a auditoria, e avisa quem aprova o pilar.
  Onde: `sp_tarefa_concluir`, `ResponsavelAutomatico::avisarAprovadores`; origem: migração 345.
- **RN-TAR-68** [FEITO] — Responsabilidade que vence em até 30 dias gera uma tarefa de aviso por responsável e data (idempotente).
  Onde: `ResponsibilityService::enqueueExpiringTasks`; origem: `p1-responsibility-service-20261010.md`.

## 8. Agenda e eventos recorrentes

- **RN-TAR-69** [FEITO] — A agenda universal (`agenda_eventos`) guarda criador, responsável, período, descrição, local e origem. Evento exige chave, título e início.
  Onde: `AgendaService::createEvent`; origem: `p1-agenda-service-20261010.md`.
- **RN-TAR-70** [FEITO] — Recorrência diária, semanal ou mensal, com intervalo de 1 a 365 e data final opcional. Passada a data final, a recorrência se desliga.
  Onde: `AgendaService::createRecurring`, `materializeDueOccurrences`; origem: código.
- **RN-TAR-71** [FEITO] — O worker cria as ocorrências vencidas ou do dia seguinte, uma vez só por data, e avisa o responsável por tarefa NOTIFICACAO.
  Onde: `agenda_execucoes`, `scripts/task-worker.php`; origem: `p1-agenda-service-20261010.md`.
- **RN-TAR-72** [FEITO] — Eventos de monitoramento do legado (`ev_eventos`) são copiados para a agenda universal pela chave de origem. Alterar atualiza; excluir cancela, sem apagar.
  Onde: `eventos.php`, `AgendaService::syncSource`, `cancelSource`; origem: `p1-agenda-service-20261010.md`.
- **RN-TAR-73** [FEITO] — Ao sincronizar com "preservar concluído", evento já CONCLUIDO não é alterado.
  Onde: `AgendaService::syncSource`; origem: código.
- **RN-TAR-74** [FEITO] — A Home › Agenda mostra eventos da agenda, tarefas e as mesmas pendências de "Minhas pendências".
  Onde: `eventos.php?modo=agenda`, `vw_agenda_eventos_usuario`, `vw_agenda_tarefas_usuario`; origem: código (c454).

## 9. Pendências e Home

- **RN-TAR-75** [FEITO] — A Home tem abas independentes, cada uma com transação própria: Agenda, Minhas pendências, Alertas, Dashboard pessoal e Dashboard geral (gestor).
  Onde: `home_agenda.php`, `home_pendencias.php`, `home_alertas.php`, `dashboard.php?home_usuario=1`, `dashboard_admin.php?home_gestor=1`; origem: `home_abas_pendencias_pilares_20261012.txt`.
- **RN-TAR-76** [FEITO] — Minhas pendências lista os itens dos pilares em que o usuário é responsável direto ou por grupo, com o motivo de cada um. Cada pilar só aparece com permissão de Visualizar na transação.
  Onde: `classes/MinhasPendencias.php`; origem: código.
- **RN-TAR-77** [FEITO] — O filtro do usuário é exato e conferido de novo em cada linha. A transação aberta pela pendência aplica outra vez o escopo do usuário no servidor.
  Onde: `MinhasPendencias::ler`, parâmetro `minhas_pendencias=1`; origem: `home_abas_pendencias_pilares_20261012.txt`.
- **RN-TAR-78** [FEITO] — Sinal do prazo: vencido (antes de hoje), a vencer (até 30 dias), no prazo (mais de 30) ou sem data. Ordem: prazo mais antigo primeiro, sem prazo no fim.
  Onde: `MinhasPendencias::sinal`, `itens`; origem: código.
- **RN-TAR-79** [FEITO] — Motivos de Licenças, em ordem: renovação em protocolo; vencida; prazo de reação passado; vence em até 30 dias; responsável (válida até); sem validade.
  Onde: `MinhasPendencias::pilares`; origem: código.
- **RN-TAR-80** [FEITO] — Taxas de licença não pagas, vencidas ou vencendo em até 30 dias entram para os responsáveis da licença.
  Onde: `vw_minhas_taxas` (migração 344); origem: código.
- **RN-TAR-81** [FEITO] — Medição e Monitoramento usa a data de reação. Treinamentos, Infraestrutura e Controles não têm data: a pendência é a própria responsabilidade.
  Onde: `MinhasPendencias::pilares`; origem: código.
- **RN-TAR-82** [FEITO] — Homologação entra nas pendências: fornecedores (responsável pelo fornecedor ou integração), documentos, funcionários, treinamentos e plano de ação. O fornecedor externo não recebe as pendências do responsável interno.
  Onde: `MinhasPendencias::homologacao` (migrações 296, 310, 311, 312); origem: código.
- **RN-TAR-83** [FEITO] — O painel universal de pendências junta tarefas abertas, eventos da agenda dos próximos 30 dias, responsabilidades que vencem em até 30 dias e alertas não lidos.
  Onde: `PendenciasService`, `painel_pendencias_universal.php` (permissão inicial ADMINISTRADOR); origem: `p1-agenda-service-20261010.md`.
- **RN-TAR-84** [FEITO] — O painel direito mostra atalhos (Pendências, Aprovações, Próximos prazos, Ajuda, Calendário) com contagem. Atualiza a cada 5 minutos sem recarregar as abas. O clique abre uma aba na área de trabalho, não popup.
  Onde: `principal/main_pendencias.php`; origem: `dashboard_diretrizes.txt`, `painel_direito_diretrizes.txt`.
- **RN-TAR-85** [FEITO] — Alertas e logs não substituem pendências.
  Onde: —; origem: `painel_direito_diretrizes.txt`.
- **RN-TAR-86** [FEITO] — Novo pilar precisa de view de pendências com o `CODUSUARIO` efetivo, transação PHP própria e link na Home.
  Onde: —; origem: `home_abas_pendencias_pilares_20261012.txt`.

## 10. Dashboards e painel do gestor

- **RN-TAR-87** [FEITO] — Dashboard pessoal: só itens do usuário (requisitos, licenças, medições, planos de ação, alertas, perfil de atribuições, análises de IA). Cada número abre a transação já filtrada.
  Onde: `principal/dashboard.php`; origem: código.
- **RN-TAR-88** [FEITO] — ADMINISTRADOR e ADM DA EMPRESA abrem o dashboard da empresa por padrão. O Dashboard administrativo exige o perfil ADMINISTRADOR; outro perfil recebe 403.
  Onde: `dashboard.php`, `dashboard_admin.php`; origem: `dashboard_administrativo_20261004.md` e código.
- **RN-TAR-89** [FEITO] — Painel do gestor: equipe = subordinados diretos e indiretos pelo campo gestor do cadastro de usuários. Abas: Minha equipe, Atendimento legal da equipe e Prazos da equipe.
  Onde: `principal/dashboard_gestor.php`, `DashboardEquipe::membros` (transação `PAINEL_GESTOR`); origem: apresentação Atlas (slide 12).
- **RN-TAR-90** [FEITO] — O gestor só abre o painel pessoal de quem é da equipe dele; ADMINISTRADOR e ADM DA EMPRESA abrem o de qualquer um.
  Onde: `dashboard.php?usuario=`; origem: código.
- **RN-TAR-91** [FEITO] — Situação do perfil de atribuições por pessoa: não se aplica, vencido, devolvido, aguardando aprovação, aprovado ou sem envio.
  Onde: `DashboardEquipe::perfil`; origem: código.

## 11. Motor de questionários

- **RN-TAR-92** [FEITO] — Catálogo (questionários, versões, blocos, perguntas, opções, regras, publicações) fica em `guniversal_dbp`. Execuções, respostas, resultados e evidências ficam no banco da empresa.
  Onde: `QuestionnaireService`, migrações 193; origem: `p2-questionnaire-20261010.md`.
- **RN-TAR-93** [FEITO] — Contextos: `LEGAL`, `SGA`, `QUALITY`, `WORKFLOW`, `SUPPLIER`, `ESG`, `PROJECTS`, `EMPLOYEE_INTEGRATION`. Todos usam as mesmas tabelas, filtradas pelo módulo. Contexto inválido é recusado.
  Onde: `QuestionnaireService::catalogForContext`; origem: `p2-questionnaire-catalogo-contextos-20261011.md`.
- **RN-TAR-94** [FEITO] — Cada contexto tem workflow próprio (`<CONTEXTO>_QUESTIONNAIRE`) com etapas INICIO, REVISAO, FINALIZADO, CANCELADO e ações ENVIAR, APROVAR, REJEITAR.
  Onde: migrações 196 e 202; origem: `p2-questionnaire-integracoes-20261011.md`.
- **RN-TAR-95** [FEITO] — Versão universal (`codempresa = 0`): só o ADMINISTRADOR altera. Versão da empresa: criada pelo ADM DA EMPRESA (ou perfil com Editar em Questionários) a partir da versão que a empresa usa; vale só para ela.
  Onde: `QuestionarioVersaoService::escopo`, `exigirEdicao` (migração 291); origem: código.
- **RN-TAR-96** [FEITO] — Versão vigente da empresa = a publicada dela; sem ela, a universal publicada.
  Onde: `QuestionnaireService::vigentes`; origem: código.
- **RN-TAR-97** [FEITO] — Só rascunho é editado. Versão publicada é imutável; para mudar, cria-se nova versão. Versões nunca são apagadas (descartar = arquivar).
  Onde: `QuestionarioVersaoService::exigirEdicao`, `descartar`; origem: código.
- **RN-TAR-98** [FEITO] — Publicar exige ao menos uma pergunta ativa e nenhum conflito pendente. A publicada anterior do mesmo escopo vira ARQUIVADA.
  Onde: `QuestionarioVersaoService::publicar`; origem: código.
- **RN-TAR-99** [FEITO] — Ao publicar uma universal, cada empresa com versão própria recebe um merge: novo na universal entra; alterado só de um lado fica esse lado; alterado nos dois fica o da empresa e vira conflito para o ADM resolver.
  Onde: `QuestionarioVersaoService::mesclarEmpresas`, `resolverConflito`; origem: código.
- **RN-TAR-100** [FEITO] — Merge sem conflito, com a empresa em versão publicada, já sai publicado. Com conflito ou rascunho aberto, sai como rascunho.
  Onde: `QuestionarioVersaoService`; origem: código.
- **RN-TAR-101** [FEITO] — Só questionário publicado é executado. A execução tem chave de idempotência: reabrir no mesmo contexto não duplica.
  Onde: `QuestionnaireService::start`; origem: código.
- **RN-TAR-102** [FEITO] — Só o dono da execução responde, e só com ela ABERTA. Pergunta obrigatória sem resposta bloqueia. Depois de concluída, não aceita reenvio.
  Onde: `QuestionnaireService::submit`; origem: `p2-questionnaire-20261010.md` e código.
- **RN-TAR-103** [FEITO] — Pontuação: soma das opções escolhidas sobre a soma das maiores opções de cada pergunta pontuada. Sem pergunta pontuada, o percentual é de perguntas respondidas.
  Onde: `QuestionnaireService::score`, `submit`; origem: código.
- **RN-TAR-104** [FEITO] — Com percentual mínimo na versão, aprovado = percentual ≥ mínimo. Sem mínimo, não há aprovado/reprovado.
  Onde: `QuestionnaireService::submit`; origem: código.
- **RN-TAR-105** [FEITO] — Concluir leva o workflow para REVISAO. A aprovação humana leva a FINALIZADO; a rejeição, a CANCELADO.
  Onde: `QuestionnaireService::advanceWorkflowAfterSubmit`; origem: `p2-questionnaire-integracoes-20261011.md`.
- **RN-TAR-106** [FEITO] — Evidências ficam ligadas a questionário, pergunta e registro de origem; incluir de novo não duplica.
  Onde: `QuestionnaireService::addEvidence`; origem: `p2-questionnaire-integracoes-20261011.md`.
- **RN-TAR-107** [FEITO] — Lançadores nos grids de Requisitos legais, Licenças, Medições, Treinamentos, Infraestrutura, Controles, Ocorrências de Qualidade e Homologação de fornecedores.
  Onde: `principal/questionario_execucao.php`; origem: `p2-questionnaire-integracoes-20261011.md`.
- **RN-TAR-108** [FEITO] — Fornecedor externo só responde questionários dos fornecedores ligados a ele; o interno restrito, só os das suas unidades.
  Onde: `questionario_execucao.php` (migrações 296 e 316); origem: código.
- **RN-TAR-109** [FEITO] — Manutenção em Administração › Questionários, com uma transação em iframe por contexto (filtros e editor não se misturam).
  Onde: `principal/questionarios.php`, `*_questionario.php`; origem: `p2-questionnaire-catalogo-contextos-20261011.md`.
- **RN-TAR-110** [PENDENTE] — Questionário legal (`LEGAL_REQUIREMENTS`, 9.621 perguntas migradas) continua RASCUNHO. Só publicar depois de revisar os itens marcados, validar textos e definir tipos de resposta. Hoje todas são TEXTO e não obrigatórias.
  Onde: migração 203, `amb_questionario_pergunta_saneamento`; origem: `p2-questionnaire-legal-saneamento-20261011.md`.
- **RN-TAR-111** [PENDENTE] — Questionários-base dos 8 contextos existem só como rascunho vazio; nenhuma pergunta foi inventada.
  Onde: migração 202; origem: `p2-questionnaire-catalogo-contextos-20261011.md`.

## Pontos a confirmar

- Ordem de resolução do responsável: o plano da Fase 1 punha a atribuição direta antes da delegação; o modelo lógico, a delegação antes. Hoje a view devolve todos os válidos, sem hierarquia. Falta definir se a ordem vai ser aplicada.
- Módulo de `planodeacao.codusuario` (Requisitos legais ou Controle): não migrado, aguardando decisão.
- 953 vínculos órfãos de treinamento legado e o saneamento dos órfãos de Homologação ficaram fora da carga.
- Pilar Requisitos legais e Não conformidades no responsável automático: o perfil tem bloco `NAO_CONFORMIDADES` e potencial sem tipo cai em "Requisitos", mas os itens automáticos cobrem só os cinco pilares.
- Tarefa com destino grupo (`codgrupo_destino`): existe no modelo, mas Agenda, pendências e conclusão olham só `codusuario_destino`.
- Delegações (`responsabilidade_delegacoes`) têm aprovação e revogação no serviço, mas não há tela própria documentada; só a de substituição.
- Painel universal de pendências e tela de substituição com permissão só para ADMINISTRADOR: falta definir se outros perfis recebem.
- `diretrizes-projeto.txt` (16.2.1) diz que a Home não incorpora dashboard nem pendências; o documento de 12/10/2026 pôs as pendências e os dashboards em abas da Home (em iframes, cada um com transação própria). Confirmar se a diretriz geral deve ser atualizada.
- [PENDENTE] Avisos por SMS e WhatsApp (etapa 6 da gestão de tarefas): ficaram para a Onda 3; o e-mail está pronto e depende só do SMTP.

## Fontes

Anotações do projeto: responsáveis automáticos (23/10/2026), próximos passos da apresentação Atlas (24/10/2026) e padrão de layout das telas; conferidas na revisão de 24/10/2026.


Documentos em `docs/`: `decisoes_funcionais_responsabilidades_20260820.md`, `modelo_logico_responsabilidades_20260819.md`, `plano_fase_1_modelo_responsabilidades_20260819.md`, `matriz_geral_permissoes_responsabilidades_20260821.md` e `_20260822.md`, `status_implementacao_responsabilidades_20260820.md`, `interface_consulta_responsabilidades_licencas_20260820.md`, `interface_consulta_responsabilidades_requisitos_20260820.md`, `inventario_responsabilidades_20260819.md`, `inventario_infraestrutura_responsabilidades_20260823.md`, `mapeamento_vinculos_legados_responsabilidades_20260819.md`, `replicacao_responsabilidades_por_tenant_20260819.md`, `atualizacao_status_responsabilidades_20260822.md`, `analise_responsabilidades_treinamento_20260822.md`, `responsabilidades_gestao_funcionarios_20260822.md`, `responsabilidades_infraestrutura_ml.md`, `responsabilidades_meus_controles.md`, `responsabilidades_treinamentos_ml.md`, `controle_documentos_responsabilidades_20260824.md`, `validacao_prontida_medicao_monitoramento_responsabilidades_20260821.md`, `relatorio_homologacao_responsabilidades_modulo_legal_20260920.md`, `p0-lote-responsabilidades-treinamentos-infraestrutura.md`, `p1-agenda-service-20261010.md`, `p1-responsibility-service-20261010.md`, `p1-workflow-service-20261010.md`, `p1-conclusao-20261010.md`, `green-tasks-task-scheduler.md`, `home_abas_pendencias_pilares_20261012.txt`, `home_tarefas_diretrizes.md`, `painel_direito_diretrizes.txt`, `dashboard_diretrizes.txt`, `dashboard_geral_diretrizes.md`, `dashboard_administrativo_20261004.md`, `p2-questionnaire-20261010.md`, `p2-questionnaire-catalogo-contextos-20261011.md`, `p2-questionnaire-integracoes-20261011.md`, `p2-questionnaire-legal-saneamento-20261011.md`.

Código: `classes/ResponsavelAutomatico.php`, `PerfilAtribuicoesService.php`, `ResponsibilityService.php`, `TaskService.php`, `AgendaService.php`, `PendenciasService.php`, `MinhasPendencias.php`, `DashboardEquipe.php`, `AiApprovedTaskService.php`, `QuestionnaireService.php`, `QuestionarioVersaoService.php`; `database/094_responsabilidades_views_procedures.sql`, `268_perfil_atribuicoes.sql`, `345_responsaveis_automaticos.sql`, `345_ajuda_responsaveis.php`; `principal/api/tarefa_concluir.php`, `api/perfil_atribuicoes.php`, `perfil_atribuicoes.php`, `perfil_atribuicoes_gestao.php`, `home_pendencias.php`, `home_agenda.php`, `eventos.php`, `dashboard.php`, `dashboard_gestor.php`, `resp_auto_abrir.php`, `questionario_execucao.php`; `scripts/windows-service.php`.

`diretrizes-projeto.txt`: seção 16.2.1 (Home principal após o login). Não há outro trecho sobre responsabilidade, agenda, tarefa ou questionário.
