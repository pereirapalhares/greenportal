# Responsáveis automáticos pelo perfil de atribuições (commit c558, migração 345 — 23/10/2026; c559)

Gestão de tarefas, etapa 3 ("da tarefa identificada à ação concluída"). Migração aplicada nas 7 empresas em 23/10/2026.

## Decisões do usuário
- **Alcance:** a regra vale para as tarefas dos potenciais aprovados da IA e para os itens novos dos pilares cadastrados sem responsável.
- **Mais de uma pessoa executa o pilar na unidade:** a tarefa vai para quem tem menos tarefas abertas; as outras pessoas acompanham.
- **Ninguém no perfil:** a tarefa fica com quem pediu a análise e o ADM DA EMPRESA recebe um alerta. O item fica sem responsável e o ADM recebe o alerta.
- **(c559) Empresa sem nenhum perfil aprovado:** o alerta ao ADM fica desligado, para não virar um alerta por item ou tarefa. Ele liga sozinho quando o primeiro perfil de atribuições com pilar for aprovado. Em 23/10 só o Atlas tinha 1 perfil aprovado, e nenhum pilar tinha quem executasse.
- **ACOMPANHA:** recebe um alerta quando a tarefa é criada ou o item é atribuído.
- **APROVA:** recebe um alerta quando a tarefa é concluída, para conferir.

## Como funciona
- **Candidatos** (`vw_resp_auto_candidatos`): vêm do perfil vigente, aprovado pelo gestor.
  - Há uma linha por usuário, módulo, pilar, papel e unidade.
  - Quem não marcou unidades no perfil vale para todas.
  - A view traz as tarefas abertas de cada um (green_tasks PENDENTE ou PROCESSANDO).
- **Tarefa da IA** (AiApprovedTaskService):
  - O tipo do potencial vira o pilar: LICENCA → LICENCAS, MONITORAMENTO, TREINAMENTO → TREINAMENTOS, INFRAESTRUTURA, CONTROLE → CONTROLES, INFLUENCIA → HOMOLOGACAO_FORNECEDORES.HOMOLOGACAO.
  - A unidade é a do contexto da análise.
  - O motivo da escolha vai na descrição da tarefa, e `criado_por` continua sendo quem pediu.
  - Quem pediu recebe o alerta "Tarefa encaminhada".
  - A indicação fica registrada em `resp_auto_registro` (AREA = TAREFA).
- **Itens novos sem responsável:**
  - Áreas cobertas: li_licencas, mm_monitoramento, ml_treinamentos, ml_infraestrutura e documentos (controles).
  - Quem faz: o serviço de responsabilidades, pela função `responsaveisAutomaticos()`.
  - Só entram os itens com código acima da marca gravada na migração (`resp_auto_controle`); os que já existiam ficam como estão.
  - O item visto pela primeira vez fica AGUARDANDO. Depois de 1 hora ainda sem responsável, o sistema cria a responsabilidade direta em `responsabilidade_atribuicoes`, com ORIGEM DIRETA e a observação "Indicado automaticamente…".
  - Se alguém indicar o responsável antes disso, o registro fica como JA_TINHA.
  - Licença com versões: o responsável de qualquer versão da mesma licença vale, tanto a coluna CODUSUARIO quanto as atribuições.
  - `vw_resp_auto_itens` é montada pelo runner conforme as colunas de cada banco.
- **Concluir tarefa:**
  - Na Home › Agenda há o botão Concluir (ícone de visto), habilitado na linha de tarefa.
  - Ele chama `api/tarefa_concluir.php`, que chama `sp_tarefa_concluir`. Só o destinatário conclui, e só tarefa pendente.
  - Ao concluir, quem APROVA o pilar recebe o alerta para conferir.
- **Link dos alertas de item:** `principal/resp_auto_abrir.php?area=X&id=N`. O link do alerta não pode levar JSON, então essa página monta o filtro exato e redireciona para o cadastro.

## Banco
- Tabelas: `resp_auto_controle` e `resp_auto_registro`.
- Views: `vw_resp_auto_candidatos`, `vw_resp_auto_adm`, `vw_resp_auto_registro` e `vw_resp_auto_itens`.
- Procedures: `sp_resp_auto_pendentes`, `sp_resp_auto_atribuir`, `sp_resp_auto_tarefa`, `sp_resp_auto_alerta` e `sp_tarefa_concluir`.
- Ajuda: Perfis de atribuições, Meu perfil e Agenda, com o marcador ml345.

## Aplicar
```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-345-responsaveis-automaticos.php --simular
```
Depois, rodar o mesmo comando sem `--simular`. O relatório mostra, por pilar, quantas pessoas executam.

## Testado localmente
- Escolha pelo menos carregado e pela unidade.
- Acompanhantes e aprovadores.
- Pilar sem ninguém (aviso ao ADM).
- Responsável indicado à mão dentro da 1 hora.
- Renovação de licença herdando o responsável.
- Tarefas da IA encaminhadas e o fallback para quem pediu.
- Concluir pela Agenda: aviso ao aprovador, só o destinatário conclui, tarefa já concluída e tela expirada.
- Migração repetida, com a marca mantida.
- (c559) Sem nenhum perfil aprovado, nenhum alerta; depois do primeiro perfil aprovado, o alerta ao ADM volta.
