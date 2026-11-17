# Validação da transação `modulolegal_requisitoslegais.php` — 24/09/2026

**Projeto:** GreenPortal  
**Empresa:** Modelo  
**Banco:** `gmodelo_dbp`  
**Rota:** `/principal/modulolegal_requisitoslegais.php`

## 1. Interpretação do escopo

Esta transação não é um cadastro CRUD genérico de requisitos legais. O grid principal consulta os requisitos e permite alteração específica do nível de controle (`PODER`). As operações de associação são realizadas em popups contextuais.

Portanto, a validação completa precisa cobrir:

- acesso, sessão e permissões;
- carregamento do grid e integridade dos dados;
- filtros, busca, paginação e ordenação;
- seleção de requisito e preservação de contexto;
- alteração controlada de `PODER`;
- popups de Situação, Licenças, Controles, Medição/Monitoramento, Checklist e Alterações;
- exportação, colunas, PDF/padrão legal, ajuda e análise de IA;
- isolamento de unidade, empresa e requisito;
- limpeza e ausência de registros temporários.

## 2. Cobertura já validada

| Área | Resultado | Evidência |
|---|---|---|
| Grid, colunas e colunas técnicas ocultas | Aprovado | `tests/resultado-completo-requisitos-legais-grid.json` |
| Filtros por unidade, poder, atendimento, âmbito, indicadores e texto | Aprovado | `tests/resultado-completo-requisitos-legais-grid.json` |
| Paginação, contagem AJAX, reinício na página 1 e bloqueio de concorrência | Aprovado | `tests/resultado-completo-requisitos-legais-grid.json` |
| Filtros por empresa/unidade e requisitos revogados | Aprovado | `tests/resultado-filtros-legislacao-empresa.json` |
| Contexto de empresa e unidade na legislação | Aprovado | `tests/resultado-nao-visual-contexto-legislacao.json` |
| Colunas: ocultar, salvar e restaurar | Aprovado | `tests/resultado-colunas-requisitos-legais.json` |
| Popups e relacionamentos contextuais | Aprovado | `tests/resultado-completo-requisitos-legais-associados.json` |
| CRUD da Situação | Aprovado | `tests/resultado-crud-situacao-requisitos-legais.json` |
| Checklist: acesso, campos, empresa, unidade e pergunta | Aprovado | `tests/resultado-nao-visual-checklist-legislacao.json` |
| Permissões e bloqueios de acesso | Aprovado tecnicamente | matriz final de pré-go-live e evidências de permissões |
| Registros sem unidade e duplicidades contextuais | Aprovado | histórico completo do Módulo Legal |

## 3. Validação complementar de 24/09/2026

Foi iniciado o roteiro de CRUD dos popups de Licenças, Controles, Medição/Monitoramento e Checklist via Chrome/CDP. O popup de Licenças abriu, confirmou os botões CRUD e a ordem das colunas. O roteiro ficou bloqueado aguardando o refresh do iframe após a inclusão.

O processo de teste foi encerrado para não manter uma sessão indefinida. A consulta posterior confirmou zero associações temporárias para o requisito usado no teste nos seguintes relacionamentos:

- `legislacaoxlicencas`;
- `legislacaoxdocumentos`;
- `mm_monitoramentoxlegislacao`;
- `legislacaoxchecklist`.

Esse bloqueio é do roteiro de automação/espera do iframe e não constitui, isoladamente, erro funcional da transação. O CRUD contextual possui evidências anteriores aprovadas, mas precisa de reteste automatizado estável para fechamento desta rodada.

## 4. Pontos que ainda precisam de fechamento

1. Reexecutar o CRUD completo dos quatro popups alteráveis com espera robusta do refresh do iframe e confirmação de persistência no banco.
2. Revalidar diretamente a alteração de `PODER`, com alteração temporária e restauração do valor original.
3. Executar o fluxo de solicitação de análise de IA iniciado nesta tela, confirmar alerta, status e limpeza da solicitação de teste.
4. Revalidar exportação específica desta transação, incluindo filtros ativos, colunas personalizadas, job, arquivo XLSX e alerta.
5. Revalidar leitura do padrão/texto legal, PDF e link `ARQUIVO` em um requisito real.
6. Revalidar ajuda da transação e ausência de erros JavaScript/PHP/SQL.
7. Repetir acesso direto e permissões da rota com os sete usuários autorizados, confirmando 200/403 conforme a matriz.
8. Fazer teste visual em viewport móvel e desktop, incluindo ausência de overflow e funcionamento dos popups.
9. Registrar aceite funcional de negócio para regras de atendimento, nível de controle, responsabilidade e requisitos revogados.

## 5. Próxima ordem de execução

### Prioridade 1 — integridade e mutação

- corrigir ou tornar resiliente o roteiro de espera do iframe;
- executar CRUD de Licenças, Controles, Medição/Monitoramento e Checklist;
- validar alteração/restauração de `PODER`;
- confirmar zero resíduos no banco.

### Prioridade 2 — fluxos complementares

- executar IA;
- executar exportação;
- testar PDF, padrão legal, arquivo e ajuda;
- confirmar colunas e filtros combinados após recarga.

### Prioridade 3 — segurança e aceite

- repetir matriz de permissões da rota;
- validar contexto Modelo/Modelo2 sem ativar Modelo2 fora do teste;
- obter aceite funcional formal.

## 6. Conclusão atual

A transação está tecnicamente bem coberta em grid, filtros, contexto, colunas, paginação, relacionamentos e Situação. O fechamento integral ainda depende do reteste estável dos quatro CRUDs contextuais, da alteração de `PODER`, dos fluxos de IA/exportação/PDF e do aceite funcional de negócio.

Não há autorização de go-live implícita neste documento.
