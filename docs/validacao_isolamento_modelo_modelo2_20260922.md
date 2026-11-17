# Validação de isolamento Modelo x Modelo2 — 22/09/2026

## Execução

- `Modelo2` foi ativada temporariamente.
- Os sete usuários autorizados conseguiram autenticar no tenant Modelo2.
- Foram verificadas 42 rotas: ADMINISTRADOR recebeu HTTP 200 nos seis fluxos; os demais perfis receberam HTTP 403 sem erros de servidor.
- Foram inseridos marcadores controlados de Condicionantes em ambos os bancos, um marcador por tenant.
- Cada marcador foi localizado somente no próprio banco durante a verificação.
- Ao final, os dois marcadores foram removidos.
- `Modelo2` foi retornada para `INATIVA`.

## Comparação final

| Indicador | Modelo | Modelo2 |
|---|---:|---:|
| Requisitos | 1.523 | 1.523 |
| Licenças | 46 | 46 |
| Condicionantes | 17 | 17 |
| Unidades | 16 | 16 |
| Usuários | 2.526 | 2.526 |
| Marcadores de teste restantes | 0 | 0 |

## Conclusão

O isolamento técnico entre os bancos foi aprovado para os cenários executados. Não houve vazamento do marcador entre tenants e não restaram dados temporários.

Evidências visuais: `tests/evidencias-homologacao-modelo2-20260922`  
Resultado: `tests/resultado-homologacao-modelo2-20260922.json`  
Controle: `scripts/controle-isolamento-modelo2.php`
