# Regras de negócio — Não Conformidades

Atualizado em 24/10/2026. Documento de referência: o que o sistema faz hoje e as decisões do usuário. Legenda: [FEITO], [PENDENTE], [A CONFIRMAR].

Princípio geral: as telas leem só por views (`vw_nc_*`) e gravam só por procedures (`sp_nc_*`). As mensagens de validação das procedures chegam ao usuário como estão. Até a virada, o sistema anterior (legado) prevalece e é sincronizado para o greenportal.

## 1. Tipos de não conformidade

- **RN-NC-01** [FEITO] — O tipo é identificado pelo código `CODTIPONAOCONFORMIDADE`, nunca pelo texto: 1 = Fornecedor, 2 = Cliente, 3 = Empresa, 4 = Acidente/Incidente, 5 = Planejamento, 6 = Assistência Técnica.
  Onde: `classes/NaoConformidadeWorkflow.php` (MENU_NOVA); origem: análise de templates 21/08/2026 e migração 321.
- **RN-NC-02** [FEITO] — Cada tipo tem seu menu de inclusão e sua permissão: `NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA`, `_FORNECEDOR`, `_CLIENTE`, `_PLANEJAMENTO`, `_ASSISTENCIA`, `_ACIDENTE`. Sem `pode_incluir` no menu do tipo, a inclusão é negada (403).
  Onde: `NaoConformidadeWorkflow::MENU_NOVA`, `principal/naoconformidades_novaocorrencia*.php`, `principal/naoconformidades_ocorrencia.php?tipo=N`; origem: código.
- **RN-NC-03** [FEITO] — Os campos que cada tipo mostra vêm de `nc_tipo_de_naoconformidadexcampos` (legado, só consulta no greenportal).
  Onde: `NaoConformidadeWorkflow::campos`, `vw_nc_tipo_campos`; origem: migração 339 e legado `nc_indicacao_ocorrencia.php`.
- **RN-NC-04** [FEITO] — Empresa (3) e Cliente (2) seguem o fluxo completo: gestor → análise da causa → plano → eficácia.
  Onde: `database/321_nao_conformidades_base.sql`; origem: legado `formulario/nc_s.php`.
- **RN-NC-05** [FEITO] — Fornecedor (1): fluxo da Empresa, com fornecedor obrigatório no envio e classificação própria (cadastro "Classificação de fornecedores").
  Onde: `sp_nc_ocorrencia_salvar`; origem: migração 321 (etapa "325", 21/10/2026).
- **RN-NC-06** [FEITO] — Acidente/Incidente (4) usa o fluxo da Empresa.
  Onde: migração 321 ("331"); origem: Decisão do usuário (22/10/2026): tipo 4 com o fluxo da Empresa (sem uso no legado da Crown).
- **RN-NC-07** [FEITO] — Planejamento (5): o envio leva direto ao Plano de Ação, sem causas e sem avaliação do gestor; o gestor monta as ações.
  Onde: `sp_nc_ocorrencia_salvar`, `sp_nc_plano_salvar`; origem: Decisão do usuário (21/10/2026).
- **RN-NC-08** [FEITO] — Assistência Técnica (6): o emitente monta as ações ainda Em criação e envia direto ao Plano de Ação. Exige ao menos 1 ação, todas com descrição e responsável. Cada responsável é avisado.
  Onde: `sp_nc_ocorrencia_salvar`, `sp_nc_alertar`; origem: Decisão do usuário (22/10/2026).
- **RN-NC-09** [FEITO] — Planejamento e Assistência Técnica exigem no envio o campo "Passar pela análise da eficácia?" (S/N).
  Onde: `sp_nc_ocorrencia_salvar`; origem: legado `nc_planejamento` / `nc_assistenciatecnica`.

## 2. Abertura da ocorrência

- **RN-NC-10** [FEITO] — A NC nova nasce na etapa "Em criação", situação "Em andamento", com o usuário logado como emitente e número no formato `CODOCORRENCIA/ANO`.
  Onde: `sp_nc_ocorrencia_salvar`; origem: migração 321.
- **RN-NC-11** [FEITO] — O que é criado no greenportal recebe código numa faixa própria (ocorrência e causa a partir de 50001, ação a partir de 500001). O sincronismo não apaga nem altera essa faixa.
  Onde: `fn_nc_faixa`, `SincronismoLegado::FAIXAS_NOVO`; origem: Decisão do usuário (21/10/2026), migração 322.
- **RN-NC-12** [FEITO] — Para enviar ao gestor: gestor ativo, unidade, e sumário e descrição quando o tipo usa esses campos.
  Onde: `sp_nc_ocorrencia_salvar`, `NaoConformidadeConfig::FIXOS`; origem: legado `nc_s.php`.
- **RN-NC-13** [FEITO] — Cliente (2): no envio são obrigatórios cliente, unidade do cliente, produto, tipo e nº do documento, tipo de reclamação, procedência e "afeta a segurança de alimentos".
  Onde: `sp_nc_ocorrencia_salvar`; origem: Decisão do usuário (21/10/2026).
- **RN-NC-14** [FEITO] — O cliente é gravado pelo nome (`DSCCLIENTE`), como no legado; o produto, pelo código.
  Onde: migração 321 (etapa "327"); origem: legado.
- **RN-NC-15** [FEITO] — Gravar é tudo ou nada: se a validação do envio falha, nada fica gravado pela metade (nem a NC nova).
  Onde: `NaoConformidadeWorkflow::chamar` (transação); origem: código (c511).
- **RN-NC-16** [FEITO] — Além das regras fixas, a empresa pode exigir outros campos no envio, por tipo (Configuração › Campos por tipo). Só campos que o tipo usa.
  Onde: `nc_config_obrigatorios`, `sp_nc_config_validar_envio`; origem: migração 339 (23/10/2026).
- **RN-NC-17** [FEITO] — O gestor escolhido precisa estar ativo; qualquer usuário ativo pode ser gestor, responsável ou responsável de ação, como no legado.
  Onde: `NaoConformidadeWorkflow::usuarios`, `sp_nc_ocorrencia_salvar`; origem: código e diagnóstico do legado da Crown (20/10/2026: no legado qualquer usuário ativo é gestor/responsável); Decisão do usuário (24/10/2026): "Qualquer usuário ativo".
- **RN-NC-18** [FEITO] — Itens desativados de cadastros não aparecem para escolha, mas continuam aparecendo nas NCs que já os usam.
  Onde: `NaoConformidadeWorkflow::opcoes`, `vw_nc_opcoes`; origem: migração 332.
- **RN-NC-19** [FEITO] — O fornecedor externo só pode criar NC de Assistência Técnica.
  Onde: `principal/naoconformidades_ocorrencia.php`; origem: Decisão do usuário (22/10/2026).

## 3. Etapas e quem atua em cada uma

- **Em criação** — emitente (ou administrador). Edita a aba Ocorrência e envia. Na Assistência Técnica também monta as ações.
- **Recusado pelo Gestor** — emitente corrige e reenvia ao gestor.
- **Com o Gestor** — gestor da NC. Registra ação imediata, abrangência, riscos/oportunidades, mudança; escolhe o responsável; envia, recusa ou finaliza como improcedente. Não existe no Planejamento e na Assistência Técnica.
- **Em Analise da Causa Raiz** — responsável pela análise. Cadastra causas, ações e a análise da causa.
- **Plano de Ação** — Empresa/Fornecedor/Cliente/Acidente: responsável pela análise. Planejamento: o gestor monta as ações. Assistência Técnica: emitente e gestor mantêm as ações. Nos tipos 5 e 6, o responsável de cada ação só atualiza status e observação da sua ação.
- **Recusado pela Avaliacao da Eficacia** — responsável pela análise refaz causas/ações e reenvia.
- **Avaliação da Eficacia** — gestor da NC. Escreve a verificação e aplica o resultado.
- **Finalizado** — só consulta. Só a administração reabre.

- **RN-NC-20** [FEITO] — Administrador e ADM da empresa (`ADMINISTRADOR`, `ADM_DA_EMPRESA`) podem agir em qualquer etapa.
  Onde: `NaoConformidadeWorkflow::admin`, parâmetro `p_admin` das procedures; origem: legado (ADM).
- **RN-NC-21** [FEITO] — Só o emitente altera a aba Ocorrência, e só em "Em criação" ou "Recusado pelo Gestor".
  Onde: `sp_nc_ocorrencia_salvar`; origem: legado.
- **RN-NC-22** [FEITO] — Só o gestor da NC avalia em "Com o Gestor". Enviar ao responsável exige ação imediata, análise da abrangência e responsável.
  Onde: `sp_nc_gestor_salvar`; origem: legado.
- **RN-NC-23** [FEITO] — Recusa do gestor exige motivo e devolve a NC ao emitente ("Recusado pelo Gestor").
  Onde: `sp_nc_gestor_salvar`; origem: legado.
- **RN-NC-24** [FEITO] — Improcedente: o gestor finaliza a NC na hora, com a situação "Foi finalizado pelo gestor por ser improcedente!".
  Onde: `sp_nc_gestor_salvar`; origem: legado.
- **RN-NC-25** [FEITO] — Só o responsável pela análise altera causas, ações e análise nas etapas de análise/plano/recusa na eficácia.
  Onde: `sp_nc_analise_validar_acesso`; origem: legado.
- **RN-NC-26** [FEITO] — Incluir causa ou gravar ação passa a NC para "Plano de Ação" (como no legado).
  Onde: `sp_nc_etapa_plano`; origem: legado `nc_causas_s.php` / `nc_planodeacao_s.php`.
- **RN-NC-27** [FEITO] — Excluir uma causa exclui também as ações dela.
  Onde: `sp_nc_causa_excluir`; origem: migração 321.
- **RN-NC-28** [FEITO] — Status da ação só pode ser 0, 25, 50, 75 ou 100%.
  Onde: `sp_nc_plano_salvar`; origem: legado.
- **RN-NC-29** [FEITO] — Enviar para a eficácia exige: análise da causa, data prevista de conclusão, ao menos 1 causa, ao menos 1 ação por causa e plano realizado (`getStatusPlanoAcaoNC` diferente de "Não").
  Onde: `sp_nc_analise_salvar`; origem: legado.
- **RN-NC-30** [FEITO] — Planejamento e Assistência Técnica avançam sozinhos quando todas as ações chegam a 100% (na Assistência Técnica, se as ações já estão a 100% no envio, avança na hora): com "Passar pela eficácia" = S vão para a eficácia; = N finalizam como ENCERRADO "Sem verificação da eficácia".
  Onde: `sp_nc_planejamento_avancar`; origem: legado `pendencias_nc_planodeacao_update.php`.
- **RN-NC-31** [FEITO] — Só o gestor da NC avalia a eficácia. Finalizar exige o texto da verificação. Resultado: EFICAZ ou ENCERRADO → Finalizado; NÃO EFICAZ → volta.
  Onde: `sp_nc_eficacia_salvar`; origem: legado.
- **RN-NC-32** [FEITO] — Não eficaz ou recusa na eficácia (com motivo) volta para "Recusado pela Avaliacao da Eficacia"; nos tipos 5 e 6 volta para "Plano de Ação".
  Onde: `sp_nc_eficacia_salvar`; origem: Decisão do usuário (21/10/2026).
- **RN-NC-33** [FEITO] — Quando o parâmetro `EFICACIA_EXIGE_PRAZO` = S, finalizar a eficácia exige a data da análise da eficácia e o responsável pela pendência (padrão S só na Crown).
  Onde: `NaoConformidadeWorkflow::exigePrazoEficacia`, `NaoConformidadeConfig`; origem: legado Crown (chamado 15652015), migração 339.
- **RN-NC-34** [FEITO] — Etapa gravada com acentuação quebrada pelo legado ("Plano de AÃ§Ã£o") é corrigida só na leitura; o valor gravado não muda.
  Onde: `fn_nc_etapa`, `NaoConformidadeWorkflow::etapa`; origem: migrações 321/331.
- **RN-NC-35** [FEITO] — Toda passagem de etapa e alteração fica no histórico da NC (`nc_workflow_historico`), gravado só pelas procedures.
  Onde: `sp_nc_historico_registrar`; aba Histórico; origem: migração 321.

- **RN-NC-92** [FEITO] — Nas NCs do legado de Empresa, Cliente e Fornecedor a ação aponta só para a causa (`CODOCORRENCIA` vazio). O greenportal acha a ocorrência da ação pela ação ou pela causa, para mostrar, editar e incluir o responsável da ação em "Minhas ocorrências". Os dados do legado não mudam.
  Onde: `fn_nc_plano_ocorrencia`, `vw_nc_planodeacao`, `vw_nc_envolvidos`, `vminhasocorrenciasnc`; origem: migração "327" (c509, 22/10/2026).

## 4. Fornecedor externo

- **RN-NC-36** [FEITO] — O usuário de fornecedor só age na análise da causa e no plano quando é o responsável. Não avalia como gestor.
  Onde: `NaoConformidadeWorkflow::fornecedorExterno`, `naoconformidades_ocorrencia.php`; origem: legado (`$_SESSION['FORNECEDOR']`).
- **RN-NC-37** [FEITO] — Na Assistência Técnica, o fornecedor externo age como emitente.
  Onde: `naoconformidades_ocorrencia.php`; origem: Decisão do usuário (22/10/2026).
- **RN-NC-38** [FEITO] — Ao escolher o responsável de uma ação, o fornecedor externo só vê os usuários do próprio fornecedor e os da empresa que já participam da NC.
  Onde: `NaoConformidadeWorkflow::responsaveisAcaoExterno`; origem: Decisão do usuário (22/10/2026, c519).
- **RN-NC-39** [FEITO] — Data de resposta do fornecedor: gravada sempre que o fornecedor envia a análise; ao atualizar uma ação, só na primeira vez.
  Onde: `sp_nc_resposta_fornecedor`; origem: Decisão do usuário (22/10/2026).
- **RN-NC-40** [FEITO] — Avisos para usuário de fornecedor começam com "Sr. Fornecedor, ...".
  Onde: `sp_nc_alertar`; origem: legado.

## 5. Quem vê a NC

- **RN-NC-41** [FEITO] — Vê a ocorrência: administrador, quem tem visão total da Lista geral, ou quem participa dela (emitente, gestor, responsáveis, membros da lista de distribuição).
  Onde: `NaoConformidadeWorkflow::podeVer`, `vw_nc_envolvidos`; origem: legado (mandava a NC inteira por e-mail).
- **RN-NC-42** [FEITO] — Lista geral abre ordenada por código decrescente e sem as finalizadas; escolher "Finalizado" no filtro de Etapa mostra só as finalizadas.
  Onde: `principal/naoconformidades_listageralocorrencias.php`; origem: análise de migração 21/08/2026.
- **RN-NC-43** [FEITO] — "Minhas ocorrências" mostra só as NCs em que o usuário é emitente, gestor, responsável pela análise ou responsável de ação. O filtro é aplicado no servidor e inclui finalizadas.
  Onde: `principal/naoconformidades_minhasocorrencias.php`; origem: análise de migração 22/08/2026.

- **RN-NC-93** [FEITO] — Sem "visualizar total", Minhas ocorrências e Lista geral ficam no escopo do usuário (envolvido na NC) também na contagem, na exportação e nas opções dos filtros.
  Onde: `force_user_scope` / `CODUSUARIO_ENVOLVIDO` nas telas de lista; origem: c502 (21/10/2026).
- **RN-NC-94** [FEITO] — Permissões iniciais por perfil (migração 329, só acrescenta): USUARIOS — Minhas ocorrências e as cinco "Nova ocorrência"; VISITANTE — só consulta de Minhas ocorrências e Lista geral. Nova ocorrência de Assistência Técnica: as permissões da Nova ocorrência da empresa mais os perfis de fornecedor que veem Minhas ocorrências.
  Onde: `scripts/aplicar-migracao-321-nao-conformidades-base.php` (etapas 328/329); origem: c516/c519 (22/10/2026).
- **RN-NC-95** [FEITO] — Todo menu de NC existe no catálogo universal e é replicado pelo código para todas as empresas; não há menu só de uma empresa. Excluir menu com itens dentro ou com perfil com acesso é bloqueado.
  Onde: `classes/MenuCatalogoService.php`, Setup › Menu; origem: Decisão do usuário (21/10/2026), c506.

## 6. Avisos, listas de distribuição e participantes externos

- **RN-NC-44** [FEITO] — Cada passagem de etapa avisa em Home › Alertas os mesmos destinatários dos e-mails do legado: envio ao gestor → gestor e lista; envio ao responsável → responsável; recusa e improcedente → emitente; envio à eficácia → gestor; não eficaz → responsável; finalizada → emitente, gestor e responsável.
  Onde: `sp_nc_alertar`; origem: legado `nc_s.php`.
- **RN-NC-45** [FEITO] — Quem fez a passagem não recebe o aviso; só usuários ativos recebem; erro no aviso não impede a passagem.
  Onde: `sp_nc_alertar`; origem: migração 321.
- **RN-NC-46** [FEITO] — Nos tipos 5 e 6, os responsáveis das ações também recebem os avisos de recusa na eficácia e de finalização.
  Onde: `sp_nc_alertar`; origem: legado.
- **RN-NC-96** [FEITO] — Alertas, pendências e agenda de NC abrem a ocorrência como aba do portal, na aba certa da NC, com o título "NC <código>/<ano>".
  Onde: `greenPortalOpenTab` (Home, sino do cabeçalho, Minhas pendências, Agenda); origem: c517–c520 (22/10/2026).
- **RN-NC-47** [FEITO] — Participantes externos (sem login) da lista de distribuição recebem por e-mail o aviso de criação ("Tomar conhecimento da criação da não conformidade").
  Onde: `sp_nc_email_participantes`, `email_fila`; origem: Decisão do usuário (22/10/2026): "continuam, recebendo por e-mail".
- **RN-NC-48** [FEITO] — Listas do sistema anterior (código abaixo de 50001) só consulta. Para alterar, "Copiar como nova lista" (leva membros e categorias).
  Onde: `sp_nc_lista_validar`, `sp_nc_lista_copiar`; origem: migração 333.
- **RN-NC-49** [FEITO] — Nome de lista não se repete. Só usuários ativos e participantes ativos entram em lista.
  Onde: `sp_nc_lista_salvar`, `sp_nc_lista_membros`; origem: migração 333.
- **RN-NC-50** [FEITO] — Quando `LISTAS_FORNECEDOR_SEPARADAS` = S, a NC de Fornecedor só oferece listas da categoria Fornecedores e os outros tipos, as demais (padrão S só na Crown).
  Onde: `NaoConformidadeWorkflow::separaListasFornecedor`; origem: legado Crown, migração 339.
- **RN-NC-51** [FEITO] — Participantes do legado vêm pelo sincronismo (nome e e-mail; o legado prevalece). No greenportal só se altera o Ativo deles.
  Onde: `NaoConformidadeParticipantes::sincronizar`; origem: migração 333.
- **RN-NC-52** [FEITO] — Participante novo do greenportal recebe código a partir de 90000001. E-mail obrigatório e válido (vários separados por ";"); não pode repetir e-mail de outro participante ativo.
  Onde: `sp_nc_participante_salvar`; origem: migração 333.
- **RN-NC-53** [FEITO] — Toda alteração da administração (listas, participantes, configuração) fica em `nc_admin_historico` (antes → depois, quem e quando).
  Onde: `sp_nc_admin_historico`; origem: migração 333.
- **RN-NC-54** [FEITO] — E-mails saem pela fila (`email_fila`) do serviço do Agendador: novas tentativas em 10/20/30/40 minutos; na 5ª falha fica em ERRO e pode ser reenviado em Administração › E-mails enviados.
  Onde: `sp_email_resultado`, `scripts/email-worker.php`; origem: migração 333.
  [PENDENTE] O SMTP não está configurado. Decisão do usuário: só configurar depois, para não correr o risco de enviar e-mails de teste a usuários reais. Até lá os e-mails ficam na fila.

## 7. Administração: correção, transferência e saneamento

- **RN-NC-55** [FEITO] — Até a virada, correção, transferência e atribuição de gestor valem só para NCs do greenportal (código a partir de 50001). As do legado aparecem para consulta ("corrija no sistema anterior").
  Onde: `sp_nc_admin_validar`; origem: Decisão do usuário (22/10/2026), migração 334.
- **RN-NC-56** [FEITO] — Toda ação da administração exige motivo (mínimo 5 caracteres) e vai para o histórico da NC com "antes → depois". Quem passa a ser gestor ou responsável é avisado.
  Onde: `sp_nc_admin_*`; origem: migração 334.
- **RN-NC-57** [FEITO] — Correção de ocorrência substitui as 12 telas de correção do legado: emitente, gestor, responsável, responsáveis das ações, data (não futura), unidade, lista, sumário, descrição, ação imediata, abrangência e, se o tipo tiver, fornecedor, quantidades e cliente.
  Onde: `principal/naoconformidades_correcao.php`, `sp_nc_admin_corrigir`; origem: migração 334.
- **RN-NC-58** [FEITO] — Etapa não é texto livre. A administração só pode: reabrir NC finalizada (volta à etapa anterior à finalização), devolver uma etapa (pelo histórico) ou encerrar administrativamente. A tela mostra o destino antes de confirmar.
  Onde: `sp_nc_admin_etapa`, `sp_nc_admin_etapa_destino`; origem: migração 334.
- **RN-NC-59** [FEITO] — Transferência de responsabilidades (desligamento/troca de função): lista o que está com o usuário (gestor, responsável, emitente de NCs abertas, ações abertas, listas) e transfere o que for marcado para um usuário ativo diferente.
  Onde: `principal/naoconformidades_transferencia.php`, `sp_nc_admin_transferir`; origem: migração 334.
- **RN-NC-60** [FEITO] — Painel de saneamento mostra NCs abertas sem gestor, com gestor/responsável inativo, paradas, antigas e com ações vencidas. Ações possíveis: atribuir gestor e lembrar responsáveis de ações vencidas.
  Onde: `principal/naoconformidades_saneamento.php`, `vw_nc_saneamento`; origem: migração 334.
- **RN-NC-61** [FEITO] — O painel não encerra NCs e NCs antigas não são alteradas em lote.
  Onde: `principal/naoconformidades_saneamento.php`; origem: Decisão do usuário (22/10/2026).
- **RN-NC-62** [FEITO] — Administração fica organizada em subgrupos: Ocorrências, Listas e participantes, Cadastros gerais, Cadastros do cliente, Configuração. O subgrupo aparece quando o usuário vê algum item dele.
  Onde: `NaoConformidadeMenus`, `NaoConformidadeAdministracao::SUBGRUPOS`; origem: pedido do usuário (22/10/2026), migração 335.

## 8. Configuração do módulo

- **RN-NC-63** [FEITO] — A Configuração do módulo é só do ADMINISTRADOR. Abas: Campos por tipo, Parâmetros, Lembretes, Lembretes enviados, Histórico.
  Onde: `principal/naoconformidades_configuracao.php`, menu `NAO_CONFORMIDADES_CONFIGURACAO`; origem: migração 339 (23/10/2026).
- **RN-NC-64** [FEITO] — Parâmetros da empresa: `EFICACIA_EXIGE_PRAZO`, `LISTAS_FORNECEDOR_SEPARADAS`, `IA_ANALISE_CAUSA`, `IA_EFICACIA`, `LEMBRETES_INCLUIR_LEGADO`, `LEMBRETES_DESDE`. Só chaves conhecidas; S/N ou data AAAA-MM-DD.
  Onde: `NaoConformidadeConfig::PARAMETROS`, `sp_nc_config_parametro_salvar`; origem: migração 339.
- **RN-NC-65** [FEITO] — Sem valor gravado valem os padrões, que repetem o comportamento anterior: Crown = S nas regras da Crown, demais = N; IA = S; lembretes do legado = N.
  Onde: `NaoConformidadeConfig::padrao`; origem: migração 339.
- **RN-NC-66** [FEITO] — Lembretes por situação: NC aguardando o gestor, NC devolvida ao emitente, análise parada, conclusão prevista vencida, ação vencendo, ação vencida, eficácia aguardando o gestor.
  Onde: `NaoConformidadeConfig::EVENTOS`, `vw_nc_lembrete_candidatos`; origem: Decisão do usuário (23/10/2026).
- **RN-NC-67** [FEITO] — Cada regra tem: dias, repetição (0 = uma vez), aviso ao superior após N lembretes (0 = nunca), envio de e-mail e filtro por tipos. Limites: dias 0–3650, repetição 0–365, superior 0–20.
  Onde: `sp_nc_lembrete_regra_salvar`; origem: migração 339.
- **RN-NC-68** [FEITO] — O serviço "nc" do Agendador processa as regras ativas: só destinatário ativo, até 500 por regra e execução, sem repetir antes do prazo. Grava alerta e, se marcado, e-mail. O superior vem de `usuarios.codusuario_superior`.
  Onde: `sp_nc_lembretes_processar`, `scripts/nc-lembretes-worker.php`; origem: migração 339.
- **RN-NC-69** [FEITO] — Por padrão os lembretes valem só para NCs do greenportal (o legado tem a própria política até a virada). `LEMBRETES_DESDE` limita às NCs criadas a partir da data.
  Onde: `sp_nc_lembretes_processar`; origem: migração 339.
- **RN-NC-70** [FEITO] — "Simular" na tela só conta quantos lembretes sairiam, sem enviar.
  Onde: `sp_nc_lembretes_processar(1)`; origem: migração 339.
- **RN-NC-97** [FEITO] — As regras de lembrete são criadas desligadas; cada empresa liga as que quiser. O serviço roda a cada 60 minutos (tarefa GreenPortal-NcLembretes) e aparece como NC_LEMBRETES em Administração › Serviços.
  Onde: `nc_lembrete_regras`, `scripts/criar-agendamento-nc-lembretes.ps1`; origem: migração 339 (23/10/2026). [PENDENTE] rodar a 339 em todos os bancos, instalar a tarefa e ligar as regras.

## 9. IA na análise da causa e na avaliação da eficácia

- **RN-NC-71** [FEITO] — A IA só sugere. Nada é gravado na NC até a pessoa revisar, marcar e aplicar. Aplicar usa as mesmas procedures da tela, numa transação, e registra no histórico.
  Onde: `NaoConformidadeAnaliseIa::aplicar`, `NaoConformidadeEficaciaIa::aplicar`; origem: migrações 336/337.
- **RN-NC-72** [FEITO] — Os prompts são cadastrados em Setup › Prompts de IA: `NC_ANALISE_CAUSA` e `NC_EFICACIA`. Sem prompt ativo, o pedido é recusado.
  Onde: `NaoConformidadeAnaliseIa::prompt`; origem: pedido do usuário (23/10/2026): "este também deve ser um prompt".
- **RN-NC-73** [FEITO] — O pedido roda em segundo plano (serviço "ia" do Agendador) e a resposta chega em Home › Alertas. Enquanto houver pedido do mesmo tipo na fila para a NC, novo pedido fica bloqueado (até 2 horas).
  Onde: `NaoConformidadeAnaliseIa::situacao`, `exigirSemPendente`; origem: Decisão do usuário (23/10/2026).
- **RN-NC-74** [FEITO] — Botões "Sugerir com IA" (análise da causa) e "Avaliar com IA" (eficácia) dependem dos parâmetros `IA_ANALISE_CAUSA` e `IA_EFICACIA`.
  Onde: `NaoConformidadeConfig`; origem: migração 339.
- **RN-NC-75** [FEITO] — Análise da causa: o contexto leva a ocorrência, análise e causas já cadastradas, tipos de causa da empresa e até 5 NCs semelhantes já tratadas que o usuário pode ver. Sugere até 4 causas e 6 ações.
  Onde: `NaoConformidadeAnaliseIa::contexto`, `sp_nc_ia_semelhantes`; origem: migração 336.
- **RN-NC-76** [FEITO] — Sugestão conferida: tipo de causa e responsável só dos cadastrados; prazo de 1 a 365 dias (padrão 30); alerta para causa já cadastrada ou causa sem ação.
  Onde: `NaoConformidadeAnaliseIa::normalizar`; origem: migração 336.
- **RN-NC-77** [FEITO] — Eficácia: quem pede é o gestor. O contexto leva ocorrência, causas, ações (status, prazos, reprogramações, observações), arquivos de evidência e até 8 NCs parecidas abertas depois desta (possível recorrência), só as que o gestor pode ver.
  Onde: `NaoConformidadeEficaciaIa`, `sp_nc_ia_recorrencias`; origem: migração 337.
- **RN-NC-78** [FEITO] — Evidências são lidas por OCR antes de ir à IA: anexos da ocorrência, evidências da análise e arquivos das ações; PDF, imagem e Word; até 20 arquivos de 25 MB; até 3.500 caracteres por arquivo e 40.000 no total. Espera as leituras até 2 horas.
  Onde: `NaoConformidadeEficaciaIa::lerEvidencias`, `prepararPendentes`; origem: Decisão do usuário (23/10/2026): escolha "B", migração 338.
- **RN-NC-79** [FEITO] — Parecer da IA: Eficaz, Não eficaz ou Inconclusivo. "Levar para a verificação" só grava o texto da verificação e pré-preenche o resultado; o resultado continua aplicado pelo gestor em "Aplicar o resultado".
  Onde: `NaoConformidadeEficaciaIa::aplicar`, `preenchimento`; origem: migração 337.
- **RN-NC-98** [FEITO] — Quando a IA termina (ou falha), o alerta traz o link que abre a NC como aba do portal, na aba certa, com a revisão aberta. Aplicar ou descartar fica no histórico da NC (IA_ANALISE_APLICADA/DESCARTADA, IA_EFICACIA_APLICADA/DESCARTADA). Situações do pedido: EXTRAINDO → SOLICITADA → SUGERIDA → APLICADA ou DESCARTADA; também ERRO e SUBSTITUIDA.
  Onde: `sp_nc_ia_analise_concluida`, `nc_ia_analises`; origem: migrações 336–338.
- **RN-NC-99** [FEITO] — Arquivo de evidência já lido pelo OCR é reaproveitado (chave pelo SHA-256 do conteúdo). Arquivo não lido aparece no contexto com o motivo; sem arquivos legíveis, o pedido vai direto à IA.
  Onde: `NaoConformidadeEficaciaIa`, `green_ocr_jobs`; origem: migração 338.
- **RN-NC-100** [FEITO] — O runner da 338 só atualiza o prompt NC_EFICACIA se ele nunca foi editado; se foi, mantém o texto da empresa e avisa a regra que falta acrescentar.
  Onde: runner 338; origem: c543 (23/10/2026).
- **RN-NC-80** [FEITO] — O pedido de eficácia confere antes o limite de consumo de IA da empresa.
  Onde: `IaTaximetro::exigirDisponivel`; origem: migração 340.

## 10. Relatórios e PDF

- **RN-NC-81** [FEITO] — O PDF da ocorrência é completo, com as mesmas informações das abas: Ocorrência, Avaliação do gestor, Análise da causa, Causas, Plano de ação (com reprogramação), Verificação da eficácia, Datas, Anexos, Histórico do fluxo e Histórico das ações.
  Onde: `classes/NaoConformidadeRelatorio.php`, `principal/naoconformidade_ocorrenciapdf.php`; origem: pedido do usuário (22/10/2026): "o PDF tem que vir completo".
- **RN-NC-82** [FEITO] — Um só layout para todos os tipos; os campos mostrados seguem os que o tipo usa. Planejamento e Assistência Técnica não têm a seção Avaliação do gestor nem coluna Causa.
  Onde: `principal/naoconformidade_relatoriopdftemplate.php`; origem: código (c526).
- **RN-NC-83** [FEITO] — Cabeçalho e rodapé do PDF trazem o nome da empresa (tenant); sem nome, "Green Portal".
  Onde: `NaoConformidadeRelatorio::montar`, `naoconformidade_relatoriopdftemplate.php`; origem: código.
- **RN-NC-84** [FEITO] — O PDF é gerado sob demanda, em memória (Dompdf), sem arquivo salvo no servidor. Só gera para quem pode ver a NC.
  Onde: `naoconformidade_ocorrenciapdf.php`; origem: análise de migração 21/08/2026.
- **RN-NC-85** [FEITO] — Anexos aparecem só pelo nome, e só para quem tem permissão de ver arquivos do GED (`ncocorrencia`).
  Onde: `NaoConformidadeRelatorio::anexos`; origem: código.
- **RN-NC-86** [FEITO] — Rótulos: "Tipo" = tipo da NC (`DSCTIPONAOCONFORMIDADE`); "Classificação" = classificação da ocorrência (`DSCTIPOOCORRENCIA`).
  Onde: grid e PDF; origem: análise de templates 21/08/2026.

- **RN-NC-101** [FEITO] — Anexos da NC ficam no GED nas pastas do legado (`nc_indicacao_ocorrencia`, `nc_analise_ocorrencia` pelo código da ocorrência, `nc_planodeacao` pelo código da ação). NC finalizada: anexos só consulta.
  Onde: `gestao_arquivos.php` (tipos ncocorrencia, ncanalise, ncplano); origem: migração "323" (21/10/2026).
- **RN-NC-102** [FEITO] — Quem enviou o arquivo tem acesso total a ele (ver, baixar/imprimir, excluir), mesmo sem essas permissões no perfil; os demais seguem o perfil. Em NC finalizada o dono ainda baixa, mas não exclui. Arquivos enviados pelo legado não têm dono.
  Onde: `sp_ged_arquivos_do_usuario`, `GedPermissaoTipo::arquivosDoUsuario`; origem: migração 330 (c521, 22/10/2026).

## 11. Cadastros auxiliares

- **RN-NC-87** [FEITO] — Cadastros gerais: Classificação, Classificação de fornecedores, Tipos de ocorrência, Origens, Sistemas de gestão, Setores, Tipos de causa. Cadastros do cliente: Clientes, Unidades do cliente, Produtos, Tipos de documento, Tipos de reclamação, Tipos de tampa, Tamanhos de lata.
  Onde: `NaoConformidadeCadastros::LISTA`; origem: migrações 327 e 332.
- **RN-NC-88** [FEITO] — Registros do legado (código abaixo de 50001) só consulta, exceto a coluna Ativo. Registros novos recebem código a partir de 50001 e o sincronismo não os altera.
  Onde: `NaoConformidadeCadastros::tela` (`legacy_below`, `legacy_editable`); origem: Decisão do usuário (21/10/2026 e 22/10/2026).
- **RN-NC-89** [FEITO] — Nome repetido é bloqueado ao incluir/alterar no greenportal (o sincronismo não é bloqueado).
  Onde: gatilho `trg_<tabela>_antes_incluir/alterar`; origem: migração 332.
- **RN-NC-90** [FEITO] — Registro do greenportal em uso em NC não pode ser excluído. A grade mostra Origem e "Em uso".
  Onde: `sp_<tabela>_validar_exclusao`; origem: migração 327.
- **RN-NC-91** [FEITO] — A tabela oficial de tipos de reclamação é `nc_tiporeclamacao`; a duplicada `nc_tipo_de_reclamacao` foi descartada no destino.
  Onde: `database/107_integridade_modulo_nc.sql`; origem: decisão registrada em 21/08/2026.

## 12. Testes

- **RN-NC-103** [FEITO] — NCs de teste só são apagadas pelo script de limpeza quando não existem no legado e o sumário (ou a descrição, quando o tipo não tem sumário) começa com "TESTE GREENPORTAL". A limpeza leva junto alertas e histórico.
  Onde: `scripts/limpar-nc-teste.php`; origem: c513.

## Pontos a confirmar

- [DECIDIDO] Gestor: a análise de 21/08/2026 dizia que só usuário com perfil GESTOR e do grupo GESTORES podia ser gestor; no legado qualquer usuário ativo é gestor (diagnóstico de 20/10/2026). Decisão do usuário (24/10/2026): "Qualquer usuário ativo" — continua como está, também depois da virada (RN-NC-17).
- [A CONFIRMAR] O que acontece com as NCs do legado depois da virada (faixa abaixo de 50001 continua só consulta?).
- [A CONFIRMAR] Tratamento definitivo dos registros órfãos do legado (ações sem causa/ocorrência, causas com tipo inexistente), preservados desde 21/08/2026.
- [PENDENTE] Nenhum item de NC foi encontrado em `docs/diretrizes-projeto.txt`; as diretrizes do módulo estão espalhadas nos cabeçalhos do código e nas migrações.
- [A CONFIRMAR] A NC 50001 da Crown (Planejamento, criada em 22/10/2026) é teste?
- [PENDENTE] Testar na Crown: fornecedor escolhendo responsável da ação, usuário USUARIOS criando NC, VISITANTE só consultando, data de resposta do fornecedor e leitura das evidências pela IA.

## Fontes

- Código: `classes/NaoConformidadeWorkflow.php`, `NaoConformidadeConfig.php`, `NaoConformidadeMenus.php`, `NaoConformidadeParticipantes.php`, `NaoConformidadeAdministracao.php`, `NaoConformidadeCadastros.php`, `NaoConformidadeAnaliseIa.php`, `NaoConformidadeEficaciaIa.php`, `NaoConformidadeRelatorio.php`.
- Telas: `principal/naoconformidades_ocorrencia.php`, `naoconformidades_configuracao.php`, `naoconformidades_correcao.php`, `naoconformidades_transferencia.php`, `naoconformidades_saneamento.php`, `naoconformidades_listageralocorrencias.php`, `naoconformidade_ocorrenciapdf.php`, `naoconformidade_relatoriopdftemplate.php`.
- Migrações: `database/321_nao_conformidades_base.sql`, `333_nc_administracao.sql`, `334_nc_administracao_correcoes.sql`, `336_nc_analise_ia.sql`, `337_nc_eficacia_ia.sql`, `338_nc_eficacia_evidencias.sql`, `339_nc_configuracao.sql`.
- Docs: `docs/analise_migracao_modulo_gestao_nao_conformidades_20260821.txt`, `docs/analise_templates_html_nao_conformidades_20260821.txt`, `docs/analise_templates_pdf_nc_planejamento_assistencia_tecnica_20260821.txt`, `docs/diretrizes-projeto.txt` (sem trechos de NC).
- Anotações do projeto: análise e reconstrução das NCs (20–22/10/2026), proposta da Administração (22/10/2026), IA na análise da causa e na eficácia (23/10/2026), configuração do módulo (23/10/2026).
- Observação: a parte de templates PDF por tipo (agosto/2026) foi substituída pelo relatório único (c526); vale o que está na seção 10.
