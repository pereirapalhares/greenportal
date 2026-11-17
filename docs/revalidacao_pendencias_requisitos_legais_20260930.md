# Revalidação das pendências — Requisitos Legais

Data: 30/09/2026  
Ambiente: Firefox 157.0 com GeckoDriver em `127.0.0.1:4444`  
Tenant: Modelo (`gmodelo_dbp`)  
Contexto: requisito `LB0001`, legislação `8`, unidade `1`.

## Validações técnicas concluídas

- Acesso pelo menu principal e carregamento da tela `modulolegal_requisitoslegais.php`.
- Seleção de requisito legal.
- Abertura dos popups contextuais.
- Fechamento dos popups.
- Remoção dos iframes contextuais após o fechamento.
- Abertura visual do modal PDF pelo botão `Texto da Norma`.
- Renderização do canvas PDF.
- Presença do botão de download.
- Fechamento do modal PDF.

## Correção aplicada

O arquivo `assets/js/popups.js` passou a limpar o conteúdo do popup transacional no evento `hidden.bs.modal`. Com isso, o iframe contextual não permanece no DOM após o fechamento. A versão do recurso foi atualizada para `popups.js?v=6`.

## Pendências que não podem ser executadas pelo agente

Permanecem aguardando manifestação formal dos responsáveis:

1. Aceite funcional do negócio.
2. Aprovação da matriz de permissões.
3. Aprovação do relatório de homologação.
4. Aprovação e agendamento da retenção por Infraestrutura/DBA.
5. Confirmação de ambiente semelhante à produção.
6. Confirmação da janela oficial de publicação.
7. Ordem explícita para executar o go-live.
8. Monitoramento e relatório pós-publicação.

Nenhuma aprovação foi declarada em nome dos responsáveis e nenhuma publicação real foi executada.

Evidência: `tests/resultado-pendencias-requisitos-firefox-20260930.json` e `tests/evidencia-firefox-pdf-requisitos-20260930.png`.
