# Regras de negócio — Migração de empresas e convivência com o legado

Atualizado em 25/10/2026. Documento de referência: o que o sistema faz hoje e as decisões do usuário. Legenda: [FEITO], [PENDENTE], [A CONFIRMAR].

## 1. Princípios de convivência com o legado

**RN-MIG-01** — Até a virada definitiva, o legado prevalece: o cadastro é feito só no legado e o GreenPortal é mantido igual a ele. [FEITO]
Onde: scripts/sincronizar-fo-legado.php (cabeçalho), principal/administracao_sincronismo.php; origem: código.

Decisão do usuário (18/10/2026): "o legado continua mandando até a virada" e "o que está no legado tem que estar no greenportal".

**RN-MIG-02** — O legado nunca é alterado. Migração e sincronismo só leem os bancos green_*; toda gravação é no banco novo. [FEITO]
Onde: tela Sincronismo ("O legado não é alterado."), sincronizar-fo-legado.php; origem: código.

**RN-MIG-03** — Telas do legado que gravam ao abrir não podem ser abertas em testes ou comparações. Exemplos na Homologação: aba Documentos do fornecedor, telas de pendências e seleção de linha na Gestão de Fornecedores. Elas geram linhas em fo_fornecedorxcriterios ou treinamentos. [FEITO]
Onde: teste visual do legado de 16/10/2026; origem: analise-homologacao-fornecedores-legado.md (seções 3.1 e 7).

**RN-MIG-04** — Antes de corrigir ou homologar relacionamentos migrados, compara-se registro a registro legado × tenant. Só se ignora a chave técnica auto_increment. As chaves de negócio contam, inclusive unidade e empresa. [FEITO]
Onde: diretrizes do projeto ("Validação de relacionamentos com o legado"); origem: diretrizes-projeto.txt.

**RN-MIG-05** — Toda correção de dados é versionada, registrada em registros_migracao e reexecutável sem duplicar nem perder registros novos válidos. [FEITO]
Onde: diretrizes do projeto; origem: diretrizes-projeto.txt.

**RN-MIG-91** — Estrutura: vale a do GreenPortal, partindo do gmodelo_dbp; tabelas e colunas novas do GreenPortal continuam. Dados: vêm do legado. Nada do legado pode se perder; pode ser adaptado, desde que o usuário encontre a informação. [FEITO]
Onde: migração e sincronismo; origem: decisão do usuário (18/10/2026).

**RN-MIG-92** — O GED físico é o mesmo nos dois sistemas: os anexos do legado e do GreenPortal ficam nas mesmas pastas (ex.: E:\projetoGreen\GED\green_modelo). [FEITO]
Onde: GedStorageService; origem: decisão do usuário (17/10/2026).

**RN-MIG-93** — Toda operação que o administrador faz no legado precisa ter no GreenPortal resultado igual ou melhor; não precisa ser igual na forma de operar. A base do GreenPortal não precisa estar 100% igual ao legado antes da virada; a carga final é feita na virada. Os números do dashboard do legado são desconsiderados: os painéis do GreenPortal são a referência. [FEITO]
Onde: comparação legado × GreenPortal (empresa MODELO); origem: decisões do usuário (17/10/2026).

**RN-MIG-94** — Ordem dos módulos que ainda faltam migrar: 1) Não conformidades (feito); 2) resto do Módulo Legal (requisitoslegais, legislacaoxunidade, condicionantes); 3) histórico do GED, Eventos e Monitoramento. Antes de cada módulo, levanta-se onde a informação fica no GreenPortal. [PENDENTE para os itens 2 e 3; condicionantes já sincronizadas desde 18/10/2026]
Onde: plano de migração; origem: decisão do usuário (18/10/2026).

**RN-MIG-95** — Tabelas de sobra do legado só são desconsideradas quando o usuário decide, e a decisão fica registrada na rotina (SincronismoLegado::DESCONSIDERADAS) para não ser migrada de novo. [FEITO]
Onde: classes/SincronismoLegado.php; origem: decisão do usuário (18/10/2026).

## 2. Cadastro central de empresas e nomes de bancos

**RN-MIG-06** — O cadastro central fica em guniversal_dbp.empresa. Ele alimenta o combo de empresas do login e a validação da empresa em credenciais/logar.php. [FEITO]
Onde: guniversal_dbp; origem: migracao_empresa_universal_diretrizes.txt.

**RN-MIG-07** — As empresas vêm de green_seguranca.empresas, preservando CODEMPRESA em codempresa e codigo_legado. Só entram empresas com banco cadastrado. Empresa bloqueada = 'S' fica INATIVA; as demais ficam ATIVA. [FEITO]
Onde: classes/EmpresaMigracaoService.php::sincronizar(); origem: código, gestao_migracao_empresas_266.txt.

**RN-MIG-08** — O nome do banco novo é derivado do legado: tira-se o prefixo green_, eliminam-se os separadores e aplica-se g…_dbp (green_gd_americana → ggdamericana_dbp). O nome é derivado internamente; não vem de formulário nem de URL. [FEITO]
Onde: EmpresaMigracaoService.php; origem: gestao_migracao_empresas_266.txt.

**RN-MIG-09** — A sincronização do cadastro é idempotente. A empresa é localizada pelo código legado ou pelo banco já conhecido, e a reexecução só atualiza (validado em 14/10/2026: 44 empresas atualizadas, sem duplicidade). [FEITO]
Onde: EmpresaMigracaoService.php; origem: gestao_migracao_empresas_266.txt.

**RN-MIG-10** — Cada empresa tem uma configuração em empresa_migracao_config: bancos legados, banco novo, status_migracao e sincronismo_ativo. O status começa em PENDENTE_VALIDACAO e sincronismo_ativo começa em 0. [FEITO]
Onde: EmpresaMigracaoService.php; origem: código.

**RN-MIG-11** — Cada empresa tem um único banco novo (g<empresa>_dbp), que guarda também a segurança (usuários, grupos, perfis, permissões). Não se cria banco _seguranca_dbp. banco_novo_seguranca é preenchido com o próprio banco novo. [FEITO]
Onde: EmpresaMigracaoService.php ($novoSeg); migração 272; origem: inventario_migracao_goodyear_267.txt, homologacao_final_migracao_goodyear_274.txt.

Decisão (14/10/2026, migração 272): banco único por empresa. O ggdamericana_seguranca_dbp não existia no servidor, então não houve dump nem DROP.

**RN-MIG-12** — O login resolve o tenant pelo cadastro central e nunca recebe livremente o nome, host, usuário ou senha do banco. [FEITO]
Onde: login; origem: diretrizes-projeto.txt (2.2), diretrizes-legado-migrado-atlas.md.

**RN-MIG-13** — Senhas e credenciais dos bancos não aparecem em grids, exportações nem logs. [FEITO]
Onde: telas de migração e worker (credenciais lidas da configuração do servidor); origem: analise_gestao_migracao_bases_legadas.txt (item 12).

## 3. Telas de Administração › Migração

**RN-MIG-14** — O menu Administração › Migração tem três itens: Migração geral (ADMINISTRACAO_EMPRESAS_MIGRACAO), Sincronismo (ADMINISTRACAO_SINCRONISMO) e Menus de migração (ADMINISTRACAO_MIGRACAO_MENUS). A migração 266 não pode desfazer esse agrupamento. [FEITO]
Onde: EmpresaMigracaoService::garantirMenu(), migrações 304/305; origem: código.

**RN-MIG-15** — As telas de migração e de sincronismo só abrem para o perfil ADMINISTRADOR (os outros recebem 403) e todas as ações exigem token CSRF. [FEITO]
Onde: principal/administracao_migracao_empresas.php, principal/administracao_sincronismo.php; origem: código.

**RN-MIG-16** — As telas só gravam pedidos, sempre por procedures (sp_migracao_*, sp_sinc_*). Quem executa é o serviço no Agendador do Windows. [FEITO]
Onde: telas acima, migrações 275 e 305; origem: código, levantamento-sql-direto.md.

## 4. Solicitação e fila de migração de uma empresa

**RN-MIG-17** — Uma solicitação informa empresa, bancos de origem, banco de destino, caminho do GED e observações, e nasce com status EM_CONSTRUCAO. Enquanto estiver EM_CONSTRUCAO, pode ser editada ou excluída. [FEITO]
Onde: administracao_migracao_empresas.php (sp_migracao_solicitacao_criar/atualizar/excluir); origem: gestao_solicitacoes_migracao_275.txt, código.

**RN-MIG-18** — "Iniciar migração" muda a solicitação para EM_EXECUCAO. O worker (\GreenPortal\GreenPortal-Migracao-Worker, a cada minuto, como SYSTEM) pega uma solicitação por vez. [FEITO]
Onde: scripts/migracao-worker.bat/.php, sp_migracao_worker_reivindicar; origem: gestao_solicitacoes_migracao_275.txt.

**RN-MIG-19** — O banco destino precisa seguir o padrão g<nome>_dbp. As origens precisam existir e ter nome válido, e a pasta do GED precisa existir. Caso contrário, a solicitação vai para ERRO na etapa VALIDAR_CONFIGURACAO. [FEITO]
Onde: migracao-worker.php; origem: código.

**RN-MIG-20** — O worker executa nesta ordem: VALIDAR_CONFIGURACAO, PROVISIONAR_DESTINO, BACKUP_DESTINO, MIGRAR_SEGURANCA, REPLICAR_PROMPTS_IA, MIGRAR_MODULO_LEGAL, AUDITAR_ORFAOS, MAPEAR_GED e VALIDAR_RESULTADO. [FEITO]
Onde: migracao-worker.php; origem: código, controle_orfaos_migracao.txt, teste_goodyear_anchieta_provisionamento_276.txt.

**RN-MIG-21** — Se uma etapa falha, o worker registra a etapa, a mensagem e o stderr técnico com o código de retorno, e a solicitação vai para ERRO. Com todas as etapas concluídas, ela fica FINALIZADO_COM_SUCESSO. [FEITO]
Onde: migracao-worker.php (sp_migracao_etapa_erro, sp_migracao_finalizar); origem: gestao_solicitacoes_migracao_275.txt, controle_orfaos_migracao.txt.

**RN-MIG-22** — O log fica no banco (migracao_logs) e em storage/logs/migracao/migracao_<id>.log. O link "Ver log" abre um popup com os logs e os últimos órfãos. [FEITO]
Onde: administracao_migracao_log.php; origem: gestao_solicitacoes_migracao_275.txt.

**RN-MIG-23** — Uma solicitação em ERRO pode ser reprocessada (sp_migracao_solicitacao_reprocessar): as etapas pendentes são limpas, o evento é registrado e o status volta para EM_EXECUCAO. [FEITO]
Onde: procedure; origem: controle_orfaos_migracao.txt.

**RN-MIG-24** — Simulação sem gravação (scripts/simular-migracao.php): não cria banco, não faz backup nem carga, e gera um relatório JSON de dry-run. [FEITO]
Onde: scripts/simular-migracao.php; origem: código, controle_orfaos_migracao.txt.

**RN-MIG-25** — O caminho do GED é normalizado para E:\projetoGreen\GED\green_<empresa> ("Green_" vira "green_") e gravado em path_ged_normalizado. Nenhum arquivo é movido. [FEITO]
Onde: migracao-worker.php; origem: controle_orfaos_migracao.txt.

## 5. Provisionamento e backup do destino

**RN-MIG-26** — O gmodelo_dbp é a referência estrutural. Toda empresa migrada ou provisionada tem a mesma estrutura-base, sem dados de outra empresa. [FEITO]
Onde: diretrizes 2.2.1; origem: diretrizes-projeto.txt, inventario_migracao_goodyear_267.txt.

**RN-MIG-27** — Se o destino não existe, o worker cria o banco em utf8mb4_0900_ai_ci e importa a estrutura do gmodelo_dbp (sem dados, com rotinas, gatilhos e eventos). [FEITO]
Onde: migracao-worker.php (PROVISIONAR_DESTINO); origem: teste_goodyear_anchieta_provisionamento_276.txt.

**RN-MIG-28** — O backup completo do destino é feito depois do provisionamento e antes de qualquer carga, em runtime/backups/solicitacao_<id>/<banco>.sql. [FEITO]
Onde: migracao-worker.php (BACKUP_DESTINO); origem: código.

**RN-MIG-29** — O tenant novo fica INATIVO até a homologação. Testes de login usam ativação temporária e o status é restaurado ao fim. [FEITO]
Onde: scripts/homologar-login-goodyear-267.php, clonagem do Modelo2; origem: inventario_migracao_goodyear_267.txt, clonagem_tenant_modelo2_20260922.md.

## 6. Regras de carga dos dados

**RN-MIG-30** — As chaves primárias do legado (COD<ENTIDADE>) são preservadas, sem criar coluna id artificial. Chaves compostas são mantidas. [FEITO]
Onde: todas as migrações; origem: diretrizes-projeto.txt (15), migracao_legislacao_diretrizes.txt.

**RN-MIG-31** — Todo banco legado é tratado como latin1. Os textos são convertidos para utf8mb4 / utf8mb4_0900_ai_ci e validados com amostras acentuadas. [FEITO]
Onde: migrações; origem: diretrizes-projeto.txt (14.1).

**RN-MIG-32** — Toda migração registra origem, destino, tipo, versão, status, quantidade e detalhes em registros_migracao do banco afetado. [FEITO]
Onde: scripts de migração; origem: diretrizes-projeto.txt (13), documentos das migrações.

**RN-MIG-33** — Tabelas exclusivas do legado (act_*, auditoria_*, ged_*, licenci*, historico_* etc.) não são copiadas só pelo nome. Cada domínio precisa de mapeamento funcional. [FEITO]
Onde: decisão técnica da Goodyear; origem: inventario_migracao_goodyear_267.txt.

**RN-MIG-34** — Referências inválidas ou iguais a zero para unidade, usuário ou grupo viram NULL. Nenhuma referência inválida fica no tenant. [FEITO]
Onde: migração 269 (licenças Goodyear), 273; origem: inventario_migracao_goodyear_267.txt.

**RN-MIG-35** — USERID repetido no legado: na Goodyear, os dois registros foram mantidos e o segundo recebeu sufixo (paulo → paulo_82). Na migração 233, valeu a linha de maior CODUSUARIO, sem criar usuários novos. [FEITO]
Onde: migrações 267 e 233; origem: inventario_migracao_goodyear_267.txt, migracao_usuarios_legado_233.md.

**RN-MIG-36** — Os arquivos do GED não são movidos nem copiados. O acesso usa o caminho legado E:\projetoGreen\GED\green_<empresa>\<tabela>\<chave>\<arquivo>. [FEITO]
Onde: GedStorageService, etapa MAPEAR_GED; origem: homologacao_final_migracao_goodyear_274.txt.

## 7. Segurança e usuários migrados

**RN-MIG-37** — Cada grupo legado gera um perfil no tenant. Perfis oficiais equivalentes são reutilizados (ADMINISTRADOR, ADM DA EMPRESA → ADM_DA_EMPRESA, FORNECEDOR). Não ficam dois perfis ativos para o mesmo grupo. [FEITO]
Onde: scripts/migrar-seguranca-generico.php, migrar-perfis-permissoes-atlas.php; origem: diretrizes-legado-migrado-atlas.md.

**RN-MIG-38** — Permissões vêm de gruposxmenus e são ligadas ao menu atual pelo código ou pelo caminho completo do menu, nunca pelo número. O que não tiver correspondência fica NAO_MAPEADA e não libera acesso por aproximação. [FEITO] Atlas: 650 vínculos NAO_MAPEADA. [PENDENTE]
Onde: atlas_permissoes_legado; origem: diretrizes-legado-migrado-atlas.md.

**RN-MIG-39** — Usuário com vários grupos recebe a união das permissões. As associações ficam em atlas_usuarios_perfis_legado, e usuarios.codperfil guarda só o primeiro perfil, para compatibilidade. Um perfil efetivo personalizado (efetivo = 1) restringe o usuário sem apagar os grupos. [FEITO]
Onde: sessão do usuário; origem: diretrizes-legado-migrado-atlas.md.

**RN-MIG-40** — Senhas migram no formato legado (senha_usa_hash = 0) para permitir o primeiro acesso. A conversão para hash acontece quando o usuário altera a senha. [FEITO]
Onde: migrar-seguranca-generico.php; origem: inventario_migracao_goodyear_267.txt, código.

**RN-MIG-41** — Campos legados de usuários ausentes no novo são acrescentados sem remover os canônicos. EMAILS serve de fallback do e-mail, e USUARIO_SUPERIOR é convertido pelo USERID do superior. A migração só atualiza usuários que já existem no destino. [FEITO]
Onde: migração 233, scripts/migrar-usuarios-legado-233.php; origem: migracao_usuarios_legado_233.md.

**RN-MIG-42** — Antes de ativar um tenant, compara-se o acesso efetivo legado × GreenPortal por usuário, grupo, menu e operação. [PENDENTE] para a Atlas, enquanto houver NAO_MAPEADA.
Onde: homologação; origem: diretrizes-legado-migrado-atlas.md.

## 8. Prompts de IA

**RN-MIG-43** — O gmodelo_dbp é a fonte oficial dos prompts de IA. A etapa REPLICAR_PROMPTS_IA copia prompts e configurações (PT, EN, ES, versão e status) para o destino antes da carga do Módulo Legal, e falha se o destino não ficar REPLICADO. [FEITO]
Onde: scripts/replicar-prompts-ia.php, migracao-worker.php; origem: controle_orfaos_migracao.txt, código.

**RN-MIG-44** — A replicação também pode ser pedida na tela (vai para a fila do worker) ou rodada em todos os bancos com --target=*. Banco cadastrado mas inexistente fica BANCO_NAO_EXISTE (não é criado). Banco sem ia_prompts fica SEM_ESTRUTURA_IA. Cada alvo registra REPLICACAO_PROMPTS_IA. [FEITO]
Onde: administracao_migracao_empresas.php (sp_migracao_prompts_solicitar), replicar-prompts-ia.php; origem: controle_orfaos_migracao.txt.

## 9. Órfãos da migração

**RN-MIG-45** — Registro com lei ou licença inexistente, ou com referência sem pai, não vai para o destino. Ele é guardado em migracao_registros_orfaos (JSON com origem, destino, solicitação, tabela e motivo) e a migração continua (política registrar_e_nao_enviar). [FEITO]
Onde: migrar-modulo-legal-generico.php, etapa AUDITAR_ORFAOS; origem: controle_orfaos_migracao.txt.

**RN-MIG-46** — Relacionamentos conhecidos sem FK declarada também são validados contra as chaves do legado antes da carga (ex.: infraestrutura/unidade, monitoramento, LPRO, documentos LAIS). Isso só vale quando a tabela-pai existe em pelo menos uma origem. [FEITO]
Onde: migrar-modulo-legal-generico.php; origem: controle_orfaos_migracao.txt.

**RN-MIG-47** — Um JSON idêntico não é gravado duas vezes. Registros com mais de 5.000 bytes vão compactados em base64. O relatório separa orfaos_ignorados_antes_da_carga de orfaos_detectados_apos_a_carga, sem contar duas vezes. [FEITO]
Onde: migracao_registros_orfaos, scripts/deduplicar-registros-orfaos.php; origem: controle_orfaos_migracao.txt.

**RN-MIG-48** — O popup de órfãos tem busca, filtros por tabela, motivo e período, paginação e exportação CSV. [FEITO]
Onde: administracao_migracao_log.php, MigracaoOrfaosExportacao.php; origem: controle_orfaos_migracao.txt.

**RN-MIG-49** — As decisões sobre órfãos ficam registradas (sp_migracao_orfao_decidir em migracao_orfaos_decisoes) e podem ser reaplicadas: justificar condicionante sem licença, remover ou atribuir responsável, vincular licença. [FEITO] (usado na Crown)
Onde: scripts/classificar-orfaos-legado.php, reaplicar-decisoes-orfaos.php; origem: código.

## 10. Módulos por empresa

**RN-MIG-50** — Os módulos comerciais são liberados por empresa (modulos_sistema e empresa_modulos no universal). Atlas: Módulo Legal/Gestão Ambiental e Homologação. Modelo e Modelo2: esses dois e mais Não Conformidades. [FEITO]
Onde: migração 265, seguranca_modulos_empresa.php (só ADMINISTRADOR); origem: modulos-por-empresa-20261014.md.

**RN-MIG-51** — Módulo desabilitado some do menu e é bloqueado também por URL direta. Administração, Segurança e Configurações dependem só das permissões do usuário. O ADMINISTRADOR vê todos os módulos. [FEITO]
Onde: PermissaoTransacao; origem: modulos-por-empresa-20261014.md.

## 11. Sincronismo legado → GreenPortal

### 11.1 Agendamento e fila

**RN-MIG-52** — O serviço \GreenSolutions\GreenPortal-SincronismoWorker roda a cada minuto, como SYSTEM, um ciclo por vez (bloqueio de serviço e limite de 12 h). Ele substituiu a tarefa fixa das 05:00, que é desativada na instalação. [FEITO]
Onde: scripts/sincronismo-worker.php, criar-agendamento-sincronismo.ps1; origem: código.

**RN-MIG-53** — Há um único horário diário para todas as empresas, ligado ou desligado na tela, no formato HH:MM. Na hora marcada, o serviço põe na fila um APLICAR de todas as empresas, que grava sem simulação prévia. Se o horário do dia já passou, a próxima execução é amanhã. [FEITO]
Onde: sp_sinc_worker_agendar, sp_sinc_config_salvar; origem: aplicar-migracao-305-sincronismo-tela.php.

**RN-MIG-54** — "Rodar agora" permite Simular ou Validar uma empresa ou todas. Não se aceita um pedido igual a outro que já está na fila. Só se cancela pedido que ainda esteja na fila. [FEITO]
Onde: sp_sinc_execucao_solicitar / _cancelar; origem: código.

**RN-MIG-55** — Aplicar manualmente só a partir de uma simulação concluída nas últimas 24 horas e ainda não aplicada, e uma única vez. A aplicação herda a empresa da simulação e grava as diferenças do momento em que roda. [FEITO]
Onde: sp_sinc_execucao_solicitar; origem: migração 305 ("Regra do usuário").

**RN-MIG-56** — Se o serviço passa mais de 5 minutos sem rodar, a tela mostra um alerta e os pedidos esperam na fila. [FEITO]
Onde: administracao_sincronismo.php; origem: código.

**RN-MIG-57** — Uma execução vira ERRO quando termina sem relatório, e CONCLUIDA_COM_ERRO quando algum banco falhou ou a validação deu divergente. Os relatórios ficam em storage\logs\sincronismo: execuções por 90 dias e relatórios do script por 30 dias. [FEITO]
Onde: sincronismo-worker.php; origem: código.

### 11.2 Quais empresas entram

**RN-MIG-58** — Entra no sincronismo a empresa ATIVA cujo banco novo existe e cuja última solicitação de migração está FINALIZADO_COM_SUCESSO (ou que não tem solicitação). Ela também precisa ter um banco de origem green_* com tabelas fo_. Atenção: uma solicitação nova que não termina com sucesso tira a empresa do sincronismo. [FEITO]
Onde: sincronizar-fo-legado.php::paresMigrados(), vw_sincronismo_empresas; origem: código.

### 11.3 O que é sincronizado

**RN-MIG-59** — São sincronizados os prefixos fo_ (Homologação) e nc_ (Não Conformidades), as tabelas do Módulo Legal de cada empresa (SincronismoLegado::TABELAS_LEGAL) e a legislação central (green_legislacao → glegislacao_dbp). [FEITO]
Onde: classes/SincronismoLegado.php, sincronizar-fo-legado.php; origem: código.

Decisão do usuário (18/10/2026): MODELO e Atlas entram no sincronismo de NC.

**RN-MIG-60** — Também são sincronizados as unidades de cada usuário (usuariosxunidades, migração 316) e os participantes externos das listas de NC (usuários legados com ativado = 'X' → nc_participantes, migração 333). Bancos sem essas migrações são ignorados. [FEITO]
Onde: sincronizarUsuariosUnidades, NaoConformidadeParticipantes::sincronizar; origem: código.

**RN-MIG-61** — A legislação central é sincronizada em toda execução, mesmo que alguma empresa falhe. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-62** — Tabelas descartadas não são migradas, criadas nem sincronizadas: nc_indicacao_ocorrencia_temp/_aux/_backup/_finalizados, nc_planodeacao_aux e nc_tiporeclamacao do legado. [FEITO]
Onde: SincronismoLegado::DESCONSIDERADAS; origem: código.

Decisão do usuário (18/10/2026): essas tabelas são "sobra do legado, sem importância".

**RN-MIG-63** — Colunas do legado terminadas em _en não são criadas nem contam como perda, porque o GreenPortal tem tratamento próprio de idiomas. [FEITO]
Onde: SincronismoLegado::COLUNAS_DESCONSIDERADAS; origem: decisão do usuário (18/10/2026).

**RN-MIG-64** — O legado nc_tipo_de_reclamacao grava em nc_tiporeclamacao do GreenPortal, com colunas mapeadas. As tabelas mapeadas rodam antes das de mesmo nome. [FEITO]
Onde: SincronismoLegado::TABELAS_MAPEADAS; origem: decisão do usuário (18/10/2026; 581 ocorrências da Crown usam esses códigos).

### 11.4 Inclusões, alterações e exclusões

**RN-MIG-65** — Para cada tabela: inclui no novo o que é novo no legado, altera o que mudou e exclui o que saiu do legado. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-66** — Antes de alterar, os valores anteriores e os novos vão para fo_sinc_alteracoes. Antes de excluir, uma cópia vai para fo_sinc_exclusoes, legal_sinc_exclusoes ou legislacao_sinc_exclusoes. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-67** — A ordem segue as FKs: na inclusão e na alteração, pais antes dos filhos; na exclusão, filhos antes dos pais. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-68** — Em tabelas sem chave primária comum, compara-se o conteúdo de todas as colunas em comum, contando repetições. Sobras são excluídas com backup e faltas são incluídas. [FEITO]
Onde: sincronizarSemChave / sincronizarSemChaveComum; origem: código.

**RN-MIG-96** — Tabela com chave própria do GreenPortal (autoincremento que não existe no legado) recebe só o que falta, comparando todas as colunas; cópias a mais são removidas com backup marcado "(duplicado)". Isso corrigiu a duplicação a cada execução encontrada em 18/10/2026. [FEITO]
Onde: sincronizar-fo-legado.php (c435); origem: correção de 18/10/2026.

### 11.5 Proteções

**RN-MIG-69** — Não se inclui nem se altera registro com FK para algo inexistente no novo (vai para ignorados_dependencia). Não se exclui registro ainda referenciado. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-70** — Inclusão que repetiria um índice único do GreenPortal é ignorada e contada em ignorados_duplicidade. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-71** — Cadastros provisórios da migração 217 nunca são excluídos. fo_fornecedorxcriterios_historico só recebe inclusões, e só de fornecedores reais. [FEITO]
Onde: SINC_PROVISORIOS, SINC_SOMENTE_INCLUSAO; origem: código.

**RN-MIG-72** — Só nos bancos gmodelo_dbp, gatlas_dbp e gmodelo2_dbp, as tabelas mestras mantidas no GreenPortal não são sincronizadas: critérios e tipos de funcionário e treinamento; tipos e critérios de fornecedor; documentos do fornecedor e seu histórico; fornecedor × usuário. Nas demais empresas o legado prevalece em todas as fo_. [FEITO]
Onde: SINC_MESTRE_NOVO / SINC_MESTRE_NOVO_BANCOS; origem: decisão do usuário (18/10/2026).

**RN-MIG-73** — A proteção de campos confirmados no GreenPortal (fo_sinc_protecoes, ex.: validade confirmada pela IA) está desligada e o legado sobrescreve. O registro em fo_sinc_protecoes é mantido para religar depois. [FEITO]
Onde: SINC_RESPEITAR_PROTECOES = false; origem: decisão do usuário (18/10/2026): até a virada o legado manda; o que for gravado no GreenPortal (aprovado da IA ou editado no grid) em condicionantes, plano de ação e homologação de fornecedores pode ser sobrescrito no próximo sincronismo.

**RN-MIG-74** — Faixa de códigos do GreenPortal: registros criados no novo começam em 50001 (nc_planodeacao em 500001). O sincronismo não os apaga, não os altera e não os conta como divergência. Se o legado chegar à faixa, a linha não é trazida e o relatório avisa (conflito_faixa_legado). [FEITO]
Onde: SincronismoLegado::FAIXAS_NOVO (migrações 322, 327, 332, 333); origem: decisão do usuário (21/10/2026).

**RN-MIG-75** — Durante o sincronismo, os gatilhos que geram documentos e itens (293/303) ficam parados, porque os registros vêm prontos do legado. [FEITO]
Onde: @gp_sinc_legado = 1; origem: código.

**RN-MIG-76** — Só roda uma execução por banco de cada vez (GET_LOCK), também no Módulo Legal e na legislação central. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

### 11.6 Simular, aplicar e validar

**RN-MIG-77** — Por padrão o script só simula: tudo roda numa transação desfeita no fim. A gravação exige --aplicar. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-78** — O --aplicar faz uma pré-validação. O banco com erro fica fora da execução (fora_da_execucao) e os outros seguem. [FEITO]
Onde: sincronizar-fo-legado.php; origem: código.

**RN-MIG-79** — Depois de gravar, cada banco é validado (legado × GreenPortal): contagem exata, linhas que faltam ou sobram pela chave, diferenças que uma nova simulação ainda faria e tabelas do legado sem correspondente. O resultado sai como "OK" ou "DIVERGENTE". O --validar faz só essa leitura. [FEITO]
Onde: validarReplica(); origem: código.

**RN-MIG-80** — O relatório JSON de cada execução fica em storage\logs. A tela mostra incluídos, alterados e excluídos (fo_ e nc_), o Módulo Legal (afetados/excluídos), a validação e a mensagem. A última execução fica em registros_migracao (SINCRONISMO_FO_LEGADO, SINCRONISMO_LEGAL_LEGADO, SINCRONISMO_LEGISLACAO_CENTRAL). [FEITO]
Onde: sincronismo-worker.php, administracao_sincronismo.php; origem: código.

**RN-MIG-99** — O sincronismo respeita o indicador de sincronismo ativo da empresa: empresa com empresa_migracao_config.sincronismo_ativo = 0 fica fora da lista da tela e não entra nas execuções, nem pelas origens green_* de registros_migracao. Vale quando sincronismo_config.respeitar_ativo = 1 (padrão). A simulação da migração 305 lista em "saem_do_sincronismo" as empresas que deixam de sincronizar; --manter=crown,atlas liga o indicador das que devem continuar. [FEITO em 25/10/2026]
Onde: vw_sincronismo_empresas, sincronizar-fo-legado.php (paresMigrados), scripts/aplicar-migracao-305-sincronismo-tela.php; origem: decisão do usuário.

Decisão do usuário (24/10/2026): Goodyear Americana — "Respeitar o indicador". Com sincronismo_ativo = 0 e o legado congelado, ela fica de fora.
Aplicação (25/10/2026): todas as empresas estavam com sincronismo_ativo = 0; ficaram com 1 as que já sincronizavam, menos a GD_AM: MODELO, CROWN, ATLAS, LUXOTTICA e GOODYEAR_ANCHIETA (--manter). Empresa nova só entra no sincronismo quando o indicador for ligado.

**RN-MIG-100** — O agendamento diário primeiro simula e só grava em seguida se as exclusões da simulação (fo_/nc_, Módulo Legal e legislação central) ficarem dentro do limite (sincronismo_config.limite_exclusoes, padrão 500). Acima do limite, a execução gravada não é criada: a simulação fica com a mensagem "Agendamento parado: N exclusões, acima do limite..." e os ADMINISTRADORES das empresas atingidas recebem um alerta com o link da tela do sincronismo. O "Rodar agora" continua exigindo simulação de até 24 h. [FEITO em 25/10/2026]
Onde: sp_sinc_worker_agendar, sp_sinc_agendado_continuar, sincronismo-worker.php (avisarAdministradores); origem: decisão do usuário.

Decisão do usuário (24/10/2026): "Simular e aplicar em seguida".

## 12. Bancos da homologação e cadeia de migrações

**RN-MIG-81** — Recebem as migrações da Homologação de Fornecedores (216 a 303) estes bancos: gmodelo_dbp ← green_modelo, gatlas_dbp ← green_atlas, gmodelo2_dbp ← green_modelo, gcrown_dbp ← green_crown, ggdamericana_dbp ← green_gd_americana e gganchieta_dbp ← green_goodyear_anchieta. Banco fora da lista dá erro. [FEITO]
Onde: classes/HomologacaoBancos.php; origem: decisão do usuário (17/10/2026).

**RN-MIG-82** — As conversões de dados já decididas valem só para os bancos originais (gmodelo, gatlas, gmodelo2). Nos bancos novos, as regras da 301 ficam desligadas e a 302 não converte nada. [FEITO]
Onde: HomologacaoBancos::ORIGINAIS, aplicar-homologacao-bancos.php; origem: código.

**RN-MIG-83** — O runner aplica a cadeia em ordem (216, 218–221, 245, 248, 249, 251, 260–263, 289, 292–303) e exige --banco. A 217 não faz parte da cadeia. [FEITO]
Onde: scripts/aplicar-homologacao-bancos.php; origem: código.

**RN-MIG-84** — Simular antes de aplicar: o --simular roda as migrações que têm simulação, e as de estrutura sem simulação aparecem como "não simulada". Na gravação, o runner para na primeira falha, que se retoma com --de=N. A saída fica em storage\logs\homologacao_bancos. [FEITO]
Onde: aplicar-homologacao-bancos.php; origem: código.

**RN-MIG-97** — Ficam fora da homologação o gluxottica_dbp e as empresas sem banco novo (ainda não migradas). Cada migração aceita --banco=; a que chama outra repassa os seus bancos, para não atingir bancos fora da lista (incidentes de 17/10/2026 com a 260 e a 292, corrigidos). [FEITO]
Onde: HomologacaoBancos.php, migrações 260/261/262/292; origem: decisão do usuário (17/10/2026).

**RN-MIG-98** — As regras automáticas dos documentos (301) ficam desligadas na MODELO e na Atlas até a virada (o legado faz esse papel); na MODELO2 continuam ligadas para testes. Nos bancos novos são instaladas desligadas e só se ligam com --ligar-regras, depois de ver a simulação do serviço. [FEITO]
Onde: aplicar-migracao-301; origem: decisão do usuário (18/10/2026).

**RN-MIG-85** — Para incluir uma empresa na homologação, acrescenta-se o banco em HomologacaoBancos::BANCOS e roda-se o runner. [FEITO]
Onde: HomologacaoBancos.php; origem: código.

**RN-MIG-86** — Uma alteração estrutural do Modelo vira migração versionada para os demais tenants. Nenhuma empresa existente é alterada fora do processo controlado. [FEITO]
Onde: diretrizes 2.2.1; origem: diretrizes-projeto.txt.

## 13. Clonagem de tenant

**RN-MIG-87** — Antes de clonar, faz-se backup lógico do original. O clone é cadastrado no universal como INATIVA e a equivalência é validada pelas contagens das views principais. [FEITO] (Modelo → Modelo2 em 22/09/2026)
Onde: scripts/cadastrar-tenant-modelo2.php, validar-clone-tenant.php; origem: clonagem_tenant_modelo2_20260922.md.

**RN-MIG-88** — O isolamento é testado com marcadores controlados em cada banco. Cada marcador só pode aparecer no próprio tenant. Ao fim, os marcadores são removidos e o clone volta para INATIVA, salvo autorização em contrário. [FEITO]
Onde: scripts/controle-isolamento-modelo2.php; origem: validacao_isolamento_modelo_modelo2_20260922.md.

## 14. Situação por empresa (24/10/2026)

**RN-MIG-89** — Goodyear Americana (GD_AM): migrada em ggdamericana_dbp, status MIGRACAO_CONCLUIDA, ATIVA, com sincronismo_ativo = 0 e "congelamento lógico" do legado. [FEITO] O upload no GED está bloqueado por permissão de gravação na pasta do Windows. [PENDENTE]
Onde: cadastro central; origem: homologacao_final_migracao_goodyear_274.txt.

**RN-MIG-90** — Goodyear Anchieta: o fluxo de fila e provisionamento foi testado (solicitação nº 3, FINALIZADO_COM_SUCESSO). Os executores de carga específicos ainda precisam ser homologados antes da migração produtiva. [PENDENTE]
Onde: gganchieta_dbp; origem: teste_goodyear_anchieta_provisionamento_276.txt.

### Para a virada (registrado em 17/10/2026)

- Alguns registros de teste receberam o mesmo código nos dois sistemas para registros diferentes (fornecedor 659, documentos 13188–13193, funcionário 1229 e outros); o mesmo vale para as pastas do GED. É preciso decidir faixa de códigos ou mapeamento. Tipos de fornecedor novos do GreenPortal já usam a faixa 100000.
- Na carga final, ignorar registros cujo pai não existe (ou rodar a 303 com --limpar depois).
- As regras da 301 dependem dos arquivos no GED.
- Decidir se a proteção de campos volta (SINC_RESPEITAR_PROTECOES) ou se o sincronismo é desligado.

## Pontos a confirmar

1. ~~sincronismo_ativo não é consultado.~~ Decidido em 24/10/2026: respeitar o indicador (RN-MIG-99).
2. **Bancos digitados na tela.** A tela da migração 275 aceita bancos de origem e destino digitados (valida só o formato e a existência). A regra da 266 diz que o usuário não escolhe livremente os bancos. Falta decidir se os campos devem vir só do cadastro central. [A CONFIRMAR]
3. **Executores genéricos.** O documento da 275 diz que pares sem executor específico terminam em ERRO. O worker atual usa os executores genéricos (migrar-seguranca-generico.php e migrar-modulo-legal-generico.php). Falta confirmar se eles estão homologados para migração produtiva. [A CONFIRMAR]
4. ~~Agendamento diário sem simulação.~~ Decidido em 24/10/2026: simula e grava em seguida, até o limite de exclusões (RN-MIG-100).
5. **Atlas: 650 vínculos NAO_MAPEADA.** Falta o catálogo de menus legado ou a relação formal CODMENU × menu atual, e depois a comparação do acesso efetivo antes da ativação. [PENDENTE]
6. **Proteções e faixas na virada.** Falta decidir quando religar SINC_RESPEITAR_PROTECOES e quais tabelas de SINC_MESTRE_NOVO valerão para Crown, GD Americana e Anchieta. [A CONFIRMAR]
7. **Critério da virada.** Não está documentado o que define a virada de cada empresa (desligar o sincronismo, congelar o legado). [A CONFIRMAR]
8. **Tarefa antiga.** A instalação do worker desativa a tarefa antiga do sincronizar-fo-legado.bat; o usuário pediu para poder reativá-la, e nesse caso o agendamento da tela deve ser desligado. Confirmar que só o GreenPortal-SincronismoWorker está ativo no servidor. [A CONFIRMAR]

## Fontes

- Anotações do projeto: migração e sincronismo (18/10/2026), aplicação da homologação nos bancos (17–18/10/2026), comparação legado × GreenPortal (17/10/2026), levantamento de SQL direto e análise da homologação do legado.
- docs/: analise_gestao_migracao_bases_legadas.txt, gestao_migracao_empresas_266.txt, gestao_solicitacoes_migracao_275.txt, controle_orfaos_migracao.txt, diretrizes-legado-migrado-atlas.md, migracao_usuarios_legado_233.md, migracao_empresa_universal_diretrizes.txt, migracao_legislacao_diretrizes.txt, migracao_li_modelo_dbp_diretrizes.txt, migracao_licencas_modelo_diretrizes.txt, migracao_modulos_fo_esg_diretrizes.md, clonagem_tenant_modelo2_20260922.md, validacao_isolamento_modelo_modelo2_20260922.md, inventario_migracao_goodyear_267.txt, homologacao_final_migracao_goodyear_274.txt, teste_goodyear_anchieta_fila_275.txt, teste_goodyear_anchieta_provisionamento_276.txt, modulos-por-empresa-20261014.md, sincronizacao_prompts_ia_modelo_atlas_20261004.md, diretrizes-projeto.txt (seções 2.2, 2.2.1, 2.6, 13, 14.1, 15 e "Validação de relacionamentos com o legado").
- Código (somente leitura): classes/SincronismoLegado.php, classes/EmpresaMigracaoService.php, classes/HomologacaoBancos.php, scripts/sincronizar-fo-legado.php, scripts/sincronismo-worker.php, scripts/criar-agendamento-sincronismo.ps1, scripts/aplicar-migracao-305-sincronismo-tela.php, scripts/migracao-worker.php, scripts/simular-migracao.php, scripts/aplicar-homologacao-bancos.php, scripts/migrar-seguranca-generico.php, scripts/migrar-modulo-legal-generico.php, scripts/classificar-orfaos-legado.php, scripts/reaplicar-decisoes-orfaos.php, principal/administracao_sincronismo.php, principal/administracao_migracao_empresas.php.
