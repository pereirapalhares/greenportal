# Regras de negócio do GreenPortal — índice

Atualizado em 26/10/2026.

Esta pasta reúne as regras de negócio de todos os módulos do GreenPortal: o que o sistema faz hoje, o que ele impede ou calcula, e as decisões do usuário que deram origem a cada regra. Cada regra tem um código, a situação, onde está no sistema (tela, procedure, classe ou serviço) e a origem (migração, documento ou decisão).

Legenda das situações:
- **[FEITO]** em produção;
- **[PENDENTE]** decidido, mas ainda não aplicado ou configurado;
- **[A CONFIRMAR]** dúvida em aberto, que depende de decisão.

## Documentos

| Documento | Código das regras | Regras | Assunto |
|---|---|---|---|
| [regras-negocio-01-plataforma.md](regras-negocio-01-plataforma.md) | RN-PLT | 141 | Multiempresa, login e sessão, perfis e permissões, catálogo universal de menus, idiomas, ajuda, padrão de telas, exportação Excel, serviços do Agendador, GED, visualizador e OCR, alertas e e-mail, IA e taxímetro, Pesquisa de documentos e busca semântica, auditoria, backup |
| [regras-negocio-02-modulo-legal.md](regras-negocio-02-modulo-legal.md) | RN-LEG | 106 | Legislação, requisitos legais, % de atendimento e risco, sugestão de risco pela IA, potenciais da IA, reavaliação por mudança de lei, licenças, protocolo, condicionantes, taxas, pilares, LAIA/LPRO/LAIS, Relatório de Atendimento Legal |
| [regras-negocio-03-tarefas-responsabilidades.md](regras-negocio-03-tarefas-responsabilidades.md) | RN-TAR | 111 | Modelo de responsabilidades, perfil de atribuições, responsáveis automáticos, tarefas, agenda, pendências, painel do gestor, dashboards, motor de questionários |
| [regras-negocio-04-homologacao-fornecedores.md](regras-negocio-04-homologacao-fornecedores.md) | RN-HOM | 124 | Fornecedores, documentos e cores, regras automáticas, protocolo, auditoria, funcionários, integração de funcionários, acesso externo e por unidade, Portaria, IA nos documentos, modelos de documento, análise de adulteração, ranking, painel e relatórios |
| [regras-negocio-05-nao-conformidades.md](regras-negocio-05-nao-conformidades.md) | RN-NC | 103 | Tipos de NC, abertura, etapas e quem atua, fornecedor externo, visibilidade, avisos e listas, administração, configuração e lembretes, IA na causa e na eficácia, PDF, cadastros |
| [regras-negocio-06-migracao-legado.md](regras-negocio-06-migracao-legado.md) | RN-MIG | 100 | Convivência com o legado, migração de empresas, sincronismo legado → GreenPortal, bancos da homologação, clonagem de tenant, situação por empresa |

## Histórico e documentos do projeto

- [historico-implantacao-greenportal.md](historico-implantacao-greenportal.md): fases, catálogo das migrações 183 a 351, serviços do Agendador, trabalho de 24 a 26/10/2026 e pendências.
- [projeto/README.md](projeto/README.md): os 28 documentos de análise e decisão do projeto (pedidos, decisões do usuário, como foi feito e testes).

## Princípios que valem para todos os módulos

- O legado (sistema anterior) prevalece até a virada. Ele nunca é alterado pelo GreenPortal, e as telas do legado que gravam ao abrir não são abertas.
- Cada empresa tem o seu banco (`g*_dbp`); o banco universal guarda o que é comum (empresas, menus, textos, ajuda, prompts, preços de IA).
- As telas leem só por views e gravam só por procedures. Scripts de migração e manutenção podem usar DDL; diagnósticos só de leitura podem consultar direto.
- Todo menu existe no catálogo universal; cada tipo de NC tem o seu menu e o seu PHP.
- Todo serviço em segundo plano é um PHP rodado pelo Agendador do Windows, sem portas.
- Exportação para Excel só pela fila do Agendador, com alerta de início e de conclusão.
- Popups pelo componente do portal; alertas abrem a tela como aba do portal.
- Todo PDF traz o nome da empresa no cabeçalho.
- A IA só sugere: aprovar, reprovar ou gravar é sempre decisão de uma pessoa. O uso da IA passa pelo taxímetro e respeita o limite mensal da empresa.
- O e-mail está pronto, mas o SMTP só será configurado depois, por decisão do usuário (para não enviar e-mails de teste a usuários reais).

## Principais decisões em aberto

A lista completa está no fim de cada documento. As que mais afetam o negócio:

1. **Virada:** data e critério da virada de cada empresa; se a proteção de campos volta a valer ou se o sincronismo é desligado (RN-MIG, RN-HOM).
2. **Tabela de severidade dos fornecedores** no risco do requisito: validar com o Ricardo (02).

Decididas em 24/10/2026: sincronismo respeita o indicador de sincronismo ativo (Goodyear Americana fora; RN-MIG-99); agendamento diário simula e grava até o limite de exclusões (RN-MIG-100); bloqueio do ranking vale na Portaria e nos novos documentos, com aviso (RN-HOM-122); cores da Portaria pela regra do Painel (RN-HOM-120); data da integração = realização (RN-HOM-119); exclusão de funcionário com documentos bloqueada, usar Desligado (RN-HOM-121); gestor de NC = qualquer usuário ativo (RN-NC-17); risco do requisito = pior entre todos os itens (RN-LEG-40).

## Pendências de aplicação (já decididas)

- Integração de funcionários (348, aplicada em 25/10/2026, como a 347, a 305 revisada e a 350): publicar o questionário de integração, ligá-lo ao padrão da empresa ou aos tipos de funcionário e preencher `GREENPORTAL_URL` no .env.
- Backup (351): configurar `BACKUP_DIR` (e, se preciso, `MYSQLDUMP_PATH`) no .env, aplicar a 351, instalar `scripts\criar-agendamento-backup.ps1` e conferir o primeiro backup em Administração › Backup.
- Modelos de documento (347): cadastrar e ativar os modelos nos Critérios; até lá nada é validado.
- Aplicar a 339 em todos os bancos, instalar a tarefa de lembretes de NC e ligar as regras desejadas.
- Configurar o SMTP (quando o usuário decidir).

## Como manter

- Toda regra nova ou alterada entra no documento do módulo, com o próximo número livre do código (não renumerar as existentes).
- Ao mudar uma regra, atualizar a situação e a origem (migração ou decisão, com data).
- Decisões do usuário são registradas como "Decisão do usuário (data): ...".
- Nunca registrar senhas, chaves, tokens ou valores do .env; só o nome da variável.
