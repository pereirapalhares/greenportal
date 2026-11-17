# Taxímetro de IA — migração 340 (c551, ajustes de tela c552, 23/10/2026)

## Decisões do usuário
- Medir o uso de IA em todo o sistema, separado **por empresa** (cada banco g*_dbp), por usuário e por módulo, com custo em R$.
- **Limite mensal por empresa**: só o perfil **ADMINISTRADOR** altera; o **ADM DA EMPRESA** só consulta.
- Ao atingir o limite: aviso aos ADM DA EMPRESA por alerta e e-mail, e o aumento é negociado.
- Ao estourar: **bloqueia** os novos pedidos de IA, com **folga de 10%** antes do bloqueio. Os pedidos que já estão na fila terminam.
- Câmbio automático pela **PTAX** do Banco Central.
- **Estimativa** dos pedidos anteriores ao taxímetro, marcada como "estimado".
- Novo item no menu Administração: **Consumo de IA**.
- (c552) A tela segue o padrão do projeto, com abas. O **Excel** vai pelo serviço de exportação (alerta). O **PDF** abre em popup do portal, com a opção de baixar.

## Como funciona
- **Ponto de medição:** as três classes que chamam a OpenAI (`InteligenciaArtificialDocumento`, `InteligenciaArtificialAssistente`, `InteligenciaArtificialLicenca`) chamam `IaTaximetro::registrar()` com o `usage` da resposta. O registro inclui entrada, cache e saída, e também os erros.
- **Contexto:** quem chama a IA informa o contexto com `IaTaximetro::contexto()`:
  - a fila (`DocumentAiQueueService`) informa tipo, registro, codjob, usuário e origem FILA;
  - o assistente informa tipo ASSISTENTE;
  - a solicitação de melhoria informa tipo SOLICITACAO_MELHORIA.
- **Custo:** `(entrada − cache) × preço de entrada + cache × preço de cache + saída × preço de saída`, dividido por 1 milhão. O resultado é multiplicado pela PTAX venda do dia (ou do último dia útil) e pelo acréscimo opcional de IOF/spread. O custo fica gravado no dia da chamada.
- **Preço do modelo:** vale o nome igual ou o nome seguido de "-" (versão datada). Sem preço próprio, vale a linha `*` (preço padrão).
- **Bloqueio:** `IaTaximetro::exigirDisponivel()` é chamado em:
  - `DocumentAiQueueService::enqueue`;
  - o assistente;
  - a solicitação de melhoria;
  - a eficácia de NC, antes do OCR.

  Ele lança `IaLimiteExcedido` quando a situação é BLOQUEADO (consumo ≥ limite × (1 + folga)). A eficácia que já esperava o OCR passa `ignorar_limite`.
- **Serviço `iaconsumo`** (tarefa **GreenPortal-IaConsumo**, a cada 30 minutos):
  - grava a PTAX no universal;
  - chama `sp_ia_limite_avisar` em cada empresa:
    - **AVISO** vai só para os ADM DA EMPRESA;
    - **LIMITE** e **BLOQUEIO** vão também para o ADMINISTRADOR;
    - o envio é por alerta e e-mail, uma vez por faixa, mês e limite.

## Banco
- **Universal:**
  - `ia_precos`, `ia_cambio`, `vw_ia_precos`, `vw_ia_cambio`;
  - `sp_ia_preco_salvar`;
  - `sp_ia_cambio_gravar`: a fonte MANUAL não é trocada pela PTAX.
- **Empresa:**
  - tabelas: `ia_consumo`, `ia_limite_config`, `ia_limite_extras`, `ia_limite_historico`, `ia_limite_avisos`;
  - views: `vw_ia_consumo`, `vw_ia_consumo_mensal`, `vw_ia_limite_config`, `vw_ia_limite_situacao`, `vw_ia_limite_*`;
  - procedures: `sp_ia_consumo_registrar`, `sp_ia_limite_salvar`, `sp_ia_limite_extra_incluir`, `sp_ia_limite_avisar(simular)`.
- **Estimativa:** jobs CONCLUIDA (caracteres / 4) e respostas do assistente (tokens gravados); roda uma vez só e é idempotente.
- **Lição de collation:** comparar uma coluna com um parâmetro ou variável de procedure usa `COLLATE utf8mb4_0900_ai_ci` explícito.

## Tela: Administração › Consumo de IA (`principal/administracao_consumo_ia.php`)
- **Barra padrão** (`greenPortalCrudButtons`, grupos Operações | Outros): Recarregar, Exportar Excel, Ajuda, PDF e o seletor do mês.
  - A barra fica num contêiner só dela: o toolbar-grupos.js junta os botões do contêiner pai da `.crud-toolbar`; com o `<body>` como pai, os botões dos grids das abas iam parar nela.
- **Abas:**
  - Resumo: indicadores, o mês escolhido e os últimos 12 meses;
  - Por usuário;
  - Por módulo;
  - Chamadas: até 5.000; a chamada de NC abre a ocorrência;
  - Limite, com sub-abas Limite e crédito extra | Histórico | Avisos enviados;
  - Preços e câmbio, com sub-abas Preços da OpenAI | Cotação do dólar;
  - Todas as empresas: só ADMINISTRADOR.
- O endereço `#abaX` ou `#subX` abre a aba ou sub-aba.
- **Excel:**
  - `api/ia_consumo.php` com `acao=exportar` (perfil com Exportar Excel) chama `ExportacaoSolicitacao`, que cria o alerta "Exportação iniciada";
  - o GreenPortal-ExportWorker gera a planilha: `ExportacaoGridWorker`, special `consumo_ia`, `classes/IaConsumoExportacao.php`, com as chamadas do mês e o título "Consumo de IA — empresa — mm/aaaa";
  - o alerta "Exportação concluída" traz o arquivo.
- **PDF:** abre no popup do portal (`GreenPortalPopups.transacaoIndependente`), com o botão **Baixar PDF** (`&baixar=1` = attachment) e o nome da empresa no cabeçalho.
- **Gravações:** `principal/api/ia_consumo.php`; manutenção só pelo perfil ADMINISTRADOR.

## Preços carregados (US$ por 1M tokens: entrada / cache / saída, vigentes desde 01/01/2024)
| Modelo | Entrada | Cache | Saída |
| --- | --- | --- | --- |
| `*` | 0,15 | 0,075 | 0,60 |
| gpt-4o-mini | 0,15 | 0,075 | 0,60 |
| gpt-4o | 2,50 | 1,25 | 10 |
| gpt-4.1-nano | 0,10 | 0,025 | 0,40 |
| gpt-4.1-mini | 0,40 | 0,10 | 1,60 |
| gpt-4.1 | 2 | 0,50 | 8 |
| gpt-5-nano | 0,05 | 0,005 | 0,40 |
| gpt-5-mini | 0,25 | 0,025 | 2 |
| gpt-5 | 1,25 | 0,125 | 10 |

O usuário conferiu os preços em 23/10.

## Situação
- **Migração 340** aplicada nos 6 bancos em 23/10:
  - PTAX com 13 cotações;
  - Atlas: 15 respostas do assistente, R$ 0,78;
  - Crown: 6 pedidos estimados, R$ 0,31.
- Tarefa GreenPortal-IaConsumo registrada.
- O modelo configurado é o **gpt-4o**.
- O OCR é local, sem custo. As solicitações de melhoria anteriores ao taxímetro não entram na estimativa.
