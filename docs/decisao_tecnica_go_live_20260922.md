# Decisão técnica para continuidade da homologação — 22/09/2026

## Autorização recebida

O solicitante autorizou o uso do banco `gmodelo_dbp`, dos usuários de teste e a definição de premissas técnicas para continuidade da homologação. O segundo tenant poderá ser verificado posteriormente.

## Premissas adotadas

- A matriz atual de permissões será considerada baseline técnico:
  - ADMINISTRADOR: acesso e CRUD completos;
  - demais perfis: acesso somente onde a matriz atual permitir;
  - HTTP 403 sem permissão será considerado comportamento esperado.
- Retenção de exportações concluídas/erro: 30 dias.
- Retenção de alertas lidos: 90 dias.
- Exportações em processamento e alertas não lidos: preservados.
- A rotina de retenção continuará em `dry-run` até sua validação operacional.
- Os usuários autorizados serão tratados como perfis de homologação simulada.
- O isolamento entre empresas será validado posteriormente quando houver segundo tenant disponível.

## Resultado técnico consolidado

- CRUD de Condicionantes: inclusão, edição e exclusão técnica aprovadas; dados temporários removidos.
- Permissões: matriz consultada no banco e comportamento HTTP confirmado.
- Unidade Modelo: isolamento por unidade validado.
- Retenção: `dry-run` executado sem exclusões indevidas.
- Exportações, alertas, PDF, filtros, popups e responsividade: validações técnicas anteriores aprovadas.

## Estado de publicação

**Recomendação técnica: APTO SOB PREMISSAS, com risco residual de isolamento entre empresas.**

A publicação definitiva entre tenants fica condicionada à validação posterior do segundo tenant. Nenhum tenant foi ativado nesta decisão.

## Próxima execução

1. Manter o baseline de permissões e a política de retenção definidos acima.
2. Continuar registrando evidências de homologação simulada.
3. Validar o segundo tenant quando disponibilizado.
4. Atualizar a decisão após o teste de isolamento entre empresas.
