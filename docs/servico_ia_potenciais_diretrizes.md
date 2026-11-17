# Serviço de IA para potenciais do Módulo Legal

## Objetivo

Esta é a primeira versão da infraestrutura para sugerir potenciais de gestão
a partir de requisitos legais. O serviço não cria diretamente registros
definitivos de Licenças, Monitoramentos, Treinamentos, Infraestrutura,
Controles ou Influências. Ele cria sugestões que serão revisadas pelo usuário.

## Arquitetura

O portal registra a solicitação na fila `ml_potenciais_solicitacoes`. O serviço
residente `services/InteligenciaArtificialService.php` consulta a fila, envia o
contexto ao provedor configurado e grava o resultado por procedure na tabela
`ml_potenciais`.

Por padrão, o serviço atende somente `127.0.0.1:8788`:

- `GET /health`: verifica se o serviço está ativo;
- `GET /processar`: força uma tentativa imediata de processamento.

O serviço é instalado como tarefa residente do Windows por
`scripts/instalar-servico-ia.ps1`, seguindo o mesmo padrão do serviço de
exportação Excel.

O worker é multiempresa: a cada ciclo consulta `guniversal_dbp.empresa`,
seleciona somente empresas com `status='ATIVA'` e processa a fila de cada
`banco_dados` com uma conexão independente. Empresas INATIVAS não são
processadas. Se o banco central estiver indisponível, o serviço registra o
erro e usa temporariamente o banco definido em `GPT_DB_DATABASE` como
fallback operacional.

## Banco de dados

A estrutura está em `database/138_potenciais_ia.sql` e deve ser aplicada em
cada banco de empresa `gmodelo_dbp`.

Tabelas:

- `ml_potenciais_solicitacoes`: fila, status, idioma, horários e erros;
- `ml_potenciais`: sugestões retornadas pela IA, confiança, idioma e status.

Views:

- `vw_ml_potenciais_solicitacoes_grid`;
- `vw_ml_potenciais_grid`.

Procedures:

- `sp_ia_potencial_solicitar`;
- `sp_ia_potencial_solicitar_com_alerta`;
- `sp_ia_potencial_proxima_solicitacao`;
- `sp_ia_potencial_concluir`.

## Fluxo operacional integrado ao requisito legal

O usuário solicita a análise diretamente em `modulolegal_requisitoslegais.php`,
selecionando um requisito e acionando o botão localizado acima do grid. A
confirmação é feita por popup.

1. O portal chama `sp_ia_potencial_solicitar_com_alerta`, que registra a fila
   e cria o alerta inicial com link de acompanhamento.
2. `InteligenciaArtificialService.php` processa a fila em segundo plano e
   mantém status, erro e horários em `ml_potenciais_solicitacoes`.
3. `sp_ia_potencial_concluir` grava as sugestões e cria o alerta final com
   data/hora e link para `principal/ia_potenciais_resultado.php`.
4. O link abre o resultado em popup. ADMINISTRADOR e ADM_DA_EMPRESA podem
   aprovar/rejeitar; GESTOR somente visualiza.

O menu `MODULOLEGAL_POTENCIAIS` é desativado pela migração
`database/140_potenciais_ia_fluxo_requisitos.sql`; a fila e o serviço
continuam ativos como infraestrutura interna.

O PHP não executa `INSERT`, `UPDATE`, `DELETE` ou `SELECT` de negócio. O
serviço utiliza exclusivamente as procedures. A conversão de um potencial
aprovado para o módulo definitivo será implementada em uma etapa posterior,
também por procedure e com auditoria.

## Idiomas

O idioma da solicitação deve ser `pt`, `en` ou `es`; qualquer valor inválido
usa PT como fallback. O idioma é enviado à IA e gravado em
`idioma_conteudo`. O conteúdo gerado é tratado como conteúdo do negócio e não
é traduzido automaticamente quando o usuário troca o idioma da interface.
Labels, mensagens, status e tooltips da interface deverão utilizar as três
traduções e fallback para PT, conforme `docs/diretrizes-projeto.txt`.

## Configuração

Adicionar ao `.env`, sem publicar a chave:

```text
GPT_AI_SERVICE_HOST=127.0.0.1
GPT_AI_SERVICE_PORT=8788
GPT_AI_SERVICE_POLL_SECONDS=5
GPT_AI_API_URL=https://provedor-compativel/v1
GPT_AI_API_KEY= chave-secreta
GPT_AI_MODEL=modelo-configurado
GPT_AI_CREDENTIALS_FILE=E:\\wamp\\apps\\greengde\\config\\app.php
```

Quando `GPT_AI_API_KEY` e `GPT_AI_MODEL` não forem informados no `.env`, o
serviço utiliza `GPT_AI_CREDENTIALS_FILE`. Por padrão, ele procura o arquivo
`E:\\wamp\\apps\\greengde\\config\\app.php`, lê `OPENAI_API_KEY` e
`OPENAI_MODEL` e chama `https://api.openai.com/v1/chat/completions`. A chave
permanece somente no processo PHP residente e nunca é enviada ao navegador.

Sem URL ou chave configurada, a solicitação é encerrada com status `ERRO` e
mensagem controlada; nenhuma sugestão é criada.

## Segurança e operação

- A porta deve permanecer local e não ser publicada na internet.
- A chave da IA fica exclusivamente no `.env` do servidor.
- O retorno deve ser JSON no contrato definido pelo serviço.
- Falhas de comunicação não criam registros parciais.
- Toda solicitação mantém status, horários, processador e erro.
- O usuário deverá aprovar ou rejeitar cada sugestão antes da conversão.

## Estado da primeira tela

O fluxo operacional atual ocorre diretamente em `modulolegal_requisitoslegais.php`.
O menu `MODULOLEGAL_POTENCIAIS` foi desativado pela migração
`database/140_potenciais_ia_fluxo_requisitos.sql`; a tela antiga permanece
somente como legado técnico.

O menu `MODULOLEGAL_POTENCIAIS` e a tela `modulolegal_potenciais.php` foram
criados. A tela possui:

- seleção de requisito legal e solicitação de análise;
- controle de duplicidade para solicitações pendentes ou em processamento;
- grid de solicitações com status, idioma, horários e erro;
- grid de potenciais sugeridos;
- seleção de um potencial no grid;
- aprovação e rejeição por botões acima do grid;
- popup de confirmação para aprovação e rejeição;
- permissões iniciais: ADMINISTRADOR e ADM_DA_EMPRESA alteram; GESTOR
  visualiza.

## Manutenção dos prompts

O menu `SETUP_IA_PROMPT`, disponível em Configurações, permite ao
`ADMINISTRADOR` alterar somente prompts já cadastrados. Cada prompt possui
obrigatoriamente uma versão em PT, EN e ES. A tela não oferece inclusão ou
exclusão.

As tabelas `ia_prompts` e `ia_prompts_historico` são mantidas pelas procedures
`sp_ia_prompt_obter` e `sp_ia_prompt_atualizar`. Antes de cada atualização, a
versão anterior e as três traduções são gravadas no histórico. O serviço
consulta o prompt ativo conforme o idioma da solicitação.

## Próximas etapas

1. Criar testes visuais e não visuais para PT, EN e ES.
2. Implementar popup de detalhes do potencial.
3. Implementar a conversão por tipo de potencial.
4. Criar o vínculo automático com o requisito legal e o responsável.
