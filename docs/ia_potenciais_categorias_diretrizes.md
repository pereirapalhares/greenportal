# IA de potenciais por categoria

## Objetivo

Ao solicitar a análise de um requisito legal em `modulolegal_requisitoslegais.php`, a IA deve analisar o padrão de desempenho e gerar sugestões separadas para cinco categorias:

1. Licenças (`LICENCA`);
2. Medição e Monitoramento (`MONITORAMENTO`);
3. Treinamentos (`TREINAMENTO`);
4. Infraestrutura (`INFRAESTRUTURA`);
5. Documentos e controles (`CONTROLE`).

O tipo `CONTROLE` representa a tabela de documentos do sistema. Não deve ser criado ou referenciado um objeto físico chamado `controles`.

## Prompts

Os prompts são cadastrados por código na tabela `ia_prompts` pela migração `database/175_ia_potenciais_prompts_por_categoria.sql`:

- `ML_REQUISITO_LICENCA`;
- `ML_REQUISITO_MONITORAMENTO`;
- `ML_REQUISITO_TREINAMENTO`;
- `ML_REQUISITO_INFRAESTRUTURA`;
- `ML_REQUISITO_DOCUMENTOS`.

Cada solicitação executa os cinco prompts e restringe a resposta ao tipo esperado. A implementação mantém o processamento atual da IA e utiliza a estrutura unificada de potenciais existente, filtrando por `codsolicitacao` e `tipo_potencial`.

## Resultado e validação

`ia_potenciais_resultado.php` apresenta uma aba por categoria. Cada aba usa um iframe próprio e uma transação isolada:

- `ia_potenciais_licencas.php`;
- `ia_potenciais_medicao.php`;
- `ia_potenciais_treinamentos.php`;
- `ia_potenciais_infraestrutura.php`;
- `ia_potenciais_documentos.php`.

Os grids possuem descrição, justificativa, confiança, status, pesquisa, filtro, paginação e rolagem. Aprovar e rejeitar permanecem desabilitados até a seleção de uma linha elegível. A confirmação altera somente o potencial da solicitação autenticada.

## Segurança e isolamento

Cada iframe valida sessão, permissão `MODULOLEGAL_POTENCIAIS`, usuário da solicitação, empresa/tenant da conexão e categoria solicitada. A validação no servidor confirma novamente o potencial, a solicitação e o tipo antes de chamar `sp_ia_potencial_validar`.

## Próximo passo operacional

Aplicar a migração nos bancos autorizados antes de executar uma análise real. A aprovação de um potencial ainda é uma decisão do usuário; a conversão para os cadastros definitivos deve permanecer em fluxo explícito e auditável.
