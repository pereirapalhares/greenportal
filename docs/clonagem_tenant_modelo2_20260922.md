# Clonagem do tenant Modelo2 — 22/09/2026

## Execução

- Backup lógico criado: `runtime/backups/gmodelo_dbp_pre_clone_20260922.sql`.
- Banco original: `gmodelo_dbp`, aproximadamente 34,7 MB e 415 tabelas.
- Banco clonado: `gmodelo2_dbp`, aproximadamente 28,2 MB e 415 tabelas.
- Empresa cadastrada em `guniversal_dbp`: `Modelo2` / `gmodelo2_dbp`.
- Estado inicial da empresa: `INATIVA`, para impedir uso antes da validação de isolamento.

## Validação de equivalência

| View | Modelo | Modelo2 |
|---|---:|---:|
| `vw_modulolegal_requisitos_grid` | 1.523 | 1.523 |
| `vw_licencas_grid` | 46 | 46 |
| `vw_licencas_condicionantes_grid` | 17 | 17 |

## Próximo passo

Ativar temporariamente `Modelo2` somente durante a janela de homologação, executar o teste de isolamento com usuários autorizados e retornar para `INATIVA` após a validação, salvo autorização diferente.
