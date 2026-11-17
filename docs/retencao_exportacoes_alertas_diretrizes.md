# Retenção de exportações e alertas

## Política aprovada para implementação

- Exportações com status `CONCLUIDA` ou `ERRO`: retenção de 30 dias após `concluido_em` (ou `solicitado_em` quando não houver conclusão).
- Arquivos físicos somente podem ser removidos quando estiverem dentro de `exports/` da aplicação.
- Exportações `PENDENTE` ou `PROCESSANDO` nunca são removidas pela rotina.
- Alertas: retenção de 90 dias somente para alertas já lidos (`lido = 1`). Alertas não lidos permanecem disponíveis.
- A limpeza é executada por tenant, com conexão ao banco do tenant, e deve ser agendada fora do horário de pico.

## Operação

O script `scripts/manutencao-retencao-exportacoes-alertas.php` inicia em modo simulação:

```powershell
php scripts/manutencao-retencao-exportacoes-alertas.php
```

Após revisão do resultado, a exclusão pode ser autorizada explicitamente:

```powershell
php scripts/manutencao-retencao-exportacoes-alertas.php --execute
```

Parâmetros opcionais: `--export-days=30`, `--alert-days=90` e `--database=gmodelo_dbp`.
O modo `--execute` deve ser registrado no log do agendador e validado primeiro em homologação.
