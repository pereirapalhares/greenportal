# Migração e sincronismo legado → greenportal (18/10/2026)

## Decisões do usuário (18/10)
- Estrutura: vale a do greenportal, partindo do gmodelo_dbp; colunas/tabelas novas do greenportal continuam. Ajustes caso a caso.
- Dados: vêm do legado (o legado não é alterado; só o greenportal sofre alterações). Nada do legado pode se perder; pode ser adaptado desde que o usuário encontre a informação.
- **Até a virada o legado manda (17:40):** o que for gravado no greenportal (aprovado da IA ou editado à mão no grid) pode ser sobrescrito pelo legado no próximo sincronismo — vale para condicionantes, plano de ação e homologação de fornecedores. A proteção de campos (fo_sinc_protecoes) foi desligada no sincronismo (c446, constante SINC_RESPEITAR_PROTECOES = false em scripts/sincronizar-fo-legado.php; os registros de fo_sinc_protecoes ficam guardados; para voltar a proteger, trocar para true).
- GED físico é o mesmo (legado = greenportal).
- Regras automáticas (301) desligadas na MODELO e na Atlas até a virada (aplicado 18/10; MODELO2 continua ligada para testes do desenvolvimento).
- Crown: documentos, histórico e vínculos dos tipos da homologação vêm do legado e entram no sincronismo.
- Ordem dos módulos que faltam na migração: 1) Não conformidades; 2) resto do Módulo Legal (requisitoslegais, legislacaoxunidade, condicionantes); 3) histórico do GED, Eventos, Monitoramento. Para cada módulo, levantar antes onde a informação fica no greenportal.
- Tabelas de sobra do legado podem ser desconsideradas quando o usuário decidir, e isso fica registrado na rotina (classes/SincronismoLegado.php::DESCONSIDERADAS) para não tentar migrar/sincronizar de novo. Desconsideradas em 18/10: nc_indicacao_ocorrencia_temp (349 só nela), nc_planodeacao_aux (50), nc_indicacao_ocorrencia_backup (15), nc_indicacao_ocorrencia_finalizados (13), nc_indicacao_ocorrencia_aux (vazia), nc_tiporeclamacao do legado (ver NC).
- Colunas *_en do legado desconsideradas (o greenportal já tem tratamento de idioma).
- MODELO e Atlas entram no sincronismo de NC.
- O sincronismo precisa validar se o que está no legado foi replicado no greenportal (desconsiderando as descartadas).
- Menu Administração › Migração com Migração geral, Sincronismo e Menus de migração; sincronismo com horário único para todas as empresas; "Rodar agora › Aplicar" só depois de simular.

## Processos
- Menu (c433 + migração 305): Administração › Migração › Migração geral (ADMINISTRACAO_EMPRESAS_MIGRACAO) · Sincronismo (ADMINISTRACAO_SINCRONISMO) · Menus de migração (ADMINISTRACAO_MIGRACAO_MENUS). EmpresaMigracaoService::garantirMenu (usado pela 266) grava a mesma estrutura.
- Migração geral: migracao-worker.bat (Agendador, a cada minuto) → migracao-worker.php: estrutura copiada do gmodelo_dbp (só se o banco não existe), segurança (migrar-seguranca-generico.php), prompts de IA e ~30 tabelas do Módulo Legal (migrar-modulo-legal-generico.php). NÃO copia homologação nem os outros módulos — essas vêm pelo sincronismo. Não tem escolha de módulo. Atenção: uma solicitação nova não finalizada com sucesso tira a empresa do sincronismo.
- Sincronismo — tela principal/administracao_sincronismo.php: agendamento (ligado + horário HH:MM, único para todas); Rodar agora (Simular / Validar, por empresa ou todas); lista de execuções com Aplicar (só a partir de simulação concluída há até 24 h, uma vez), Cancelar (na fila) e Relatório (administracao_sincronismo_relatorio.php?id=N, popup). A tela só grava pedidos: procedures sp_sinc_* no guniversal_dbp (tabelas sincronismo_config, sincronismo_execucoes; views vw_sincronismo_empresas, vw_sincronismo_execucoes).
- Sincronismo — serviço \GreenSolutions\GreenPortal-SincronismoWorker (scripts/sincronismo-worker.php, a cada minuto, SYSTEM, IgnoreNew, limite 12 h; instalado por scripts/criar-agendamento-sincronismo.ps1, que desativa a tarefa antiga do sincronizar-fo-legado.bat — o usuário pediu para poder reativá-la; se reativar, desligar o agendamento da tela). GET_LOCK = uma execução por vez; no horário coloca "AGENDADO / APLICAR / todas" na fila; executa sincronizar-fo-legado.php (--aplicar | --validar | simulação, --empresa=codigo); relatório em storage\logs\sincronismo\execucao_<n>.json (90 dias), resumo na tela; atualiza empresa_migracao_config.ultimo_sincronismo_em. Aparece em Administração › Serviços (SINCRONISMO_WORKER).
- sincronizar-fo-legado.php: fo_* e nc_*, tabelas mapeadas, Módulo Legal das empresas e legislação central (sempre, mesmo com --empresa); inclui, altera e exclui (backup em *_sinc_exclusoes / fo_sinc_alteracoes). Depois de cada banco aplicado roda a validação. Proteções que continuam: provisórios da migração 217, dependências inexistentes, histórico só inclusão, SINC_MESTRE_NOVO (MODELO/Atlas/MODELO2), gatilhos parados, duplicidade de índice único. Proteção de campos confirmados no greenportal: desligada (c446).
- classes/SincronismoLegado.php: PREFIXOS (fo_, nc_), TABELAS_LEGAL (inclui li_condicionantes e li_planodeacao desde c443), DESCONSIDERADAS, TABELAS_MAPEADAS, COLUNAS_DESCONSIDERADAS (/_en$/), PREFIXOS_MANTIDOS_NO_NOVO (vazio).
- Validação (c431): `sincronizar-fo-legado.php --validar` (também roda no --aplicar). Contagem exata legado × greenportal, faltam/sobram por chave, tabelas do legado sem correspondente, diferenças que um novo sincronismo ainda faria. Status OK ou DIVERGENTE.

## Problemas encontrados e corrigidos (c423–c446)
- Sincronismo falhava todo dia desde 15/10 ("There is no active transaction"): CREATE TABLE do backup dentro da transação. Corrigido: backup antes da transação; empresa com erro fica de fora; central aplicada sempre.
- Tabelas "mantidas no novo" (SINC_MESTRE_NOVO) só ficam fora do sincronismo em MODELO, Atlas e MODELO2.
- Gatilhos de geração (293, 303) e o de condicionantes (307) param durante o sincronismo (@gp_sinc_legado = 1).
- Inclusão que repetiria índice único do greenportal é ignorada (ignorados_duplicidade; Crown: 358 documentos repetidos no legado). A validação da Crown fica DIVERGENTE por isso.
- c434: registro em registros_migracao do glegislacao_dbp (formato diferente) derrubava o relatório no fim da aplicação — agora só avisa.
- c435: tabelas com chave própria do greenportal (autoincremento que não existe no legado) eram duplicadas a cada execução (central: legislacaoxpalavraschave 37.423→74.846, legislacaopaixlegislacaofilha 484→968; Crown mm_medicaoxlegislacao, li_licencasxpalavraschave). Agora inclui só o que falta (comparando todas as colunas) e remove as cópias a mais (backup "(duplicado)"). Limpeza aplicada na Crown/central em 18/10 (execução #9).
- c436: a simulação mostrava como "protegidos" os registros que seriam excluídos no Módulo Legal (Atlas: 8 licenças e 1 monitoramento só do greenportal foram excluídos na execução #10, com backup em legal_sinc_exclusoes).
- c444: relatório do sincronismo mostrava "Array" e avisos do PHP na coluna Observação da validação (campo motivo é lista); agora mostra o texto (ex.: "358 repetida(s) no legado", "244 dependem de registro que não existe no greenportal").
- c446: proteção de campos (fo_sinc_protecoes) desligada por decisão do usuário (legado manda até a virada).
- Menu Administração › Migração (c433 + 305).
- Simulação infla a estimativa de linhas do InnoDB; conferir pelo relatório da gravação.

## Execuções de 18/10 (tela Sincronismo)
- #9 Crown (aplicação da simulação #7) e #10 Atlas (da #8): concluídas. Crown: +82/~72/−2 em NC/homologação (mudanças do legado entre 13:26 e 13:41 a confirmar), 18 duplicados do Módulo Legal removidos, central −37.908 duplicados. Atlas: +1.286, ~975 (situações dos critérios, 74 textos de NC, 42 fornecedores).
- 17:01–17:08 — condicionantes (simulações #11–#13, aplicações #14–#16, sem exclusões):
  - Crown (#14): li_condicionantes +217, li_planodeacao +31 (Módulo Legal 248).
  - Goodyear Americana (#15): +95 em NC (tipos de ação, campos, listas, categorias), Módulo Legal 417 (li_condicionantes 266, li_licencas 76 alteradas, legislacaoxempresa 42, legislacaoxsituacao 27, infraestrutura 3, laia 2, checklist 1).
  - Goodyear Anchieta (#16): +24 em NC, Módulo Legal 1.702 (legislacaoxsituacao 1.652 e li_licencas 22 = 2× as linhas do legado: contagem de alteração do MySQL, cada linha alterada conta 2; li_condicionantes 5, legislacaoxempresa 23). Nova simulação #17 logo depois: 0 mudanças (estável).
  - Validação depois de gravar: li_condicionantes/li_planodeacao sem divergência nas três. DIVERGENTE só pelo já conhecido: duplicados do legado (Crown 358 documentos, 6 vínculos de tipo), NC com dependência ausente (Crown 244 + 47, GD 3), histórico da Crown (42), legislacaoxunidade sem tabela, tabelas sem chave comum comparadas por contagem.

## Não conformidades (Crown, 18/10)
- 25 tabelas nc_* existem nos dois → entram no sincronismo em todas as empresas.
- nc_tipo_de_reclamacao (183 na Crown) → nc_tiporeclamacao do greenportal (TABELAS_MAPEADAS, c432); nc_tiporeclamacao do legado em DESCONSIDERADAS.
- nc_workflow_historico só existe no greenportal.

## Licenças × condicionantes (18/10, c443 + migração 307)
- Legado: aba "Condicionantes" da licença (li_condicionantes com CODLICENCA; editável: descrição, frequência em lista Diário…Quadrienal, Atende? Sim/Não, responsável, observação) e aba "Plano de Ação" (li_planodeacao; status 0/25/50/75/100%; arquivos no GED pasta li_planodeacao). Excluir condicionante apaga os planos. li_situacao/li_licencasxcondicionantes e o modelo antigo (licencasxcondicionantes, condicionantes, avaliacao_condicionante) estão vazios ou não existem.
- Diagnóstico (diagnostico-licencas-condicionantes.php): Crown 217 condicionantes + 31 planos, GD Americana 266 e Anchieta 5 condicionantes só no legado — trazidos pelo sincronismo de 18/10 (#14–#16). Atlas: 175 condicionantes com licença inexistente (igual no legado); 11 com responsável que não existe no greenportal (códigos 17, 19, 24, 27, 34…). MODELO: 2 planos só no greenportal (saem, com backup, no próximo sincronismo da MODELO) e valores diferentes.
- Correções: li_condicionantes e li_planodeacao em TABELAS_LEGAL; tela de condicionantes editável como no legado (listas; valores gravados fora da lista continuam aparecendo — ManutencaoLegalCadastroGrid junta 'options' fixas + 'options_sources'); status do plano em lista de %; gatilho trg_li_condicionantes_ad_planos (307, aplicada em 7 bancos).
- c444: coluna Responsável das condicionantes e do plano de ação mostra o nome (ManutencaoLegalCadastroGrid resolve o rótulo dos combos lazy_foreign quando a view não traz a descrição; filtro da coluna com nomes; código sem usuário aparece como "27 (não cadastrado)"; a tela de condicionantes não tem mais o Responsável trocado pelo padrão). Vale para todas as telas do componente.
- c445 + migração 308 (aplicada em 7 bancos às 17:36): Plano de ação — coluna "Evidências" logo depois de Data final. Na Gestão de Arquivos (tipo planodeacao) a IA lê a evidência (OCR worker + IA worker; api/planodeacao_ia.php; prompt LI_PLANOACAO_DOCUMENTO, editável em Setup › Prompts de IA) ao abrir a tela (evidência mais recente sem análise ou aguardando aprovação) e a cada arquivo enviado; sugere Ação, Responsável, Data inicial/final, Recursos e Status (%) na aba "Revisão dos dados"; o operador marca/ajusta e confirma ou descarta; grava só os campos marcados (sp_crud_update) e atualiza a linha do grid aberto. Histórico em ged_analises_ia (aba Análises de IA). Permissão: Editar em LICENCAS. Testar com um plano da Crown (31).
- Edições no greenportal (grid ou IA) podem ser sobrescritas pelo legado no sincronismo até a virada (decisão do usuário, 17:40).

## Requisitos legais por licença (c441 + migração 306, aplicada)
- licenca_requisitos_documentos.php = tela de Requisitos legais filtrada pela licença (vw_modulolegal_requisitos_licenca_grid; filtro fixo CODLICENCA). Crown: 168 vínculos, 132 casam com o grid.

## Segurança (18/10)
- seguranca_permissoes.php: caixa marcar/desmarcar por coluna (c437); perfil escolhido no seletor ao lado da busca, sem popup (c438).
- MenuService (c440): grupo sem itens visíveis não aparece mais. Perfis associados do legado (vários grupos) só foram migrados para a Atlas (atlas_usuarios_perfis_legado); nas outras empresas vale só o perfil principal.

## Cobertura da Crown (diagnostico-cobertura-legado.php, 18/10)
- SEM_PROCESSO: NC (agora no sincronismo); taximetrosumarizado 212 mil; historico_arquivos 39 mil; pendencias_* 43 mil; requisitoslegais 3.150; li_condicionantes (agora no sincronismo); ev/mm poucos.
- legislacaoxunidade (3.753) não existe no gcrown_dbp.

## Próximos passos
- Na virada: decidir se volta a proteção de campos (SINC_RESPEITAR_PROTECOES) ou se o sincronismo é desligado.
- Resto do Módulo Legal (requisitoslegais, legislacaoxunidade), depois histórico do GED, Eventos, Monitoramento.
- Servidor: em 18/10 uma gravação foi recusada por disco cheio no E: (transitório) — acompanhar o espaço livre.

## Ferramentas
- scripts/diagnostico-cobertura-legado.php --origem=green_x --destino=gx_dbp [--detalhe] [--prefixo=nc_]
- scripts/diagnostico-tabelas-sobra-legado.php --origem=green_x [--prefixo=nc_]
- scripts/diagnostico-nc-tipo-reclamacao.php --origem=green_x
- scripts/diagnostico-menu-usuario.php --banco=gx_dbp --usuario=login [--menu=CODIGO]
- scripts/diagnostico-licencas-condicionantes.php [--banco=gx_dbp]
- scripts/sincronizar-fo-legado.php [--aplicar | --validar] [--empresa=codigo]
- scripts/testar-telas-migracao.php
