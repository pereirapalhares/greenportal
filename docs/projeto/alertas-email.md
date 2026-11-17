# Alertas do portal por e-mail (commit c555, migração 343 — 23/10/2026; c556 Luxottica)

Item da Onda 1 da apresentação Atlas ("alertas por e-mail com link direto").

## Decisões do usuário
- Cada usuário escolhe em **Minha conta › Alertas por e-mail**: **na hora** (padrão), **resumo diário às 7h** (só os não lidos) ou **não receber**.
- O e-mail traz **"Abrir no portal"**: login (se preciso) e a tela do alerta abre como aba; o alerta fica lido.
- SMTP: **não será configurado agora** (o usuário não quer arriscar mandar e-mails de teste a usuários reais). Ele mesmo preenche o .env depois (nunca vemos a senha).
- Alerta e e-mail no **mesmo serviço e mesma tarefa**: primeiro os alertas viram e-mail, depois a fila é enviada.

## Como funciona
- Serviço de e-mail do Agendador (GreenPortal-EmailWorker, a cada 2 min, `scripts\criar-agendamento-email.ps1`): `windows-service.php --service=email` chama `AlertaEmailService::gerar()` e depois `EmailFilaService::processar()`.
- `sp_alertas_email_novos`: alertas depois da marca ULTIMO_CODALERTA (gravada na migração: o histórico não vai). Os alertas de uma pessoa no mesmo ciclo vão num e-mail só. Não vão: "Exportação iniciada", alertas com mais de 1 dia, usuário sem e-mail válido, opção "não receber".
- Resumo: `alertas_email_resumo` → às 7h, um e-mail por pessoa (`sp_alertas_email_resumo_pendentes` / `_concluir`, controle RESUMO_DIA).
- Sem SMTP no .env a marca só avança e nada entra na fila (nada do período sem SMTP é enviado depois — sem risco de e-mail em massa quando o SMTP for ligado).
- Link: `GREENPORTAL_URL` + `/principal/abrir_alerta.php?e=EMPRESA&a=ALERTA` → mesma empresa logada: `main.php?abrir_alerta=N`; senão `index.php?empresa=X&abrir_alerta=N` → `credenciais/logar.php` → `main.php?abrir_alerta=N`. main.php só abre alerta do próprio usuário e só link relativo do portal.
- E-mail HTML: marca `{{botao:Texto|URL}}` vira botão (EmailFilaService::html); no texto simples "Texto: URL".

## Luxottica (c556)
A Luxottica não tinha a fila de e-mails (migração 333 é do módulo de NC). O runner da 343 agora seleciona as empresas pelas tabelas menus/perfis/permissoes/usuarios e, quando faltam as 6 procedures sp_email_*, a tabela email_fila ou a tabela alertas, aplica `database/343_fila_email.sql` (email_fila, vw_email_fila, sp_email_*, alertas) e cria vw_alertas_grid se faltar. Idempotente. Para aplicar: rodar a 343 de novo (`--simular`, depois sem).

## Configuração (.env, preenchida pelo usuário — mais tarde)
SMTP_HOST, SMTP_PORT, SMTP_SEGURANCA (tls | ssl | nenhuma), SMTP_USUARIO, SMTP_SENHA, SMTP_REMETENTE, SMTP_REMETENTE_NOME, GREENPORTAL_URL.

## Arquivos
database/343_alertas_email.sql, database/343_fila_email.sql, database/343_ajuda_alertas_email.php, scripts/aplicar-migracao-343-alertas-email.php, classes/AlertaEmailService.php, classes/EmailFilaService.php (botão), scripts/windows-service.php, principal/abrir_alerta.php, principal/minha_conta_alertas_email.php, principal/main.php, index.php e credenciais/logar.php.

## Testado localmente
Servidor SMTP de teste: e-mail na hora (agrupado, botão, filtro de "Exportação iniciada"), resumo diário só com não lidos, preferências, redirecionamentos do link e bloqueio de links inválidos. c556: banco sem fila (simulação, criação, nova execução sem recriar) e e-mail gerado e enviado nesse banco.
