# Regras de negócio — Homologação de Fornecedores e Portaria

Atualizado em 25/10/2026. Documento de referência: o que o sistema faz hoje e as decisões do usuário. Legenda: [FEITO], [PENDENTE], [A CONFIRMAR].

Princípio geral: as telas leem só por views (`vw_homologacao_*`, `vw_portaria_*`, `vw_fo_*`) e gravam só por procedures (`sp_fo_*`, `sp_crud_*`). As mensagens das procedures (SIGNAL 45000) chegam ao usuário como estão. Até a virada, o legado prevalece e é sincronizado para o greenportal (seção 17).

## 1. Escopo e bancos

- **RN-HOM-01** [FEITO] — A homologação roda nos bancos `gmodelo_dbp`, `gatlas_dbp`, `gmodelo2_dbp`, `gcrown_dbp`, `ggdamericana_dbp` e `gganchieta_dbp`. Cada um tem o banco legado de origem e a pasta do GED do legado. Empresa nova entra na lista e recebe as migrações por `scripts/aplicar-homologacao-bancos.php`.
  Onde: `classes/HomologacaoBancos.php`; origem: Decisão do usuário (17/10/2026).
- **RN-HOM-02** [FEITO] — As conversões de dados já decididas valem só para os bancos "originais" (`gmodelo_dbp`, `gatlas_dbp`, `gmodelo2_dbp`).
  Onde: `HomologacaoBancos::ORIGINAIS`; origem: código.
- **RN-HOM-03** [FEITO] — A geração antiga do legado (sem prefixo `fo_`: avaliação documental/sistêmica, envio de critérios, categorias, certificados antigos, tabela `integracao`) fica fora do escopo: todas as tabelas estão vazias.
  Onde: análise do legado §8; origem: levantamento do banco 16/10/2026.

## 2. Fornecedores

- **RN-HOM-04** [FEITO] — O fornecedor pertence a uma unidade (`CODUNIDADE`). O mesmo CNPJ pode existir em várias unidades (um cadastro por unidade).
  Onde: `fo_fornecedores`; origem: legado (dados da Atlas).
- **RN-HOM-05** [FEITO] — Gestão de Fornecedores mostra só os ativos (`Ativado? = Sim`). A tela "Fornecedores desativados" mostra os demais (Não, vazio ou outro valor), com a mesma grade e os mesmos botões. Para reativar, mude "Ativado?" para Sim.
  Onde: `vw_homologacao_fornecedores_ativos_grid`, `vw_homologacao_fornecedores_desativados_grid`, `principal/homologacaofornecedores_fornecedoresdesativados.php`; origem: migração 296 e legado `fo_fornecedor.php?desativado=S`.
- **RN-HOM-06** [FEITO] — Fornecedor incluído sem "Ativado?" entra como Sim.
  Onde: gatilho `trg_fo_fornecedores_ativo_bi`; origem: migração 296 (o legado gravava `Sim` fixo).
- **RN-HOM-07** [FEITO] — Fornecedor x Usuários define quem responde pelo fornecedor (usuários do fornecedor externo). Desligar um usuário não altera os documentos: o responsável continua gravado, mas o usuário deixa de ver o fornecedor e as pendências dele. Só usuários existentes podem ser ligados.
  Onde: `sp_fo_fornecedor_usuarios_listar` / `_gravar`, `principal/homologacaofornecedor_fornecedorxusuarios.php`; origem: migração 296 e legado `fo_fornecedorxusuario.php`.
- **RN-HOM-08** [FEITO] — Um fornecedor pode ter vários tipos. Vincular um tipo gera os documentos dele (RN-HOM-17). Desvincular apaga o registro do tipo no fornecedor, inclusive a auditoria de homologação desse tipo; os documentos ficam guardados e só deixam de aparecer.
  Onde: procedure de tipos do fornecedor na migração 293, `principal/homologacaofornecedor_fornecedorxtipos.php`; origem: migração 293.
- **RN-HOM-09** [FEITO] — Gestão de Fornecedores tem oito relacionamentos, abertos com o fornecedor selecionado: contatos, certificados, auditoria, documentos, funcionários, produtos químicos, equipamentos e plano de ação. Sem fornecedor selecionado o grid não traz registros.
  Onde: `homologacaofornecedor_fornecedorx*.php`, `HomologacaoFornecedorRelacionamentoGrid`; origem: migração 089 e legado `fo_fornecedorxauditoria_c.php`.
- **RN-HOM-10** [FEITO] — Anexos das abas (certificados, FDS dos químicos, equipamentos, plano de ação, evidência da auditoria) ficam no GED do legado: `E:\projetoGreen\GED\green_<empresa>\<pasta>\<código>`.
  Onde: `classes/GedStorageService.php`; origem: migração 300.
- **RN-HOM-11** [FEITO] — Registros órfãos (ex.: 1.352 documentos e 30 funcionários com fornecedor inexistente) não são apagados nem reassociados automaticamente. Ficam fora das telas até o saneamento.
  Onde: `docs/foreign_keys_homologacao_fornecedores_20260819.md`, `docs/analise_homologacao_fornecedores_responsabilidades_20260821.md`; origem: decisão funcional (21/08/2026).

## 3. Cadastros: tipos, critérios e treinamentos

- **RN-HOM-12** [FEITO] — Tipo de fornecedor: descrição e "Necessita auditoria?" (Sim/Não). Os critérios (documentos exigidos) são ligados ao tipo com nível de exigência Obrigatório ou Desejável; critério novo sem nível entra como Obrigatório. Vazio só existe em dados antigos.
  Onde: `sp_fo_tipofornecedor_vinculos_gravar`, `sp_fo_tipofornecedor_nivel_gravar`, `principal/homologacaofornecedores_tiposfornecedores*.php`; origem: migração 292.
- **RN-HOM-13** [FEITO] — Tipo de fornecedor usado por fornecedores ou documentos não pode ser excluído. Ao excluir um tipo sem uso, os critérios e palavras-chave dele saem junto. Critério de fornecedor em uso (tipos ou documentos) também não pode ser excluído.
  Onde: `trg_fo_tipofornecedores_antes_excluir`, `trg_fo_criteriofornecedores_antes_excluir`; origem: migração 292.
- **RN-HOM-14** [FEITO] — Critério do funcionário tem tipo de documento: Competência ou Regularização de Ordem Legal. Critério em uso não pode ser excluído (coluna "Em uso").
  Onde: `sp_fo_criteriofuncionario_validar_exclusao`, `principal/homologacaofornecedores_criteriosfuncionarios.php`; origem: migração 245.
- **RN-HOM-15** [FEITO] — Tipo de funcionário tem três vínculos: critérios, treinamentos e palavras-chave (duas listas: disponíveis e vinculados; gravação imediata). Tipo usado por funcionários não pode ser excluído; ao excluir um tipo sem uso, os vínculos saem junto. Tipo de treinamento em uso não pode ser excluído.
  Onde: `sp_fo_tipofuncionario_vinculos_gravar`, `trg_fo_tipofuncionarios_antes_excluir`, `trg_fo_tipotreinamentos_antes_excluir`; origem: migrações 248 e 249.
- **RN-HOM-16** [FEITO] — Registros criados no greenportal nesses cadastros recebem código a partir de 100000, para nunca colidir com códigos do legado. As telas abrem já com a lista (o legado abria vazia).
  Onde: AUTO_INCREMENT das tabelas `fo_criteriofuncionarios`, `fo_tipofuncionarios`, `fo_tipotreinamentos`; origem: migrações 245 e 248 (diferença combinada).

## 4. Documentos do fornecedor

- **RN-HOM-17** [FEITO] — Há um documento por fornecedor × tipo do fornecedor × critério do tipo, para todos os tipos do fornecedor (o legado gerava só do primeiro). Os documentos são criados por gatilhos (tipo novo no fornecedor, critério novo no tipo) e por procedure, nunca ao abrir a tela.
  Onde: `sp_fo_fornecedor_documentos_gerar` e gatilhos da migração 293; origem: migração 293.
- **RN-HOM-18** [FEITO] — Não pode haver dois documentos com o mesmo fornecedor + tipo + critério. Os repetidos antigos foram juntados: ficou o mais preenchido, com os campos vazios completados, as evidências copiadas e o histórico redirecionado.
  Onde: índice `uk_fo_fornecedorxcriterios`, `scripts/aplicar-migracao-294-documentos-repetidos.php`; origem: migração 294.
- **RN-HOM-19** [FEITO] — Critério retirado do tipo ou tipo retirado do fornecedor: o documento e o histórico são preservados e só deixam de aparecer. Voltando o vínculo, o documento reaparece com os dados (o legado apagava).
  Onde: `vw_homologacao_fornecedor_documentos`; origem: migração 293.
- **RN-HOM-20** [FEITO] — Situações válidas: Aprovado, Aprovado sem validade, Ciente da regra, Reprovado, Pendente com a empresa, Pendente com o fornecedor, Não enviar ao fornecedor, NA (Não se aplica) e Aguardando vencimento do protocolo. Outro valor é recusado.
  Onde: `sp_fo_fornecedor_documento_salvar`; origem: migrações 293 e 300.
- **RN-HOM-21** [FEITO] — Reprovar exige a observação da empresa com o motivo ("Informe na observação da empresa o motivo da reprovação do documento.").
  Onde: `sp_fo_fornecedor_documento_salvar`; origem: legado `fo_auditoriacriterios.php` (validação no navegador).
- **RN-HOM-22** [FEITO] — "Enviado o documento" e "Incluído no contrato" aceitam só Sim ou Não. Dias de reação: número de 0 a 9999. Validade: data válida.
  Onde: `sp_fo_fornecedor_documento_salvar`; origem: migração 293.
- **RN-HOM-23** [FEITO] — O responsável do fornecedor precisa estar ligado ao fornecedor em Fornecedor x Usuários. O responsável da empresa precisa existir.
  Onde: `sp_fo_fornecedor_documento_salvar`; origem: migração 293 (o legado montava a lista com `fo_fornecedorxusuario`).
- **RN-HOM-24** [FEITO] — Escopo de gravação: a empresa (e o FORNECEDOR_GESTOR) grava todos os campos. O perfil FORNECEDOR grava só validade, protocolo, dias de reação, observação do fornecedor e evidências; não gera nem reinicia documentos.
  Onde: `sp_fo_fornecedor_documento_salvar` (p_escopo), `HomologacaoFornecedorAcesso::escopo()`; origem: migração 296 e legado `fo_auditoriacriterios_s.php`.
- **RN-HOM-25** [FEITO] — Protocolo: "Sim" (antes não estava) ou "Renovar" coloca o documento em "Aguardando vencimento do protocolo", com cobrança em hoje + 30 dias. "Renovar" grava Sim e sempre recalcula a cobrança; "Sim" mantém a cobrança existente. Tirar do protocolo com a situação aguardando volta para "Pendente com o fornecedor". Não ou vazio limpa a cobrança.
  Onde: `sp_fo_fornecedor_documento_salvar`; origem: migração 300 e legado `fo_auditoriacriterios_s.php`.
- **RN-HOM-26** [FEITO] — Todo dia às 06:40 o serviço do protocolo volta para "Pendente com o fornecedor" os documentos aguardando cuja cobrança chegou, encerra o protocolo (Não, sem data), grava o histórico e avisa os responsáveis (empresa e fornecedor) de fornecedores ativos em Home › Alertas. Sem data de cobrança, nada muda.
  Onde: `sp_fo_protocolo_vencidos_processar`, `scripts/homologacao-protocolo-worker.php`; origem: migração 300.
- **RN-HOM-27** [FEITO] — Documentos antigos com "Em protocolo = Sim" e sem cobrança passaram para "Aguardando vencimento do protocolo", com cobrança em 30 dias a partir da conversão. NA e "Não enviar ao fornecedor" não mudaram.
  Onde: `sp_fo_protocolo_sem_cobranca_converter`; origem: Decisão do usuário (17/10/2026), migração 302.
- **RN-HOM-28** [FEITO] — Copiar/colar: copia de um documento para outros do mesmo fornecedor só nos campos vazios do destino, e copia as evidências do GED sem substituir arquivos. Para outro fornecedor é recusado. O perfil FORNECEDOR copia só os campos dele.
  Onde: `sp_fo_fornecedor_documento_copiar`; origem: legado `fo_auditoriacriterios_copiar_colar_s.php` (que não conferia o fornecedor).
- **RN-HOM-29** [FEITO] — "Excluir" documento = reiniciar: limpa todos os dados (fica como recém-gerado). O histórico guarda o estado anterior. Exige a permissão Excluir da Gestão de Fornecedores.
  Onde: `sp_fo_fornecedor_documento_reiniciar`; origem: legado (excluir apagava e a linha voltava vazia).
- **RN-HOM-30** [FEITO] — Cada gravação deixa uma cópia completa em `fo_fornecedorxcriterios_historico` (quem e quando). A tela "Histórico de alterações de documentos" mostra a mais recente primeiro.
  Onde: `vw_homologacao_fornecedor_documentos_historico`, `principal/homologacaofornecedores_historicodocumentos.php`; origem: migrações 293 e 295.

## 5. Cores (regra única)

- **RN-HOM-31** [FEITO] — Regra única de cores (aba Documentos, Home, Painel, Relatórios, Ficha):
  VERMELHO = reprovado / não atende, vencido, pendente com o fornecedor;
  AMARELO = pendente com a empresa, ou vence dentro dos dias de reação (30 se não informado);
  VERDE = aprovado, aprovado sem validade, ciente da regra / atende, no prazo;
  NEUTRO (cinza) = NA, não enviar ao fornecedor, funcionário desligado, afastado ou NAP;
  BRANCO = sem situação.
  Onde: `vw_homologacao_fornecedor_documentos`, `vw_homologacao_painel_itens`; origem: migrações 293 e 298 (o legado tinha regras diferentes em cada tela).
- **RN-HOM-32** [FEITO] — Ordem de decisão do documento do fornecedor: NA / não enviar → cinza; Reprovado → vermelho; aguardando protocolo → amarelo até a cobrança (vermelho se a cobrança já passou); vencido → vermelho; pendente com o fornecedor → vermelho; pendente com a empresa → amarelo; vence nos dias de reação → amarelo; aprovado / sem validade / ciente → verde; senão branco.
  Onde: `vw_homologacao_fornecedor_documentos`; origem: migração 300.
- **RN-HOM-33** [FEITO] — "% atendido" = verdes ÷ (total − cinzas).
  Onde: `vw_homologacao_painel_itens`, `HomologacaoRelatorios`; origem: migração 298.
- **RN-HOM-34** [FEITO] — Cores das demais categorias: auditoria reprovada ou vencida = vermelho, vence nos dias de reação = amarelo, aprovada = verde, senão branco; nível de serviço Não satisfatório = vermelho, avaliação atrasada = amarelo, Satisfatório = verde; treinamento não realizado ou vencido = vermelho, vence em 30 dias = amarelo, realizado = verde; equipamento e produto químico reprovado ou vencido = vermelho, a vencer = amarelo, aprovado = verde; plano de ação 100% = verde, atrasado = vermelho, vence em 30 dias = amarelo.
  Onde: `vw_homologacao_painel_itens`; origem: migração 298.

## 6. Regras automáticas dos documentos (serviço de documentos)

Decisão do usuário (17/10/2026): "as mesmas regras [do legado], adaptadas ao greenportal; todo serviço novo é um PHP no Agendador do Windows". Adaptações: o motivo vai para o log, o histórico e o alerta (não é concatenado na observação); a decisão é pelo estado atual do documento; Reprovado continua Reprovado até o fornecedor agir; documento de funcionário vencido com arquivo passa a "Não" (corrige um defeito do legado). As regras dependem de o GED ter os arquivos de cada documento.

- **RN-HOM-35** [FEITO] — A cada 60 minutos o serviço de documentos conta os arquivos de cada documento no GED (data do mais novo incluída) e aplica as regras de `fo_regras_documentos`. Cada regra pode ser desligada por empresa (`ativo`).
  Onde: `scripts/homologacao-documentos-worker.php`, `sp_fo_regras_documentos_listar` / `_aplicar`; origem: migração 301, adaptada do serviço do legado `enviadoodocumento.php`.
- **RN-HOM-36** [FEITO] — Não mudam: NA, Não enviar ao fornecedor, Aguardando vencimento do protocolo e documento em protocolo em vigor (cobrança de hoje em diante ou sem data). Protocolo com cobrança vencida segue as regras.
  Onde: `sp_fo_regras_documentos_aplicar`; origem: migração 301.
- **RN-HOM-37** [FEITO] — Regras do fornecedor, na ordem:
  FORN_ENVIADO — "Enviado o documento" = Sim com arquivo, Não sem arquivo (menos NA);
  FORN_VENCIDO — vencido e não está com o fornecedor (nem Reprovado) → Pendente com o fornecedor;
  FORN_SEM_ARQUIVO — sem arquivo (menos Aprovado sem validade, Ciente da regra, Reprovado e já pendente com o fornecedor) → Pendente com o fornecedor;
  FORN_SEM_RESPONSAVEL — sem responsável da empresa ou do fornecedor, validade futura e ainda não aprovado → Pendente com a empresa;
  FORN_ATUALIZADO — com arquivo, não vencido, pendente com o fornecedor / reprovado / sem situação, e o fornecedor agiu depois da última ação da empresa → Pendente com a empresa.
  Onde: `fo_regras_documentos`, `sp_fo_regras_documentos_aplicar`; origem: migração 301.
- **RN-HOM-38** [FEITO] — "O fornecedor agiu" = alteração feita por usuário ligado ao fornecedor (Fornecedor x Usuários) ou arquivo novo no GED depois da última ação da empresa (o serviço conta como empresa). Reprovado continua Reprovado até o fornecedor agir.
  Onde: `sp_fo_regras_documentos_aplicar` (histórico); origem: migração 301.
- **RN-HOM-39** [FEITO] — Regras do funcionário (menos NAP, Afastado e Desligado; só funcionários ativos):
  FUNC_SEM_ARQUIVO — sem arquivo → Atende = Não, Pendente com o fornecedor;
  FUNC_VENCIDO — validade vencida → Atende = Não, Pendente com o fornecedor (no legado esta regra nunca era alcançada);
  FUNC_SEM_RESPONSAVEL — com arquivo, sem responsável da empresa ou do fornecedor → Atende = Sim, Pendente com a empresa;
  FUNC_ATENDE — com arquivo e validade em dia (ou sem validade) → Atende = Sim, sem pendência.
  Onde: `sp_fo_regras_documentos_aplicar`; origem: migração 301.
- **RN-HOM-40** [FEITO] — O motivo de cada mudança vai para o log (`fo_regras_documentos_log`), para o histórico (só quando a situação muda) e para o alerta do responsável; não é somado às observações (o legado escrevia no texto). Alertas: um por responsável e escopo, só de fornecedores ativos (empresa quando foi para "Pendente com a empresa"; fornecedor quando foi para "Pendente com o fornecedor").
  Onde: `homologacao-documentos-worker.php`, `vw_fo_regras_documentos_log`; origem: migração 301.

## 7. Auditoria de homologação e cronograma

- **RN-HOM-41** [FEITO] — A auditoria é registrada por fornecedor × tipo, só para tipos com "Necessita auditoria = Sim": validade, dias de reação, nota, aprovado (Sim/Não) e evidência no GED (`fo_fornecedorxauditoria`).
  Onde: `vw_homologacao_fornecedor_auditoria_grid`, `fo_fornecedoresxtipofornecedores`; origem: legado `fo_auditoriatipos.php`, migração 300.
- **RN-HOM-42** [FEITO] — Cronograma da auditoria: eventos (categoria 2 – Auditoria) com facilidades necessárias, auditores e check list; a unidade do evento é a do fornecedor. Incluir evento só em tipo com Necessita auditoria = Sim. Exclusão só de evento da própria auditoria. A coluna Cronograma mostra os eventos ainda não vencidos.
  Onde: `principal/eventos.php?codauditoria=N`, procedures da migração 317; origem: migração 317 e legado `ev_schedule_c.php`.
- **RN-HOM-43** [A CONFIRMAR] — Faixa da nota e nota mínima para aprovar não estão definidas. Os relatórios rotulam "Nota (0 a 100)"; o ranking trata nota até 10 como escala 0–10 (multiplica por 10).
  Onde: `HomologacaoRelatorios`, migração 289; origem: análise do legado §6.4 item 8.

## 8. Funcionários, critérios e treinamentos

- **RN-HOM-44** [FEITO] — Gestão de Funcionários lista só funcionários ligados a um fornecedor real. Sem fornecedor, com fornecedor inexistente ou provisório ("... - migração 217"), o registro fica no banco e não aparece.
  Onde: migração 218; origem: código.
- **RN-HOM-45** [FEITO] — Critérios (documentos) e treinamentos do funcionário são gerados pelo tipo dele: na inclusão do funcionário, na troca de tipo e na inclusão de critério/treinamento no tipo. Nada é apagado: itens do tipo antigo ficam guardados. Só gera itens cujo critério/treinamento existe no cadastro.
  Onde: gatilhos e procedure da migração 303; origem: legado (gerava ao abrir a aba ou ao salvar).
- **RN-HOM-46** [FEITO] — Telas e relatórios consideram o documento do funcionário só quando é do tipo atual dele, o critério é exigido por esse tipo e ainda existe no cadastro. Os demais continuam no banco.
  Onde: `vw_homologacao_painel_itens` (DOCFUNC), `sp_fo_relatorio_unificado`; origem: migração 315 (comparação com a Crown, 19/10/2026).
- **RN-HOM-47** [FEITO] — "Atende ao critério?" aceita Sim, Não, NAP (não aplicável), Desligado e Afastado. Critérios e treinamentos são filtrados juntos por funcionário e tipo de funcionário.
  Onde: `homologacaofornecedor_funcionarioxcriterios.php`, `_funcionarioxtreinamentos.php`; origem: migração 090 e legado.
- **RN-HOM-48** [FEITO] — O perfil FORNECEDOR não exclui funcionários.
  Onde: `principal/homologacaofornecedor_funcionario.php`; origem: migração 297 e legado.
- **RN-HOM-107** [substituída pela RN-HOM-121] — Excluir funcionário apagava só o funcionário, como no legado; os itens preenchidos ficavam guardados e os itens em branco eram removidos pela limpeza da 303 (--limpar). Sem documentos nem treinamentos preenchidos, continua assim.
- **RN-HOM-121** [FEITO em 25/10/2026] — Funcionário com algum documento (Atende preenchido ou validade) ou treinamento (realizado ou validade) preenchido não pode ser excluído: o banco recusa com a mensagem "em vez de excluir, marque como Desligado". O sincronismo do legado não é bloqueado.
  Onde: `trg_fo_funcionarios_excluir_bd` (migração 350); origem: Decisão do usuário (24/10/2026): "Bloquear e usar Desligado".
  Onde: `sp_fo_funcionario_itens_limpar`; origem: comparação legado × greenportal (17/10/2026).
- **RN-HOM-49** [FEITO] — Integração (cor): sem data = branco; vencida = vermelho; vence nos dias de reação (30 se não informado) = amarelo; senão verde. A data é `fo_funcionarios.DATA_VALIDADE`.
  Onde: `vw_homologacao_painel_itens` (INTEGRACAO); origem: migração 298 e relatório de integração do legado.

## 9. Integração de funcionários (questionário, decisão e certificado)

- **RN-HOM-50** [FEITO] — Quatro etapas: cadastro → questionário → avaliação → decisão. O questionário é o do tipo de funcionário; sem um próprio, vale o padrão da empresa (configuração com tipo vazio). Só um padrão e uma configuração por tipo.
  Onde: `fo_integracao_config`, `sp_fo_integracao_config_*`; origem: Decisão do usuário (24/10/2026), migração 348.
- **RN-HOM-51** [FEITO] — Configuração: questionário obrigatório e publicado; nota mínima de 0 a 100; validade da integração de 1 a 120 meses (padrão 12); prazo do link de 1 a 90 dias (padrão 7).
  Onde: procedure de configuração da migração 348; origem: migração 348.
- **RN-HOM-52** [FEITO] — Quem responde é o próprio funcionário, por um link só dele, sem senha, válido até o prazo e para uma resposta. Gerar outro link cancela o anterior que ainda esperava resposta. O código do link não é gravado, só o hash.
  Onde: `sp_fo_integracao_criar`, `integracao.php`, `IntegracaoFuncionario::novoToken`; origem: Decisão do usuário (24/10/2026).
- **RN-HOM-53** [FEITO] — Ao responder, a nota das respostas comparada com a nota mínima (70 quando não informada) sugere Aprovado ou Reprovado. Resposta com link vencido não é aceita. O funcionário não vê a nota. O responsável pela integração do fornecedor (senão quem gerou o link) recebe um alerta.
  Onde: `sp_fo_integracao_respondida`, `IntegracaoFuncionario`; origem: migração 348.
- **RN-HOM-54** [FEITO] — Uma pessoa decide, só depois da resposta. Reprovar, ou aprovar abaixo da nota mínima, exige motivo (mínimo 5 caracteres).
  Onde: `sp_fo_integracao_decidir`; origem: Decisão do usuário (24/10/2026).
- **RN-HOM-55** [FEITO; alterada pela RN-HOM-119] — Aprovada: o certificado registra a validade = hoje + meses da configuração. O funcionário fica Liberado na portaria até essa data e o certificado em PDF vai para os arquivos do funcionário. Reprovada: gera-se novo link para refazer.
  Onde: `sp_fo_integracao_decidir`, `sp_fo_integracao_certificado`, `principal/homologacaofornecedores_integracao.php`; origem: Decisão do usuário (24/10/2026).
- **RN-HOM-56** [FEITO] — Se o sincronismo trouxer uma data de integração mais antiga, o serviço de documentos reaplica a última integração aprovada.
  Onde: `sp_fo_integracao_reaplicar`, `homologacao-documentos-worker.php`; origem: migração 348.

- **RN-HOM-119** [FEITO em 25/10/2026] — `fo_funcionarios.DATA_VALIDADE` é a data de **realização** da integração (como o legado rotula e grava). A validade é calculada: realização + meses da configuração da integração do tipo de funcionário (sem configuração do tipo, a do padrão da empresa; sem nenhuma, 12 meses). A integração aprovada no portal grava a realização = data da decisão. Vale para Painel, relatórios, Portaria e tela de integração, inclusive para as datas vindas do legado.
  Onde: `vw_fo_integracao_validade` (migrações 298, 348 e 350), `sp_fo_integracao_decidir`, `sp_fo_integracao_reaplicar`; origem: Decisão do usuário (24/10/2026): "Legado = data de realização". Efeito esperado: as integrações do legado passam a vencer 12 meses depois da realização (na Crown, 583 de 698 apareciam vencidas tratando a data como validade). Na aplicação de 25/10/2026 as cores não mudaram em nenhum banco: não há data de integração do legado nos últimos 12 meses (Crown continua com 583 vencidas). Na MODELO/MODELO2 há 4 datas no futuro, provavelmente validade digitada no campo de realização: corrigir no legado.
- **RN-HOM-123** [FEITO em 25/10/2026] — Funcionário desligado não recebe link de integração: inativo no cadastro ou com todos os critérios preenchidos como "Desligado" (como o legado registra o desligamento). A tela mostra "Desligado (sem link de integração)" e o banco recusa gerar o link. O texto "Desligado" no número do registro (texto livre) não conta. Na MODELO, em 25/10/2026: 402 de 1.001 funcionários.
  Onde: `vw_fo_funcionario_desligado`, `sp_fo_integracao_criar` (migração 348), `principal/homologacaofornecedores_integracao.php`; origem: teste da integração de 25/10/2026 (aprovada a integração de um funcionário desligado), decisão do usuário (25/10/2026).
- **RN-HOM-124** [FEITO em 25/10/2026] — Questionário modelo do funcionário `INTEGRACAO_FUNCIONARIO_PADRAO` (6 perguntas: meio ambiente, segurança, regras da unidade; nota mínima 70%) publicado e ligado como padrão da MODELO (12 meses, link de 7 dias). O checklist "Integração de Funcionários" (perguntas para quem integra) continua em rascunho. Teste: link, resposta (83,3%, sugestão Aprovado), aprovação e certificado conferidos.
  Onde: Questionários de Integração de Funcionários, configuração da tela de integração; origem: decisão do usuário (25/10/2026).

## 10. Acesso: fornecedor externo e restrição por unidade

- **RN-HOM-57** [FEITO] — Três modos de acesso: 0 = vê tudo (ADMINISTRADOR, ADM_DA_EMPRESA); 1 = fornecedor externo (FORNECEDOR, FORNECEDOR_GESTOR); 2 = interno restrito às suas unidades (demais perfis). A mesma regra existe em SQL para os serviços sem sessão.
  Onde: `HomologacaoFornecedorAcesso::modo()`, `vw_homologacao_usuario_acesso`; origem: migrações 296 e 316.
- **RN-HOM-58** [FEITO] — Fornecedor externo vê só os fornecedores ativos ligados a ele em Fornecedor x Usuários, e os funcionários deles. A ligação é conferida no servidor em cada leitura e gravação (403 se não for dele).
  Onde: `sp_fo_acesso_registro`, `vw_homologacao_fornecedores_usuario_grid`, `vw_homologacao_funcionarios_usuario_grid`; origem: migrações 296 e 297.
- **RN-HOM-59** [FEITO] — O perfil FORNECEDOR não vê as abas Certificados, Auditoria, Produtos químicos, Equipamentos e Plano de ação (bloqueadas também no servidor). Os dois perfis externos não acessam: Ranking, Desativados, Histórico de documentos, cadastros de tipos e critérios, e a Portaria.
  Onde: `HomologacaoFornecedorAcesso::ABAS_OCULTAS_FORNECEDOR`, `MENUS_SO_EMPRESA`; origem: migrações 296 e 297 e legado.
- **RN-HOM-60** [FEITO] — Na Crown, o perfil FORNECEDORES equivale a FORNECEDOR. GESTORES continuam internos.
  Onde: `HomologacaoFornecedorAcesso::PERFIS_EQUIVALENTES`, `vw_homologacao_usuario_acesso`; origem: Decisão do usuário (20/10/2026), migração 319.
- **RN-HOM-61** [FEITO] — Interno restrito vê os fornecedores das suas unidades (`usuariosxunidades` + unidade do usuário) e também aqueles pelos quais responde, mesmo de outra unidade: responsável pelo fornecedor ou pela integração, documento, critério ou treinamento de funcionário, plano de ação, responsabilidade atribuída. Sem unidade, vê só esses.
  Onde: `vw_homologacao_fornecedores_acesso_interno`, `sp_fo_acesso_interno_registro`; origem: Decisão do usuário (19/10/2026), migração 316.
- **RN-HOM-108** [FEITO] — Interno restrito só grava fornecedor numa unidade que é dele. A mesma restrição vale para Painel, Ficha, Relatórios, planilhas, envio semanal, GED e questionário do fornecedor; ADM_DA_EMPRESA vê as evidências de todas as unidades. Ranking, Histórico de documentos e as pendências da Home não têm filtro por unidade (como no legado).
  Onde: `HomologacaoFornecedorAcesso`, `sp_fo_painel_itens`, `sp_fo_relatorio_*` (p_externo = 2); origem: migração 316 (19/10/2026).
- **RN-HOM-62** [FEITO] — A restrição por unidade só vale nos bancos em que a migração 316 foi aplicada; antes dela o interno continua vendo tudo.
  Onde: `HomologacaoFornecedorAcesso::modoBanco()`; origem: código.
- **RN-HOM-63** [FEITO] — O que cada perfil faz em cada tela (visualizar, incluir, editar, excluir, exportar, arquivos) vem de Segurança › Permissões. Os menus novos nascem sem perfil liberado; ADMINISTRADOR tem acesso por regra.
  Onde: `scripts/aplicar-migracao-247-permissoes-transacao.php`; origem: migrações 245, 248, 295.

## 11. Portaria

- **RN-HOM-64** [FEITO] — Documentação de Fornecedor e Documentação de Funcionário são só consulta (sem incluir, alterar ou excluir), com exportação para Excel e evidências no GED. Documentação de Funcionário mostra só funcionário com nome e fornecedor com unidade.
  Onde: `principal/portaria_documentacaofornecedor.php`, `portaria_documentacaofuncionario.php`; origem: migrações 260 e 262, legado `foFornecedorxCriterios` / `foFuncionariosXCriterios`.
- **RN-HOM-65** [FEITO] — ADMINISTRADOR vê todas as unidades; os demais perfis só as suas, mesmo com "Visualizar total". A regra vale também para contar e enviar arquivos.
  Onde: `vw_portaria_documentacao_*_acesso`, `sp_portaria_docfornecedor_permitido`, `vw_portaria_usuario_unidades`; origem: migrações 260, 262, 263.
- **RN-HOM-66** [FEITO] — Perfis: PORTARIA visualiza, exporta, vê e envia arquivos. HOMOLOGACAO e ADM_DA_EMPRESA: o mesmo, mais excluir arquivos e Editar (análise por IA).
  Onde: migrações 260, 261, 262 (via 247); origem: código.
- **RN-HOM-67** [substituída pela RN-HOM-120] — Cores da Portaria › Fornecedor: vermelho = Reprovado ou Aprovado vencido; laranja = Aprovado que vence em até 30 dias; verde = Aprovado / Aprovado sem validade; amarelo = Pendente com o fornecedor; azul = Pendente com a empresa; cinza = NA, Não enviar, Ciente da regra; sem cor = sem situação.
  Onde: `SITUACAO_COR` da migração 260; origem: migração 260.
- **RN-HOM-68** [substituída pela RN-HOM-120, menos treinamentos] — Cores da Portaria › Funcionário: cinza = Desligado, Afastado ou NAP; vermelho = Sim vencido ou Não sem pendência; laranja = Sim que vence em até 30 dias; verde = Sim; amarelo / azul = pendente com o fornecedor / com a empresa. Treinamentos: vermelho = não realizado ou vencido; laranja = vence em até 30 dias; verde = realizado.
  Onde: migrações 262 e 263; origem: código.
- **RN-HOM-69** [FEITO] — Coluna Situação com ícone da cor + texto; o filtro de situação filtra no servidor (todas as páginas e o Excel).
  Onde: `docs/portaria_documentacaofornecedor.md`; origem: código.
- **RN-HOM-70** [FEITO] — Liberação do funcionário na portaria: Liberado enquanto a validade da integração (RN-HOM-119) for hoje ou depois; senão "Não liberado". Fornecedor bloqueado no ranking: "Não liberado (fornecedor bloqueado)" (RN-HOM-122).
  Onde: `principal/homologacaofornecedores_integracao.php` (coluna Portaria); origem: migração 348.
- **RN-HOM-120** [FEITO em 25/10/2026] — Cores da Portaria (fornecedor e funcionário) pela regra única do Painel, nesta ordem: vermelho escuro = fornecedor bloqueado no ranking; cinza = NA / Não enviar ao fornecedor (funcionário: Desligado, Afastado, NAP); vermelho = Reprovado / Não atende, validade vencida ou Pendente com o fornecedor; amarelo = Pendente com a empresa ou vence dentro dos dias de reação do documento (sem dias de reação, 30); verde = Aprovado, Aprovado sem validade, Ciente da regra / Atende; sem cor = sem situação. Treinamentos continuam com 30 dias fixos (ponto 12).
  Onde: `SITUACAO_COR` das migrações 260 e 262 (regravadas pela 350), `assets/css/portaria-documentacao-fornecedor.css`, ajuda das duas telas; origem: Decisão do usuário (24/10/2026): "Usar a regra do Painel".

## 12. Análise de documentos pela IA

- **RN-HOM-71** [FEITO] — Na janela de arquivos, "Analisar com IA" extrai o texto no servidor (PDF, Word, imagem com OCR) e envia à IA com o prompt `FORNECEDOR_DOCUMENTO` ou `FUNCIONARIO_DOCUMENTO`, junto com o documento exigido e o cadastro. Exige Editar na transação.
  Onde: `principal/api/fornecedorcriterios_ia.php`, `funcionariocriterios_ia.php`; origem: migrações 261 e 262.
- **RN-HOM-72** [FEITO] — Conferências do fornecedor: documento corresponde ao exigido (Sim/Não/Incerto); razão social diferente do cadastro (semelhança abaixo de 75%); CNPJ com raiz (8 dígitos) diferente; documento vencido; leitura por OCR. Cada uma vira alerta.
  Onde: `HomologacaoDocumentoIa::fornecedor`; origem: migração 261.
- **RN-HOM-73** [FEITO] — Conferências do funcionário: nome diferente (semelhança abaixo de 80%); CPF diferente do Nº de Registro; tipo do documento x critério (ASO ou NR de número diferente); documento vencido; leitura por OCR.
  Onde: `HomologacaoDocumentoIa::funcionario`; origem: migração 262.
- **RN-HOM-74** [FEITO] — Dados de saúde não saem do servidor: linhas com diagnóstico, CID, exames, sintomas e afins são removidas e códigos da CID-10 mascarados antes do envio. Da IA só voltam campos previstos (de saúde, só a aptidão do ASO). O número de linhas omitidas vira alerta.
  Onde: `classes/FuncionarioDocumentoTexto.php`; origem: migrações 221 e 262.
- **RN-HOM-75** [FEITO] — A IA só sugere. A pessoa revisa e confirma. Fornecedor: grava validade, situação (sugerida: Aprovado / Aprovado sem validade / Reprovado se vencido), enviado e observação. Funcionário: grava validade, atende (pela aptidão do ASO ou pelo treinamento; Não se vencido), situação e observações.
  Onde: `sp_docfornecedor_ia_confirmar`, `sp_docfuncionario_ia_*`; origem: migrações 261 e 262.
- **RN-HOM-76** [FEITO] — O mesmo conteúdo já lido não é reenviado à IA. Reanálise só para ADM da empresa ou ADMINISTRADOR.
  Onde: `GedAnaliseIaRegistro::podeReanalisar`, chave de idempotência da fila; origem: migração 261.

## 13. Modelos de documento e validação automática

- **RN-HOM-77** [FEITO] — Cada critério (de fornecedor ou de funcionário) pode ter um modelo: itens que o documento precisa ter (obrigatórios ou recomendados; até 30), orientação para a IA e regras fixas (conferir com o cadastro, assinatura/carimbo, validade escrita, emitido há no máximo N dias, validade mínima de N dias). Arquivo de exemplo opcional: o texto lido serve para a IA sugerir os itens.
  Onde: `fo_documento_modelos`, `principal/homologacaofornecedores_modelo_documento.php`; origem: migração 347 (Onda 2 da apresentação Atlas).
- **RN-HOM-78** [FEITO] — Validação automática: a cada 5 minutos, documento com modelo ativo em "Pendente com a empresa" e arquivo novo (enviado depois da ativação, ou todos com "validar também os pendentes") vai para OCR e IA; até 20 arquivos por empresa por varredura. O parecer item a item fica como análise pendente de revisão e o responsável da empresa é avisado.
  Onde: `HomologacaoDocumentoModelo` (`varrer`, `preparar`, `aoConcluirJob`); origem: migração 347.
- **RN-HOM-79** [FEITO] — O mesmo conteúdo no mesmo documento é validado uma vez só. Aprovar, reprovar ou devolver é sempre decisão de uma pessoa. Acesso pela permissão do cadastro de critérios.
  Onde: procedures `sp_doc_modelo_*`; origem: migração 347.

## 13a. Análise de adulteração dos documentos

Decisões do usuário (24/10/2026): conferir metadados do PDF, assinatura digital, imagem e foto, e o conteúdo pela IA; rodar automaticamente quando o arquivo chega; apontar o risco e exigir motivo para aprovar documento de risco alto.

- **RN-HOM-109** [FEITO] — Todo arquivo novo de documento de fornecedor e de funcionário (enviado depois da ativação da migração 349) é analisado sozinho pelo serviço "ia" do Agendador: a varredura roda a cada 10 minutos e só abre as pastas do GED que mudaram. Arquivos que já estavam no GED são analisados só pelo botão "Analisar este arquivo". O mesmo conteúdo (hash) no mesmo documento é analisado uma vez.
  Onde: `HomologacaoDocumentoAdulteracao::processar`, `sp_doc_adult_candidatos`, `sp_doc_adult_registrar`; origem: migração 349 (aplicada em 24/10/2026 nos 6 bancos da homologação).
- **RN-HOM-110** [FEITO] — Verificações: metadados (editor de imagem ou de PDF no programa que gerou ou editou o arquivo, PDF modificado mais de 1 dia depois de criado, PDF salvo por cima do original, Word modificado por outra pessoa); assinatura digital do PDF (o conteúdo confere com a assinatura; o arquivo foi alterado depois da última assinatura); imagem (programa de edição nos dados da foto; região com compressão diferente do resto); conteúdo (CNPJ/CPF com dígito inválido, data impossível, emissão no futuro, validade antes da emissão, emissão depois da última gravação do arquivo) e a leitura da IA (prompt `DOC_ADULTERACAO`), que aponta incoerências do texto.
  Onde: `HomologacaoDocumentoAdulteracao::verificar`; origem: migração 349.
- **RN-HOM-111** [FEITO] — Risco: um indício de gravidade alta (ou três médios) = ALTO; um médio = MÉDIO; só baixos = BAIXO; nenhum = sem indícios. Indícios informativos (ex.: assinatura íntegra) não contam. São altos: assinatura que não confere, alteração depois da última assinatura, CNPJ/CPF inválido em texto do próprio PDF e emissão depois da última gravação do arquivo.
  Onde: `HomologacaoDocumentoAdulteracao::risco`; origem: migração 349.
- **RN-HOM-112** [FEITO] — Sem texto legível, sem o prompt ou com o limite mensal de IA atingido, a análise termina só com as verificações fixas, e isso fica registrado. Texto de documento de funcionário vai à IA sem os dados de saúde. A cadeia do certificado digital (ICP-Brasil) não é conferida; só a integridade da assinatura.
  Onde: `HomologacaoDocumentoAdulteracao::preparar`; origem: migração 349.
- **RN-HOM-113** [FEITO] — Risco médio ou alto avisa o responsável da empresa pelo documento (sem responsável, os ADM DA EMPRESA), com link para a aba Adulteração dos Arquivos do documento.
  Onde: `HomologacaoDocumentoAdulteracao::alertar`; origem: migração 349.
- **RN-HOM-114** [FEITO] — Documento com arquivo de risco ALTO sem ciência não pode ser aprovado: fornecedor (Aprovado / Aprovado sem validade) e funcionário (Atende = Sim). O banco recusa a gravação, venha de qual tela vier. O sincronismo do legado não é bloqueado (o legado prevalece até a virada).
  Onde: gatilhos `trg_fo_doc_adult_forn_bu` e `trg_fo_doc_adult_func_bu`, `vw_doc_adult_pendentes`; origem: migração 349.
- **RN-HOM-115** [FEITO] — A ciência é registrada na aba Adulteração por quem tem Editar na Portaria (Documentação de fornecedor/funcionário) ou na Gestão de Fornecedores/Funcionários, com motivo de pelo menos 10 caracteres; fica no log com quem e quando. Vale para risco médio (opcional) e alto (necessária para aprovar). Excluir o arquivo suspeito também libera a aprovação.
  Onde: `sp_doc_adult_ciente`, `sp_doc_adult_arquivo_excluido`, `principal/api/gestao_arquivos.php` (ações adulteracao*); origem: migração 349.
- **RN-HOM-116** [FEITO] — Regras automáticas: FORN_ADULTERACAO leva o documento aprovado, ciente ou sem situação com arquivo de risco alto sem ciência para "Pendente com a empresa"; FUNC_ADULTERACAO deixa o documento do funcionário em Atende = Não e "Pendente com a empresa" (e impede que FUNC_ATENDE o aprove). As duas nascem ligadas só onde as outras regras do mesmo escopo estão ligadas (em 24/10/2026: ligadas só na MODELO2).
  Onde: `sp_fo_regras_documentos_aplicar` (migração 301 regravada pela 349); origem: migração 349.
- **RN-HOM-117** [FEITO] — O fornecedor externo não vê a análise de adulteração (nem a aba, nem o ícone na lista de arquivos).
  Onde: `principal/gestao_arquivos.php`, `HomologacaoFornecedorAcesso::externo`; origem: migração 349.
- **RN-HOM-118** [FEITO] — A análise só aponta indícios; a decisão do documento é de uma pessoa. O uso da IA entra no taxímetro (tipo `DOC_ADULTERACAO`).
  Onde: `IaTaximetro::TIPOS`; origem: migração 349.

## 14. Ranking e bloqueio

- **RN-HOM-80** [FEITO] — Nota 0–100 por fornecedor ativo = média ponderada: documentação do fornecedor 35, dos funcionários 25, não conformidades 25, auditoria 15. Componente sem dados sai da conta e o peso é dividido entre os outros. Sem documentos, funcionários e auditoria = "Sem dados" (sem nota e sem faixa).
  Onde: `vw_fornecedor_ranking_calculo`, `scripts/aplicar-migracao-289-ranking-fornecedores.php`; origem: migração 289.
- **RN-HOM-81** [FEITO] — Componentes: documentos = % de critérios em dia (aprovado ou vence em até 30 dias; NA fora); funcionários = % de critérios e treinamentos em dia (desligados e afastados fora); NC = 100 − 15 por aberta − 10 por atrasada − 5 por fechada nos últimos 12 meses (atrasada = prazo vencido ou aberta há mais de 90 dias); auditoria = nota, ou aprovada 100 / reprovada 0, vencida vale metade.
  Onde: idem; origem: migração 289.
- **RN-HOM-82** [FEITO] — Faixas: A ≥ 85, B ≥ 70, C ≥ 50, D abaixo. Pesos somam 100; faixas A > B > C > 0 e A ≤ 100. Cada mudança guarda antes/depois e pede novo cálculo.
  Onde: `fo_ranking_config`, procedure de configuração da 289, tela Administração › Pesos do ranking; origem: migração 289.
- **RN-HOM-83** [FEITO] — O cálculo é feito só pelo serviço agendado (a cada 15 min verifica): uma vez por dia, quando os pesos mudam ou em "Recalcular agora". Guarda uma nota por fornecedor por dia.
  Onde: `scripts/fornecedor-ranking-worker.php`, `sp_fornecedor_ranking_atualizar`, `fo_ranking_historico`; origem: migração 289.
- **RN-HOM-84** [FEITO] — Bloquear e desbloquear exigem motivo com pelo menos 10 caracteres; tudo fica no histórico (BLOQUEADO, ALTERADO, DESBLOQUEADO). Na Gestão de Fornecedores a linha do bloqueado aparece em vermelho suave, com o motivo.
  Onde: `sp_fornecedor_bloquear`, `sp_fornecedor_desbloquear`, `principal/homologacaofornecedor_fornecedor.php`; origem: migração 289.
- **RN-HOM-85** [FEITO] — ADMINISTRADOR, ADM DA EMPRESA e GESTOR veem o ranking de todas as unidades; os demais, só das suas.
  Onde: `FornecedorRanking::visaoTotal()`; origem: migração 289.
- **RN-HOM-86** [substituída pela RN-HOM-122] — O bloqueio só aparecia na tela. Não impedia portaria, documentos nem outra operação.
  Onde: busca no código (`fo_fornecedor_bloqueios` usado só no ranking e no grid); origem: análise do legado §8 ("efeito do bloqueio" em aberto).
- **RN-HOM-122** [FEITO em 25/10/2026] — Fornecedor bloqueado no ranking: (1) Portaria — os documentos do fornecedor e dos funcionários aparecem "Fornecedor bloqueado (não liberado)" e a integração mostra "Não liberado (fornecedor bloqueado)"; no Relatório unificado os funcionários ficam Bloqueado, com o motivo nas pendências; (2) novos documentos — o banco recusa aprovar documento do fornecedor (Aprovado / Aprovado sem validade) e do funcionário (Atende = Sim) enquanto durar o bloqueio, venha de qual tela vier, e as regras automáticas não aprovam; documentos já aprovados continuam como estão; (3) aviso — a linha em vermelho com o motivo na Gestão de Fornecedores (RN-HOM-84) e as mensagens acima. Não impede contratação nem outras operações. O sincronismo do legado não é bloqueado.
  Onde: `trg_fo_bloqueio_forn_bu`, `trg_fo_bloqueio_func_bu`, `vw_fo_fornecedores_bloqueados` (migração 350), 301 regravada, `HomologacaoRelatorios::unificado`, `principal/homologacaofornecedores_integracao.php`; origem: Decisão do usuário (24/10/2026): "Portaria, Novos documentos, Só aviso".

## 15. Painel, relatórios, Home e Ficha

- **RN-HOM-87** [FEITO] — Painel de Homologação (menu `HOMOLOGACAO_PAINEL`) acompanha só fornecedores ativos, em nove categorias: documentos, auditoria, nível de serviço, integração, documentos de funcionário, treinamentos, equipamentos, químicos e plano de ação. Filtros: unidade, tipo, busca, "Sou eu" / "Sem responsável" e vencimento (vencidos, 30, 90 dias).
  Onde: `sp_fo_painel_itens`, `principal/homologacaofornecedores_painel.php`; origem: migração 298 (substitui a árvore de 12 nós e o dashboard do legado).
- **RN-HOM-88** [FEITO] — Permissões do Painel: visualizar = ver; exportar = Excel (pela fila) e PDF; editar = "Cobrar responsáveis" (alertas). Fornecedor externo vê só os seus, sem ranking e sem cobrança.
  Onde: `homologacaofornecedores_painel.php`; origem: migração 298.
- **RN-HOM-89** [FEITO] — Relatórios de Homologação usam as mesmas cores e filtros do Painel, com filtros rápidos (válidos, vencidos, vencem nos dias de reação, pendentes, em protocolo, aprovados, reprovados, sem responsável, sem situação). Ordem padrão: vermelho, amarelo, branco, verde, cinza.
  Onde: `classes/HomologacaoRelatorios.php`, `sp_fo_relatorio_itens`; origem: migração 314.
- **RN-HOM-90** [FEITO] — Relatório unificado de funcionários: Bloqueado = algum item exigido vermelho ou não avaliado; Vence em breve = algum amarelo; Liberado = todos verdes. Cinza e itens que o tipo não exige não contam. A integração é sempre exigida.
  Onde: `HomologacaoRelatorios::unificado`; origem: migração 314.
- **RN-HOM-91** [FEITO] — "Meus relatórios": visões salvas por usuário, com envio semanal opcional por alerta (uma vez por semana ISO). O escopo é o do dono quando salvou.
  Onde: `fo_relatorios_visoes`, `scripts/homologacao-relatorios-worker.php`; origem: migração 314.
- **RN-HOM-92** [FEITO] — Resumo semanal (segundas às 07:10): cada responsável com itens vermelhos ou amarelos recebe um alerta com o link do Painel em "Sou eu", salvo se desligou. Não repete na mesma semana.
  Onde: `sp_fo_painel_resumo_semanal`, `scripts/homologacao-resumo-worker.php`; origem: migração 298.
- **RN-HOM-93** [FEITO] — Home › Minhas pendências: documentos do fornecedor em que a pessoa é a responsável interna, de fornecedor ativo, visíveis na aba Documentos e em "Pendente com a empresa", sem situação, ou aprovado vencido / vencendo nos dias de reação; documentos de funcionário de fornecedor ativo (sem Desligado, Afastado e NAP) com Atende = Não ou vazio, ou Sim vencido / vencendo em 30 dias.
  Onde: `vw_homologacao_pendencias_documento_interno`, `vw_homologacao_pendencias_funcionario_interno`; origem: Decisões do usuário (18/10/2026), migração 310.
- **RN-HOM-94** [FEITO] — Pendências do fornecedor externo: documentos em que ele é o responsável do fornecedor (e continua ligado ao fornecedor), de fornecedor ativo, vermelhos, amarelos ou sem situação, menos "Pendente com a empresa".
  Onde: `vw_homologacao_pendencias_fornecedor_externo`; origem: migração 296 e legado.
- **RN-HOM-95** [FEITO] — Ficha do fornecedor: abre do Painel (ou de qualquer tela com `?CODFORNECEDOR=N`), com os mesmos dados e cores do Painel: documentos, funcionários, treinamentos, equipamentos, químicos, plano de ação e histórico. Permissão do Painel; fornecedor externo só os seus.
  Onde: `principal/homologacaofornecedores_ficha.php`; origem: c455 (19/10/2026), migração 313.

## 16. Responsabilidades e Módulo Legal

- **RN-HOM-96** [FEITO] — Tipos de responsabilidade: `HOMOLOGACAO_FORNECEDOR` (pelo fornecedor) e `HOMOLOGACAO_DOCUMENTACAO` (pela documentação). A consulta e a manutenção abrem a partir do fornecedor ou do funcionário selecionado; exclusão é lógica e preserva o histórico. O campo de responsável legado em `fo_fornecedores` não é alterado.
  Onde: `principal/fornecedor_responsabilidades*.php`, `funcionario_responsabilidades*.php`, migrações 112 e 113; origem: análise 21/08/2026 e 22/08/2026.
- **RN-HOM-97** [PENDENTE] — `fo_funcionarioxtiposxtreinamentos.codusuario` (responsável pelo treinamento) não é mostrado como responsabilidade do funcionário até decisão funcional.
  Onde: `docs/responsabilidades_gestao_funcionarios_20260822.md`; origem: decisão pendente.
- **RN-HOM-98** [FEITO] — Requisito legal com Poder = Influência pode ser ligado a fornecedores (por requisito e unidade). O botão Homologação de Fornecedores só fica habilitado com uma linha selecionada e Poder = Influência. Só inclui e exclui; o combo traz só fornecedores ainda não associados. Permissão da transação `REQUISITOS_LEGAIS`.
  Onde: `principal/manutencaolegalrequisitoslegais_homologacao.php`, `legislacaoxfornecedores`; origem: migração 141.

## 17. Relação com o legado e o sincronismo

- **RN-HOM-99** [FEITO] — Até a virada, o cadastro é feito no legado. O sincronismo diário (horário único configurado em Administração › Migração › Sincronismo) inclui, altera (legado prevalece) e exclui no greenportal o que mudou no legado; antes de excluir guarda cópia em `fo_sinc_exclusoes`. Por padrão só simula; grava com `--aplicar`.
  Onde: `scripts/sincronizar-fo-legado.php`; origem: Decisão do usuário (18/10/2026): "o legado continua mandando até a virada".
- **RN-HOM-100** [FEITO] — Em MODELO, Atlas e MODELO2 são mantidos no greenportal (não sincronizados): critérios, tipos e treinamentos de funcionário e seus vínculos; tipos e critérios de fornecedor com nível e palavras-chave; documentos do fornecedor, histórico e tipos do fornecedor (com a auditoria); Fornecedor x Usuários. Nas demais empresas (ex.: Crown) o legado prevalece em todas as tabelas `fo_*`.
  Onde: `SINC_MESTRE_NOVO`, `SINC_MESTRE_NOVO_BANCOS`; origem: Decisão do usuário (18/10/2026), migrações 245, 292, 293, 296.
- **RN-HOM-101** [FEITO] — Consequência: o que é criado no legado nessas tabelas mestre-novo não chega ao greenportal (cadastrar também na tela nova).
  Onde: `docs/homologacaofornecedores_criteriosfuncionarios.md`; origem: migração 245.
- **RN-HOM-102** [FEITO] — Proteções do sincronismo: provisórios da migração 217 nunca são excluídos; registro com referência inexistente não entra; registro ainda referenciado não é excluído; histórico só recebe inclusões de fornecedores reais; uma execução por banco de cada vez; os gatilhos de geração ficam parados (os itens vêm prontos do legado); repetição de índice único é ignorada e contada.
  Onde: `scripts/sincronizar-fo-legado.php`; origem: código.
- **RN-HOM-103** [FEITO] — A proteção dos campos confirmados no greenportal (`fo_sinc_protecoes`, ex.: validade confirmada pela IA) está desligada: o legado sobrescreve também esses campos. O registro da proteção é mantido.
  Onde: `SINC_RESPEITAR_PROTECOES = false`; origem: Decisão do usuário (18/10/2026).
- **RN-HOM-104** [FEITO] — As unidades de cada usuário (`usuariosxunidades`) vêm do legado (legado prevalece), base da restrição por unidade e da Portaria.
  Onde: `scripts/sincronizar-fo-legado.php`, migração 316; origem: migração 316.
- **RN-HOM-105** [FEITO] — Tipos de funcionário iguais ao legado: os que não existem no legado (código < 100000) foram excluídos; funcionários com código antigo ficam com a função em branco.
  Onde: `scripts/aplicar-migracao-251-tipos-funcionarios-igual-legado.php`; origem: migração 251.
- **RN-HOM-106** [A CONFIRMAR] — Exceções fixas do legado (usuário `Portaria.CPS` vê todos os fornecedores na árvore; `joice.mota` sem carga automática; variantes `_crown`) não foram levadas ao greenportal.
  Onde: análise do legado §3.4 e §6.4 item 11; origem: legado.

## Pontos a confirmar

1. ~~Efeito do bloqueio do ranking.~~ Decidido em 24/10/2026: Portaria, novos documentos e aviso; contratação não (RN-HOM-122).
2. Proteção de campos confirmados pela IA: a doc da Portaria diz "protegidos contra o sincronismo", mas a proteção foi desligada em 18/10/2026 (RN-HOM-103). Atualizar a doc da Portaria ou religar após a virada?
3. ~~Cores da Portaria × Painel.~~ Decidido em 24/10/2026: usar a regra do Painel (RN-HOM-120).
4. ~~Data da integração.~~ Decidido em 24/10/2026: é a data de realização; validade = realização + meses (RN-HOM-119).
5. Auditoria: faixa da nota e nota mínima para aprovar (RN-HOM-43).
6. Status consolidado "fornecedor homologado" (documentos + auditoria + nível de serviço) não existe; só o Liberado/Bloqueado por funcionário.
7. Plano de ação gerado a partir de documento reprovado ou NC: não existe; continua manual?
8. Responsável por treinamento (`fo_funcionarioxtiposxtreinamentos.codusuario`): semântica (RN-HOM-97).
9. Saneamento dos órfãos e da diferença de 5 funcionários entre legado e novo (Modelo).
10. Data da virada e dono definitivo dos dados.
11. Escopo do sub-módulo de avaliação de desempenho/criticidade (SAP) e das palavras-chave (tabelas vazias no legado).
12. Treinamentos usam 30 dias fixos no amarelo, sem os dias de reação: manter?
13. Exceções fixas do legado (RN-HOM-106): ainda são necessárias?

## Fontes

- Anotações do projeto: análise da homologação do legado, etapas implementadas, comparação legado × greenportal (MODELO e Crown), regras automáticas dos documentos, aplicação nos bancos, restrição por unidade, relatórios gerenciais, modelos de documento e integração de funcionários.
- docs: `homologacao_fornecedores_transacoes.md`, `homologacaofornecedores_criteriosfuncionarios.md`, `homologacaofornecedores_tiposfuncionarios.md`, `homologacaofornecedores_tipostreinamentos.md`, `portaria_documentacaofornecedor.md`, `portaria_documentacaofuncionario.md`, `analise_homologacao_fornecedores_responsabilidades_20260821.md`, `responsabilidades_gestao_funcionarios_20260822.md`, `relacionamento_requisito_homologacao_influencia_diretrizes.md`, `foreign_keys_homologacao_fornecedores_20260819.md`, `integridade_indices_homologacao_fornecedores_20260819.md`, `diretrizes-projeto.txt` (perfis padrão e validação com o legado).
- database: 218, 221, 245, 248, 249, 260–263, 289, 292, 293, 296, 297, 298, 300–303, 314, 316, 317, 347, 348.
- classes: `HomologacaoFornecedorAcesso.php`, `HomologacaoRelatorios.php`, `FornecedorRanking.php`, `HomologacaoDocumentoModelo.php`, `HomologacaoDocumentoIa.php`, `FuncionarioDocumentoTexto.php`, `IntegracaoFuncionario.php`, `HomologacaoBancos.php`.
- scripts: `sincronizar-fo-legado.php`, `homologacao-documentos-worker.php`, `homologacao-protocolo-worker.php`, `homologacao-resumo-worker.php`, `fornecedor-ranking-worker.php`, `aplicar-migracao-289/294/295/310/313/315/319-*.php`.
- principal: telas `homologacaofornecedor*`, `homologacaofornecedores_*`, `portaria_*`, `manutencaolegalrequisitoslegais_homologacao.php`.
