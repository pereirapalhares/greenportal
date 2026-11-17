# Não Conformidades › Administração — análise do legado e proposta (22/10/2026)

Pedido do usuário: estudar o menu Não Conformidades › Administração do legado e propor melhorias (não copiar). Usuários: ADM DA EMPRESA e ADMINISTRADOR.
Fontes: código do legado (cadastros/, configuracoes/ — só leitura) e scripts/diagnostico-nc-administracao-legado.php (c530, Crown).

## 1. O que o legado tem (Crown)

Menu em 3 grupos (Administração NC, Administração NC Clientes, Administração NC Fornecedores), 31 itens:

| Grupo | Itens | Como funciona |
|---|---|---|
| Correções por NR/Ano (12) | Alterar data, emitente, responsável, gestor, etapa, ocorrência (sumário/descrição), ação imediata e abrangência, lista e unidade, cliente, fornecedor, quantidade | Uma tela por campo: digita o NR/Ano e grava direto na tabela. Sem histórico, sem motivo, sem aviso aos envolvidos, sem validação (etapa livre e ainda apaga a SITUACAO), SQL montado com o texto digitado. |
| Cadastros (15) | Setores (10), Tipo de ocorrência (18), Origem (85), Sistema de gestão (2), Classificação (13), Tipos de causa (7), Classificação de fornecedores (7), 7 cadastros do Cliente, Categorias | Grid genérico (cadastros_c.php). Exclusão sem conferir uso. Origem tem 85 itens, 54 usados desde 2025. |
| Estrutura (1) | Tipo de ocorrência × Campos (121 vínculos) | Só ADMINISTRADOR. Define os campos de cada tipo; obrigatoriedade fica no código. |
| Pessoas (2) | Lista de distribuição (425 listas, 3.197 vínculos; 253 usadas, 132 desde 2025; maior com 87 pessoas), Participantes | "Participantes" = pessoas sem login (usuarios.ativado = 'X', nome + e-mail) que entram nas listas e só recebem e-mail. |

Acesso: ADM DA EMPRESA (44 usuários) e ADMINISTRADOR (5) em quase tudo; Alterar emitente e Campos por tipo só ADMINISTRADOR.
Fora do menu, também administrativo: política de e-mails (lembretes por regra, frequência, repetição e envio ao superior) e as listas "todas / pendentes / finalizadas".

Situações que hoje pedem correção manual (Crown, NCs abertas = 2.900): 224 abertas sem gestor; 643 em análise/plano sem responsável (620 Planejamento — correto); idade: 206 até 90 dias, 852 de 90 dias a 1 ano, 1.226 de 1 a 3 anos, 616 com mais de 3 anos; 7 com a etapa com acentuação quebrada.

Cuidados no legado (não abrir): configuracoes/nc_indicacao_ocorrencia_todas.php (inclui cadastros/botoes_indicacao_ocorrencia_todas.php, que faz UPDATE de etapa ao carregar) e configuracoes/nc_etapas.php (muda etapa; tem a senha root do MySQL no código — revisar).

## 2. Proposta (resumo)
Um só menu Não Conformidades › Administração, por tarefa: Correção de ocorrência (com motivo, histórico antes → depois, aviso, etapa só por ações controladas), Transferência de responsabilidades, Painel de saneamento, Cadastros (Em uso, desativar em vez de excluir), Listas de distribuição e participantes, Configuração do módulo (campos/obrigatórios por tipo, parâmetros, lembretes — só ADMINISTRADOR).

## 3. Decisões do usuário (22/10/2026)
- Participantes externos (sem login): **continuam, recebendo por e-mail** (envio pelo serviço do Agendador do Windows).
- NCs antigas: **deixar como está, não alterar nada** — sem encerramento em lote.
- Até a virada: **correções só nas NCs do greenportal** (faixa 50001+); as do legado só consulta.
- Ordem das fases: 1 cadastros + listas → 2 correção + transferência → 3 saneamento + lembretes → 4 configuração do módulo.
- Grupo "Cadastros" renomeado para **"Administração"** (mesmo código NAO_CONFORMIDADES_CADASTROS).

## 4. Implementado
- **Fase 1a (c531/c532, migração 332):** 7 cadastros gerais com Ativo, Em uso, nome repetido bloqueado, legado só Ativo editável.
- **Fase 1b (c533/c534, migração 333 — aplicada em 22/10):**
  - Listas de distribuição (naoconformidades_listasdistribuicao.php + popup naoconformidades_listadistribuicao.php): pessoas, externos, inativos, NCs, último uso; listas do legado só consulta + "Copiar como nova lista"; novas a partir de 50001 (FAIXAS_NOVO: nc_listadistribuicao, xusuarios, xcategoria). Histórico em nc_admin_historico.
  - Participantes externos (nc_participantes; legado ativado='X' pelo sincronismo — NaoConformidadeParticipantes::sincronizar no sincronizar-fo-legado.php; nome/e-mail do legado, Ativo do greenportal; novos a partir de 90000001). Crown: 809 carregados, 640 em listas; 150 vínculos apontam para pessoas inexistentes (igual no legado).
  - Fila de e-mail (email_fila, sp_email_*), serviço scripts/email-worker.php (windows-service --service=email; tarefa GreenPortal-EmailWorker a cada 2 min por scripts/criar-agendamento-email.ps1; EMAIL_WORKER em Administração › Serviços), SMTP próprio (classes/SmtpCliente.php, .env SMTP_*). sp_nc_alertar (321) chama sp_nc_email_participantes no envio ao gestor. Administração › E-mails enviados mostra a fila e reenvia os com erro. **Pendente: SMTP no .env e instalar a tarefa.**
  - gmodelo2_dbp: usuarios sem a coluna emails — a migração adapta a view (c534).
- **Fases 2 e 3 (c535, migração 334 — rodar de novo a 333):** Correção de ocorrência (sp_nc_admin_corrigir; sp_nc_admin_etapa REABRIR/DEVOLVER/ENCERRAR com destino calculado pelo histórico), Transferência de responsabilidades (vw_nc_admin_responsabilidades, sp_nc_admin_transferir), Painel de saneamento (vw_nc_saneamento, vw_nc_acoes_vencidas; atribuir gestor e lembrar responsáveis, só NCs do greenportal; não encerra NCs). Registros no histórico da NC: CORRECAO_ADMIN, ADMIN_REABRIR/DEVOLVER/ENCERRAR, TRANSFERENCIA_ADMIN, GESTOR_ATRIBUIDO_ADMIN.
- **Fase 4 (pendente):** configuração do módulo (campos e obrigatórios por tipo, parâmetros por empresa, regras de lembrete pelo Agendador).
