# Não Conformidades — análise e reconstrução (Crown, 20–22/10/2026)

Fontes: código do legado (E:\wamp\www\formulario, nc_planejamento, nc_cliente, nc_assistenciatecnica, cadastros, configuracoes — só leitura), telas de NC do greenportal, scripts/diagnostico-nc-legado-greenportal.php (c489), diagnostico-nc-estrutura.php (c490), diagnostico-nc-codigos.php (c493), diagnostico-nc-performance.php (c496), diagnostico-nc-fornecedor.php (c500), diagnostico-nc-planejamento.php (c503), diagnostico-nc-cliente.php (c507/c508) e diagnostico-nc-assistencia.php (c514).

## Plano aprovado pelo usuário (20/10) — concluído em 22/10
1. Corrigir a base: procedures/views, permissões, telas com erro. → **c491–c492 / migração 321** — concluída e testada na Crown (21/10).
2. Completar o fluxo da Empresa: **avisos pelos Alertas do portal** e anexos. → **c495 / "323"** — aplicada e testada na Crown com usuários reais (21/10).
3. Outros tipos pela ordem de uso: **Fornecedor** (c501–c502 / "325", testada 21/10) → **Planejamento** (c504–c505 / "326", testada 21/10) → **Cliente** (c510–c513 / "327", testada 22/10) → **Assistência Técnica** (c515–c516 / "328", testada 22/10). Acidente/Incidente: 0. Todos os tipos gravam no greenportal.
- Regra do usuário (21/10): cada tipo tem seu item de menu abaixo de Não Conformidades com PHP próprio (como "Nova não conformidade da Empresa"), criado na etapa em que o tipo passa a gravar.
- Regra do usuário (21/10): **todo menu existe no catálogo universal; não há menu específico de empresa** (ver "Menus", c506).

## Dados (Crown)
- 16.730 ocorrências (último código do legado 19.561). Por tipo: Empresa 6.809 (887 abertas), Fornecedor 3.759 (750), Planejamento 3.639 (746), Cliente 2.401 (497), Assistência Técnica 122 (20).
- Maiores códigos: ocorrência 19.561, causa 15.546, ação 45.842 (MODELO 5.291 / 5.102 / 11.080; Atlas 7.075 / 174 / 261; GD e Anchieta vazias).
- No legado qualquer usuário ativo é gestor/responsável.
- Campos por tipo: nc_tipo_de_naoconformidadexcampos × green_universal.md_tabelasxcampos.
- **Ações do legado ligadas só pela causa:** em Empresa (7.595), Cliente (4.493), Fornecedor (4.374) a ação tem nc_planodeacao.CODOCORRENCIA NULL e aponta só para a causa; no Planejamento e na Assistência Técnica a ação aponta para a ocorrência.

## Legado (regras usadas)
- Empresa/Fornecedor/Cliente: Em criação/Recusado pelo Gestor (emitente) → Com o Gestor. Gestor: enviar ao responsável (exige responsável, ação imediata, abrangência) → Em Analise da Causa Raiz; recusar → Recusado pelo Gestor; improcedente → Finalizado. Responsável: causas + plano; enviar exige ≥1 causa, ≥1 plano por causa, getStatusPlanoAcaoNC ≠ 'Não' → Avaliação da Eficacia. Gestor: EFICAZ/ENCERRADO → Finalizado; NÃO EFICAZ/recusa → Recusado pela Avaliacao da Eficacia. Crown: data da análise da eficácia + responsável pela pendência obrigatórios.
- Incluir causa ou gravar ação passa a etapa para "Plano de Ação".
- Planejamento (nc_planejamento/nc_formulario.php, nc_s.php, cadastros/pendencias_nc_planodeacao_update.php): "Enviar para o Gestor" → Plano de Ação; gestor preenche ações sem causa; com todas a 100% → Avaliação da Eficácia (pass_pela_nanalise_eficacia = S) ou Finalizado sem verificação (N).
- Cliente (nc_cliente/nc_s.php): mesmo fluxo da Empresa; campos próprios (cliente por nome, unidade do cliente, produto por código, tipo/nº de documento, tipo de reclamação, tampa, lata, procedência, segurança de alimentos, quantidades). Na Crown o tipo Cliente não tem sumário (IDENTIFICACAO) configurado.
- Assistência Técnica (nc_assistenciatecnica/nc_formulario.php, nc_s.php, checa_nc_planodeacao.php): abas Ocorrência, Plano de Ação, Avaliação da Eficácia; o emitente monta as ações Em criação; "Enviar plano de ação" (≥1 ação com descrição e responsável) → Plano de Ação e e-mail a cada responsável; eficácia do gestor; a recusa deixava a NC parada (sem tratamento).
- E-mails em toda passagem; para usuário de fornecedor o assunto começa com "Sr. Fornecedor". Anexos no GED: 'nc_indicacao_ocorrencia', 'nc_analise_ocorrencia' (CODOCORRENCIA), 'nc_planodeacao' (CODPLANOACAO). Finalizada: anexos só consulta.
- Fornecedor ($_SESSION['FORNECEDOR'] = 'S'): ocorrência, parecer e eficácia só consulta; age na análise quando é o responsável.
- **Não abrir:** nc_planejamento/nc_enviar_responsaveis.php e nc_assistenciatecnica/nc_enviar_responsaveis.php (UPDATE de etapa no GET); configuracoes/nc_ocorrencias_por_fornecedor.php (UPDATE de etapa ao abrir).

## Migração 321 (base) — aplicada nos 6 bancos
- Views vw_nc_*; procedures sp_nc_* (ocorrencia_salvar, gestor_salvar, causa_salvar/excluir, plano_salvar/excluir, analise_salvar, eficacia_salvar, historico_registrar, alertar, alertar_usuario, etapa_plano, planejamento_avancar); função fn_nc_plano_ocorrencia (c509).
- Tela única principal/naoconformidades_ocorrencia.php. Gravam os tipos 3 (Empresa), 1 (Fornecedor), 5 (Planejamento), 2 (Cliente, c510) e 6 (Assistência Técnica, c515).
- O script aplicar-migracao-321-nao-conformidades-base.php é rodado de novo a cada etapa (322 faixa, 323 alertas/anexos, 325 fornecedor, 326 planejamento, 327 ações pela causa + Cliente, 328 Assistência Técnica) e cria os menus de cada tipo (pelo catálogo universal desde c506).
- c511: NaoConformidadeWorkflow::chamar roda cada procedure em transação (tudo ou nada): validação que falha depois de gravar não deixa NC criada pela metade.
- Limpeza de teste: scripts/limpar-nc-teste.php (só apaga se não existir no legado e o sumário — ou a descrição, quando não há sumário (c513) — começar com "TESTE GREENPORTAL"; inclui alertas e histórico das ações).

## Faixa de códigos do greenportal (c494 / "322")
- Ocorrências e causas a partir de **50001**, ações a partir de **500001** (fn_nc_faixa; SincronismoLegado::FAIXAS_NOVO). O sincronismo não mexe na faixa e avisa conflito. Pastas do GED da faixa não colidem com o legado.
- c510: os 7 cadastros do Cliente também têm faixa 50001 (inclusive a tabela mapeada nc_tiporeclamacao, cujo sincronismo passou a respeitar a faixa).

## Etapa 2 — alertas e anexos (c495 / "323")
- sp_nc_alertar (chamada por sp_nc_historico_registrar) grava em `alertas` com link para a aba certa; destinatários como os e-mails do legado; quem fez a passagem não recebe; só ativos; erro no alerta não impede a passagem. Lista de distribuição passa a ver a ocorrência.
- Anexos (GED) ncocorrencia, ncanalise, ncplano nas pastas do legado; Finalizada → só consulta (conferido na API).
- Testado na Crown (Empresa). Envio de arquivo real no GED não testado (sem autorização).

## Etapa 3a — Fornecedor (c500, c501, c502; "325")
- Campos do tipo (fornecedor, quantidade, lote, retenção, devolução), classificação do fornecedor, listas da categoria Fornecedores (Crown), fornecedor obrigatório no envio, PDF com nome do fornecedor.
- Usuário de fornecedor: só consulta, exceto a análise quando é o responsável; não cria NC (exceto Assistência Técnica); avisos "Sr. Fornecedor, …".
- Menu Nova ocorrência do fornecedor (NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR, naoconformidades_novaocorrenciafornecedor.php).
- **Segurança (c502):** Minhas ocorrências e Lista geral sem "visualizar total" ficam no escopo do usuário (CODUSUARIO_ENVOLVIDO, force_user_scope) também na contagem, exportação e opções de filtro.
- Testado na Crown com NOVELIS.RNCF.CBV.

## Etapa 3b — Planejamento (c503 diagnóstico, c504 fluxo, c505 rótulos; "326")
- Diagnóstico Crown: 3.639 NCs (746 abertas: 620 Plano de Ação, 89 Em criação, 37 Avaliação); nenhuma usa causas; responsável da NC quase não usado; 476 em Plano de Ação sem ações. pass_pela_nanalise_eficacia: S 2.115, N 1.497.
- Decisões do usuário (21/10): gestor monta as ações e o responsável de cada ação atualiza status/observação; NÃO EFICAZ volta ao Plano de Ação; avanço automático com todas as ações a 100%.
- Fluxo: Em criação ("Passar pela análise da eficácia?" obrigatório) → Plano de Ação; gestor/admin cria/edita/exclui ações sem causa; responsável da ação altera só status/observação; todas a 100% → Avaliação da Eficácia (S) ou Finalizado ENCERRADO "Sem verificação da eficácia" (N); NÃO EFICAZ/recusa → Plano de Ação.
- Menu Nova ocorrência de planejamento (NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO, naoconformidades_novaocorrenciaplanejamento.php → ?tipo=5).
- Testado na Crown (Nathalia → Adriano → NOVELIS → Adriano).

## Ações do legado ligadas só pela causa (c508 diagnóstico, c509 correção; "327")
- Bug: a tela procurava as ações pelo CODOCORRENCIA; nas NCs do legado as ações não apareciam nem podiam ser editadas, e o responsável da ação não via a NC em Minhas ocorrências.
- Correção: vw_nc_planodeacao e vw_nc_envolvidos acham a ocorrência por COALESCE(ação, causa); fn_nc_plano_ocorrencia nas procedures de ação; excluir causa apaga as ações pela causa; vminhasocorrenciasnc sem a condição p.CODOCORRENCIA = c.CODOCORRENCIA (cópia anterior em storage/logs/migracao_327_vminhas_<banco>.sql). Dados não mudam. Conferido na Crown (NC 19547 mostra as 2 causas e 2 ações).

## Menus (c506; migração 326 de menus)
- Bug relatado (Segurança › Permissões não reflete): o menu lateral vem do catálogo universal (guniversal_dbp.menus, MenuService) e casa as permissões pelo código; os menus de NC Fornecedor/Planejamento tinham sido criados só no banco da empresa.
- classes/MenuCatalogoService.php: grava no universal e replica pelo código em todas as empresas (mesmo codmenu quando livre); Setup › Menu usa esta classe; exclusão bloqueada se houver itens dentro ou perfil com acesso.
- scripts/aplicar-migracao-326-menus-nc-universal.php: leva ao universal o que só existe na empresa e às empresas o que falta do universal. Aplicada em 21/10.
- scripts/diagnostico-permissoes-perfil.php: diagnóstico das permissões por perfil e simulação da sessão de um usuário.

## Etapa 3c — Cliente (c507/c508 diagnóstico, c510–c513; "327")
- Diagnóstico Crown: 2.401 NCs (497 abertas); só usuários da empresa; ações pela causa (4.493). Campos desde 2024: cliente 93%, documento 94%/92%, reclamação 93%, procedência 91%, segurança de alimentos 88%, unidade do cliente 79%, lata 78%, produto 73%, qtd envolvida 69%, tampa 39%, qtd devolvida 38%; parada de linha/lote/fornecedor 0%. Cliente gravado como texto (619 fora do cadastro por espaço/quebra de linha).
- Decisões do usuário (21/10): cliente da lista do cadastro (valor antigo continua aparecendo); obrigatórios no envio: cliente, unidade do cliente, produto, tipo e nº do documento, tipo de reclamação, procedência e segurança de alimentos; telas de cadastro nesta etapa.
- Sumário/descrição: exigidos no envio só quando o tipo usa o campo.
- Menu Nova ocorrência de cliente (NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE → ?tipo=2). PDF do Cliente com as descrições.
- Não Conformidades › Cadastros (classes/NaoConformidadeCadastros.php): Clientes, Unidades do cliente, Produtos, Tipos de documento, Tipos de reclamação, Tipos de tampa, Tamanhos de lata. Registros do legado (código < 50001) só consulta (grid 'legacy_below'); novos a partir de 50001; exclusão de registro do greenportal em uso bloqueada (gatilho). Permissão inicial: ADMINISTRADOR e ADM_DA_EMPRESA. Registros novos não vão para o legado.
- Testado na Crown em 22/10 (Nathalia → Adriano gestor/responsável → EFICAZ; cadastros: legado bloqueado, novo 50001 incluído e excluído). NC de teste apagada.

## Etapa 3d — Assistência Técnica (c514 diagnóstico, c515–c516; "328")
- Diagnóstico Crown: 122 NCs (1 em 2026), 20 abertas todas em Plano de Ação (18 sem ação, 2 com tudo a 100% paradas); finalizadas quase todas sem eficácia (pass N 101); emitente = gestor em 80; 10 das 20 abertas criadas por perfil FORNECEDORES.
- Decisões do usuário (22/10): fornecedor externo cria NC de Assistência Técnica (só neste tipo); no Plano de Ação emitente e gestor incluem/alteram ações; avanço automático com todas a 100% (como o Planejamento).
- Fluxo: emitente cria e monta as ações Em criação → "Salvar e enviar o plano de ação" (≥1 ação, todas com descrição e responsável, "Passar pela análise da eficácia?") → Plano de Ação (alerta a cada responsável; ações já a 100% avançam na hora); responsável da ação altera só status/observação; todas a 100% → Avaliação da Eficácia (S) ou Finalizado (N); eficácia do gestor (pode ser fornecedor); NÃO EFICAZ/recusa → Plano de Ação (escolha: no legado ficava parada).
- Menu Nova ocorrência de assistência técnica (NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA → ?tipo=6), permissões da Nova ocorrência da empresa + perfis de fornecedor que veem Minhas ocorrências (Crown: 4 perfis).
- Testado na Crown em 22/10 (NC 50002): Nathalia (envio sem ação recusado sem gravar; 2 ações; envio) → NOVELIS.RNCF.CBV (alerta "Sr. Fornecedor…", só status/observação da própria ação; alterar ação de outro negado) → Adriano (ação a 100% → Avaliação sozinha → EFICAZ → Finalizado).

## Desempenho (c496; migração 324: c497 v1, c498 v2)
- ENCERRADO calculado por agregado no FROM (0 divergências com getStatusPlanoAcaoNC); Minhas sem DISTINCT; índices repetidos retirados; nc_planodeacao(CODCAUSA, STATUS).
- Lição: subconsulta na lista de colunas de uma view impede o MySQL de incorporá-la à consulta.
- Crown: Lista 1ª página 2,0 → 0,2 s; sem filtro 12,9 → 1,0 s; filtro "Plano encerrado?" 19,4 → 0,4 s; Minhas filtro Gestor 24,5 → 0,9 s.
- Servidor: innodb_buffer_pool_size 256 MB (pequeno); avaliar com quem administra.

## Ajustes de 22/10 (c517–c521; migrações 321 de novo, 329 e 330)
- **Alertas, pendências e agenda abrem como aba do main.php** (não em nova janela do navegador): grid da Home ('open_in_tab' → greenPortalOpenTab, c517), sino do cabeçalho (c518; arquivos de exportação continuam como download), Minhas pendências e Agenda (c519). Título da aba: "NC 50002/2026" (c520). Testado na Crown.
- **Responsável da ação (c519):** o usuário de fornecedor só escolhe usuários do próprio fornecedor + usuários da empresa que já participam da NC (+ ele mesmo e os responsáveis atuais). View vw_nc_usuarios_fornecedor; NaoConformidadeWorkflow::responsaveisAcaoExterno; o POST recusa responsável fora da lista.
- **Permissões (c519, "329"):** USUARIOS — Minhas ocorrências e as 5 "Nova ocorrência"; VISITANTE — só consulta (Minhas e Lista geral, visualizar). Só acrescenta (GREATEST). Aplicado em Crown, Atlas e Anchieta (Modelo/Modelo2/Americana não têm esses perfis).
- **data_de_reposta_do_fornecedor (c519):** sp_nc_resposta_fornecedor grava quando usuário de fornecedor envia a análise (sempre) ou atualiza ação (se ainda vazia). Coluna criada se faltar.
- **GED de NC testado na Crown (22/10):** envio, lista e exclusão na NC 50001; arquivo em E:\projetoGreen\GED\Green_crown\nc_indicacao_ocorrencia\50001.
- **Dono do arquivo (c521, migração 330):** quem enviou o arquivo tem acesso total a ele — ver, baixar/imprimir e excluir — mesmo sem essas permissões no perfil; demais usuários seguem o perfil; registro encerrado (NC finalizada) continua só consulta (o dono ainda baixa). Dono = content_versions.codusuario do envio (procedure sp_ged_arquivos_do_usuario; GedPermissaoTipo::arquivosDoUsuario), conferido na API (gestao_arquivos.php) e no visualizador; a lista traz por arquivo `dono`, `download`, `pode_excluir`. Chave do registro do envio passou a incluir o nome físico (mesmo conteúdo enviado de novo ganha o seu dono). Arquivos enviados pelo legado não têm dono. Testado na Crown com adriano.justino (perfil sem "baixar": baixou e viu Baixar/Imprimir no visualizador do próprio arquivo).
- NC de teste 50002 apagada (22/10).

## Pendências
- NC 50001 da Crown (Planejamento "teste ação 123…", criada pelo Adriano em 22/10): confirmar com o usuário se é teste.
- Testar na Crown: fornecedor escolhendo responsável da ação; usuário USUARIOS criando NC; VISITANTE só consulta; data de resposta do fornecedor gravada.
