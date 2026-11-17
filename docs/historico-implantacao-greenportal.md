# Histórico da implantação do GreenPortal (até 26/10/2026)

Este documento reúne, num lugar só, o que foi feito no GreenPortal até 26/10/2026: as fases, o catálogo das migrações, os serviços do Agendador do Windows, o trabalho mais recente (commits c563 a c573) e o que ainda está pendente.

Nunca registrar aqui senhas, chaves ou valores do `.env`.

## 1. Como a documentação está organizada

| Onde | O que tem |
|---|---|
| `docs/regras-negocio-README.md` | Índice das regras de negócio, princípios comuns, decisões em aberto e pendências de aplicação |
| `docs/regras-negocio-01-plataforma.md` … `06-migracao-legado.md` | Regras por módulo: RN-PLT (136), RN-LEG, RN-TAR, RN-HOM (124), RN-NC, RN-MIG (100). Cada regra tem situação ([FEITO], [PENDENTE], [A CONFIRMAR]), onde fica e a origem |
| `docs/projeto/*.md` | Cópia dos 28 documentos de análise e decisão do projeto (as mesmas notas do projeto "greenportal" no Claude), com as decisões do usuário e os testes de cada entrega. Índice em `docs/projeto/README.md` |
| `docs/historico-implantacao-greenportal.md` | Este documento |
| `database/NNN_*.sql` e `database/NNN_*.php` | As migrações (001 a 351) e os textos de ajuda |
| `scripts/aplicar-migracao-NNN-*.php` | Os runners das migrações 183 em diante (todos com `--simular`) |
| `scripts/criar-agendamento-*.ps1` | Instaladores das tarefas do Agendador do Windows |

**Como manter:**
- nova regra: o próximo número livre do módulo, sem renumerar; quando a migração for aplicada, trocar [PENDENTE] por [FEITO];
- decisão do usuário: registrar como "Decisão do usuário (data): …";
- nova entrega grande: um documento em `docs/projeto/` e uma linha nas seções 3 e 5 deste documento.

## 2. Princípios que valem para todo o sistema

- **Acesso ao banco:**
  - telas e serviços leem por views e gravam por procedures;
  - as migrações podem usar DDL, information_schema e DML pequeno;
  - diagnósticos de leitura podem consultar direto.
- **Serviços de fundo:** todo serviço de backend roda como PHP chamado por uma tarefa do Agendador do Windows. Nenhum serviço abre porta.
- **Legado:**
  - até a virada, o legado prevalece;
  - o sincronismo (`@gp_sinc_legado = 1`) não passa pelos gatilhos de validação;
  - as comparações com o legado são feitas sem alterar nada nele.
- **Telas:**
  - menus no catálogo universal;
  - popups pelo GreenPortalPopups (sem `confirm()`/`alert()` nativos);
  - Excel só pela fila do Agendador;
  - PDF com o nome da empresa no cabeçalho;
  - ajuda só com p, listas, negrito, itálico e links.
- **IA:**
  - a IA só sugere; aprovar, reprovar ou devolver é sempre de uma pessoa;
  - todo uso passa pelo taxímetro (migração 340).
- **E-mail:** SMTP ainda não configurado, de propósito, para não mandar e-mails de teste a usuários reais.

## 3. Linha do tempo

| Período | O que foi feito |
|---|---|
| Base (migrações 001–027) | Segurança, perfis e permissões, grid padrão, exportação, layout |
| Legislação (028–045) | Base de legislação e requisitos |
| Módulo Legal (046–081) | Requisitos, licenças, LAIA / LPRO / LAIS, correlacionamentos, ajuda |
| Homologação e ESG (082–092) | Migração das tabelas fo_ da homologação de fornecedores |
| Responsabilidades (093–104, 111–117, 127, 152–155) | Atribuições, responsáveis, substituições |
| Não conformidades (105–117) | Primeira versão do módulo de NC |
| Documentos e GED (121–128) | Controle de documentos e gestão de arquivos |
| Treinamentos e infraestrutura (129–133) | Cadastros dos pilares |
| Exportação (134–137) | Exportação para Excel pela fila |
| IA (138–148, 168–179) | Primeiras análises de documentos por IA |
| Medição e levantamentos (156–167) | Medição e monitoramento, levantamentos |
| Serviços P1/P2 (183–205) | Tarefas, agenda, workflow, conteúdo, questionários, guia do usuário, serviços do Agendador |
| Idiomas e homologação (208–240) | Idiomas, grid da homologação, assistente de IA, ajuda das telas |
| 13 a 17/10/2026 (245–264) | Cadastros da homologação, Portaria (documentação de fornecedor e de funcionário), pesquisa de documentos |
| Migração de empresas (265–275) | Goodyear, módulos por empresa, fila de migração |
| Plataforma (280–291) | Acesso a dados por procedures, auditoria, GED com IA, situação legal calculada, dashboard em abas, visualizador, protocolo, ranking de fornecedores, painel lateral |
| 17 a 20/10/2026 (292–319) | Etapas da homologação: critérios, documentos, painel, regras automáticas, relatórios, restrição por unidade, cronograma de auditoria |
| 20 a 23/10/2026 (320–340) | Não conformidades completas (base, desempenho, administração, IA na causa e na eficácia, configuração) e o taxímetro de IA |
| 23/10/2026 (341–346) | Risco dos requisitos (com sugestão da IA), alertas por e-mail, taxas das licenças, responsáveis automáticos, busca semântica |
| 24 a 26/10/2026 (347–351) | Modelos de documento, integração de funcionários, análise de adulteração, decisões em aberto da homologação, backup e retenção |

## 4. Catálogo das migrações com runner (183 a 351)

As migrações 001 a 182 não têm runner em `scripts/`: são os arquivos `.sql`/`.php` de `database/`, agrupados pelos temas da linha do tempo acima. Da 183 em diante, cada migração tem `scripts/aplicar-migracao-NNN-<nome>.php` e pode ser repetida. A coluna "Último log" mostra a data do log mais recente encontrado no servidor; "—" quer dizer que não há log guardado, e não que a migração não foi aplicada.

| Nº | Runner (scripts/aplicar-migracao-NNN-…) | Último log de aplicação |
|---|---|---|
| 183 | task-service | — |
| 184 | responsabilidade | — |
| 185 | substituicao | — |
| 186 | agenda | — |
| 187 | pendencias | — |
| 188 | workflow | — |
| 189 | content | — |
| 190 | windows-services | — |
| 191 | async | — |
| 192 | ai-tarefas | — |
| 193 | questionnaire | — |
| 194 | questionarios-admin | — |
| 195 | questionnaire-workflow | — |
| 196 | questionnaire-context | — |
| 197 | windows-task-scheduler | — |
| 198 | servicos-task-monitor | — |
| 199 | guia-usuario | — |
| 200 | ajuda-guia-usuario | — |
| 201 | guia-usuario-links | — |
| 202 | questionnaire-contexts | — |
| 203 | perguntas-legais | — |
| 204 | ajuda-questionarios | — |
| 205 | questionario-sga-exemplo | — |
| 206 | questionarios-exemplos | — |
| 207 | hierarquia-questionario-legal | — |
| 208 | versionamento-licencas | — |
| 210 | home-pendencias | — |
| 211 | idiomas | — |
| 212 | idiomas-menus-colunas | — |
| 213 | idiomas-mensagens | — |
| 214 | corrigir-menus-lais | — |
| 215 | menus-portaria-alexsandro | — |
| 216 | homologacao-fornecedores | — |
| 217 | saneamento-fo | — |
| 218 | funcionarios-somente-com-fornecedor | — |
| 219 | funcionarios-grid-codunidade | — |
| 220 | funcionarios-grid-busca-collation | — |
| 221 | funcionarios-documentos-ia | — |
| 222 | ged-analises-ia | — |
| 223 | ged-analises-ia-duplicatas | — |
| 224 | content-document-links | — |
| 225 | assistente-ia, controle-document-links | — |
| 226 | tipos-documentos-licenciatarios | — |
| 227 | catalogo-tipos-licencas | — |
| 228 | corrigir-tipos-licencas | — |
| 229 | menu-tipos-documentos-universal | — |
| 230 | solicitacoes-melhoria, vinculos-licenca-requisito | — |
| 231 | relatorios-fase5 | — |
| 232 | filtro-requisitos-sem-evidencia, usuario-superior | — |
| 233 | busca-generica-collation | — |
| 235 | ajuda-telas | — |
| 236 | assistente-permissoes | — |
| 237 | ajuda-telas-leva2 | — |
| 238 | ajuda-telas-leva3 | — |
| 239 | piloto-assistente | — |
| 240 | perfis-por-grupo-atlas | — |
| 245 | criterios-funcionarios | 17/10/2026 |
| 246 | assistente-procedures | — |
| 247 | permissoes-transacao | — |
| 248 | cadastros-homologacao | 17/10/2026 |
| 249 | tipos-funcionarios | 17/10/2026 |
| 251 | tipos-funcionarios-igual-legado | 17/10/2026 |
| 255 | menu-modulo-legal-temas | 13/10/2026 |
| 256 | permissoes-levantamento-social | 13/10/2026 |
| 257 | menus-codmenu-universal | 13/10/2026 |
| 259 | mover-menu | — |
| 260 | portaria-documentacao-fornecedor | 25/10/2026 |
| 261 | portaria-documentacao-fornecedor-ia | 17/10/2026 |
| 262 | portaria-documentacao-funcionario | 25/10/2026 |
| 263 | portaria-documentacao-funcionario-treinamentos | 17/10/2026 |
| 264 | busca-documentos | 15/10/2026 |
| 265 | modulos-empresa | — |
| 266 | gestao-migracao-empresas, permissao-baixar-arquivos | — |
| 267 | goodyear-seguranca | — |
| 268 | goodyear-legal-catalogos, perfil-atribuicoes | — |
| 269 | goodyear-licencas | — |
| 270 | goodyear-ged | — |
| 271 | goodyear-controles | — |
| 273 | goodyear-dominios-legais | — |
| 275 | fila-migracao | — |
| 280 | procedures-acesso-dados | — |
| 281 | auditoria-alertas | — |
| 282 | ged-licencas-ia | — |
| 283 | servicos-task-scheduler | — |
| 284 | fila-ia-documentos, situacao-legal | — |
| 285 | dashboard-abas | — |
| 286 | requisitos-inativados | — |
| 287 | visualizador | — |
| 288 | licencas-protocolo | — |
| 289 | ranking-fornecedores | — |
| 290 | painel-lateral | — |
| 291 | questionarios-empresa | — |
| 292 | tipos-criterios-fornecedores | 17/10/2026 |
| 293 | documentos-fornecedor | 18/10/2026 |
| 294 | documentos-repetidos | 17/10/2026 |
| 295 | historico-documentos | 17/10/2026 |
| 296 | fornecedores-desativados-externo | 17/10/2026 |
| 297 | funcionarios-fornecedor-externo | 17/10/2026 |
| 298 | painel-homologacao | 17/10/2026 |
| 299 | menu-homologacao | 17/10/2026 |
| 300 | protocolo-ged-homologacao | 17/10/2026 |
| 301 | regras-documentos-homologacao | 18/10/2026 |
| 302 | protocolo-sem-cobranca | 17/10/2026 |
| 303 | funcionario-itens | 18/10/2026 |
| 304 | menu-migracao-empresas | — |
| 305 | sincronismo-tela | 25/10/2026 |
| 306 | requisitos-por-licenca | — |
| 307 | condicionantes-licenca | — |
| 308 | planodeacao-ia | — |
| 309 | ajuda-interativa | — |
| 310 | pendencias-homologacao-home | — |
| 311 | pendencias-home-views | — |
| 312 | agenda-alertas-views | — |
| 313 | indices-ficha-fornecedor | — |
| 314 | relatorios-homologacao | 19/10/2026 |
| 315 | documentos-funcionario-tipo-atual | 19/10/2026 |
| 316 | restricao-unidade-homologacao | 19/10/2026 |
| 317 | cronograma-auditoria-homologacao | 20/10/2026 |
| 318 | data-auditoria | — |
| 319 | perfil-fornecedores | — |
| 320 | permissoes-gestores-crown | 20/10/2026 |
| 321 | nao-conformidades-base | 22/10/2026 |
| 324 | nc-desempenho | 21/10/2026 |
| 326 | menus-nc-universal | 21/10/2026 |
| 330 | ged-dono-arquivo | 22/10/2026 |
| 333 | nc-administracao | 22/10/2026 |
| 335 | nc-administracao-menus | 23/10/2026 |
| 336 | nc-analise-ia | 23/10/2026 |
| 337 | nc-eficacia-ia | 23/10/2026 |
| 338 | nc-eficacia-evidencias | 23/10/2026 |
| 339 | nc-configuracao | 23/10/2026 |
| 340 | ia-consumo | 23/10/2026 |
| 341 | requisitos-risco | — |
| 342 | risco-sugestao-ia | — |
| 343 | alertas-email | — |
| 344 | licencas-taxas | — |
| 345 | responsaveis-automaticos | — |
| 346 | busca-semantica | — |
| 347 | documento-modelos | 25/10/2026 |
| 348 | integracao-funcionarios | 25/10/2026 |
| 349 | documento-adulteracao | 24/10/2026 |
| 350 | decisoes-homologacao | 25/10/2026 |
| 351 | backup | 26/10/2026 |

## 5. Serviços do Agendador do Windows

Todos rodam como PHP (`scripts/windows-service.php --service=…` ou um worker próprio), instalados pelos `scripts/criar-agendamento-*.ps1`, e aparecem em Administração › Serviços.

| Serviço | Frequência | O que faz |
|---|---|---|
| Backup (`criar-agendamento-backup.ps1`, GreenPortal-Backup) | a cada 15 min; backup diário às 02:00 | Bancos `*_dbp`, aplicação e GED; rotação de 14 diários + 8 semanais; retenção nas empresas (migração 351) |
| Busca (GreenPortal-BuscaWorker) | contínuo | Índice por palavra, busca semântica e reavaliação por mudança de lei (264, 346) |
| Documentos da homologação | 60 min | Regras automáticas dos documentos (301) e reaplicação da validade da integração (348) |
| E-mail (GreenPortal-EmailWorker) | 2 min | Alertas → e-mail e envio da fila (343); parado até o SMTP ser configurado |
| Consumo de IA (GreenPortal-IaConsumo) | 30 min | Cotação PTAX e avisos de limite (340) |
| IA (GreenPortal-IaWorker) | contínuo | Fila de IA dos documentos, NC, risco, modelos e adulteração |
| OCR | contínuo | Leitura de PDFs e imagens (fila green_ocr_jobs) |
| Lembretes de NC (GreenPortal-NcLembretes) | 60 min | Regras de lembrete da NC (339) |
| Protocolo | diário 06:40 | Protocolos das licenças (288) |
| Ranking | 15 min | Ranking dos fornecedores (289) |
| Relatórios | segunda 07:20 | Relatórios gerenciais da homologação (314) |
| Resumo | segunda 07:10 | Resumo semanal |
| Sincronismo | a cada 1 min; diário às 05:00 | Sincronismo com o legado: simula e só aplica se as exclusões ficarem até o limite (305, RN-MIG-100) |
| Visualizador | contínuo | Conversão para o visualizador de arquivos (287) |
| Exportação (GreenPortal-ExportWorker) | contínuo | Planilhas Excel pedidas nas telas |
| Migração (275) | sob demanda | Fila de migração de empresas |

**Atenção:** a tarefa antiga `sincronizar-fo-legado.bat` ainda roda por volta das 02:04 e aplica direto, sem simular e sem limite. Ela também coincide com o backup das 02:00 (ver seção 7).

## 6. Trabalho de 24 a 26/10/2026 (commits c563 a c573)

| Commit | O que mudou |
|---|---|
| c563 | Regras de negócio de todos os módulos documentadas em `docs/regras-negocio-*.md` |
| c564–c565 | Análise de adulteração dos documentos (migração 349); RN-HOM-109 a 118 |
| c566 | Decisões em aberto fechadas em 24/10 (migração 350 e revisão da 305): ver `docs/projeto/decisoes-24-10-2026.md` |
| c567 | RN-MIG-99/100 e RN-HOM-119 a 122 marcadas como FEITO (305 e 350 aplicadas em 25/10) |
| c568 | Pendências do README atualizadas (347 e 348 aplicadas) |
| c569 | Runner da 348 com a opção `--criar-modelo` (cria o questionário INTEGRACAO_FUNCIONARIO_PADRAO quando não existe) |
| c570 | Certificado da integração com a hora de Brasília no rodapé |
| c571 | Questionários: confirmação pelo popup do portal (fim do `confirm()` nativo). Integração: funcionário desligado não recebe link (`vw_fo_funcionario_desligado`, erro FUNCIONARIO_DESLIGADO) |
| c572 | RN-HOM-123 (desligado sem link; 402 de 1.001 na MODELO) e RN-HOM-124 (questionário modelo publicado e ligado na MODELO) |
| c573 | Backup e retenção (migração 351): tela Administração › Backup, worker, tarefa do Agendador; RN-PLT-131 a 136 |
| c574 | Este histórico, a cópia dos documentos do projeto em `docs/projeto/` e a regravação de RN-HOM-123/124 em `regras-negocio-04` (o arquivo do c572 não tinha chegado ao servidor) |

**Testes feitos nesse período:**
- **Integração na MODELO**, com o usuário logado como admin: link → respostas → 83,3% com sugestão Aprovado → aprovação → validade até 25/10/2027, Portaria "Liberado" e certificado em PDF.
- **Backup, em ambiente local:**
  - o dump restaurado bateu em tabelas, rotinas, gatilhos e linhas;
  - a rotação manteve 14 diários e os semanais;
  - um backup com erro apaga a própria pasta e gera alerta;
  - a tela abre.

### Backup e retenção (migração 351) — decisões do usuário (26/10/2026)
- **Destino:** pasta configurável no `.env` (`BACKUP_DIR`). Sem ela, o backup vai para `backups\automatico` dentro da aplicação, e a tela avisa que o destino não está protegido.
- **Quando:** diário às 02:00. A rotação mantém 14 diários e o último de cada semana ISO por 8 semanas, pela hora do banco.
- **Conteúdo:**
  - todos os bancos `*_dbp`, pelo mysqldump, compactados, conferindo o "Dump completed";
  - a aplicação (código e `.env`) em zip, sem backups, exports, runtime, storage, .git e node_modules;
  - o GED, num espelho incremental (`ged_espelho/portal` e `ged_espelho/legado`);
  - um MANIFEST.txt.
- **Retenção:** agendada em todas as empresas.
- **Alertas ao ADMINISTRADOR:** em caso de erro, e quando passam mais de 26 h sem backup.
- **Tela:** Administração › Backup, só para ADMINISTRADOR, com situação, configuração, histórico, cancelar e "Fazer backup agora".

## 7. Pendências e decisões em aberto (26/10/2026)

**Decisões que o usuário precisa tomar:**
- **Tarefa antiga do sincronismo** (`sincronizar-fo-legado.bat`, 02:04): desligar, porque o sincronismo novo das 05:00 já simula e respeita o limite, ou mudar o backup para 01:00.
- **Tabela de severidade dos fornecedores** (risco dos requisitos): validar com o Ricardo.

**Configurações a cargo do usuário** (os valores não ficam registrados aqui):
- **`BACKUP_DIR` no `.env`:** apontar para um disco ou compartilhamento fora do servidor.
- **"Fazer backup agora":** clicar em Administração › Backup e conferir o primeiro backup.
- **`GREENPORTAL_URL` no `.env`:** endereço usado nos links dos e-mails e da integração.
- **SMTP no `.env`:** só quando o usuário decidir ligar os e-mails.

**Testes e ajustes ainda abertos:**
- Teste de restauração do backup e da retenção (RN-PLT-126): fica manual.
- Adulteração: testar com documentos reais (certidões assinadas, ASO digitalizado) para calibrar falsos positivos.
- NC: testar a leitura das evidências na Crown (338); ligar as regras de lembrete desejadas (339).
- Legislação: `registros_migracao` do glegislacao_dbp sem a coluna `quantidade_registros` (aviso no sincronismo).
- MODELO e MODELO2: 4 datas de integração no futuro, provavelmente validade digitada no lugar da realização.
- Segurança no legado: processos com a senha do root do MySQL no código. Recomenda-se trocar a senha e lê-la de um arquivo de configuração.
