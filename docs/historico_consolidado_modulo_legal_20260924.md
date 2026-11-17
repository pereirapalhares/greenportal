# Histórico consolidado do Módulo Legal — 24/09/2026

**Projeto:** GreenPortal  
**Empresa principal:** Modelo  
**Banco principal:** `gmodelo_dbp`  
**Segundo tenant:** Modelo2 — `gmodelo2_dbp`  
**Status:** validações técnicas concluídas; go-live ainda não autorizado.

## 1. Objetivo e escopo

Este documento consolida as atividades realizadas no Módulo Legal, as evidências disponíveis, o estado dos tenants e as pendências que precisam ser encerradas antes da publicação oficial.

Os testes foram controlados e executados com os usuários autorizados `admin`, `ueditor`, `urevisor`, `uaprovador`, `gestor`, `responsavel` e `visitante`, usando a senha de teste `<senha de teste via variável de ambiente>`. O Chrome foi utilizado via CDP em `http://127.0.0.1:9222`.

Não há aprovação formal declarada neste documento em nome de responsáveis. Os resultados abaixo registram evidências técnicas e não substituem aceite de negócio, autorização de release ou aprovação da Infraestrutura/DBA.

## 2. Entregas e validações concluídas

### Funcionalidades do Módulo Legal

- CRUD administrativo de LAIA, LPRO, LAIS e Controle validado com persistência e limpeza dos registros temporários.
- Popups e relacionamentos de LAIA, LPRO, LAIS e Controle validados.
- Meus Controles, Minhas Licenças e Minhas Infraestruturas validados.
- Filtros, paginação e tratamento de registros sem unidade testados.
- Exportações do Módulo Legal processadas, incluindo a validação do worker para LPRO, LAIS, Controle e Meus Controles.
- Alertas, jobs e arquivos XLSX temporários de teste verificados e removidos.
- Tentativas POST negativas foram bloqueadas nos perfis sem permissão.

### Segurança, permissões e contexto

A matriz técnica final foi exercitada com sete logins, 49 rotas e os seguintes resultados:

| Indicador | Resultado |
|---|---:|
| Logins testados | 7 |
| Rotas processadas | 49 |
| Acessos permitidos | 14 |
| HTTP 403 esperados | 35 |
| Erros de servidor | 0 |
| Divergências de contexto | 0 |
| Violações de CRUD | 0 |

Os resultados confirmam o comportamento técnico observado para os perfis testados. A aprovação formal da matriz de permissões continua pendente.

### Isolamento entre tenants

- O banco principal é `gmodelo_dbp`, associado à empresa Modelo.
- O segundo tenant é `gmodelo2_dbp`, associado à empresa Modelo2.
- O isolamento foi testado com marcadores temporários inseridos nos tenants correspondentes.
- Cada marcador foi encontrado somente no próprio tenant.
- Os marcadores foram removidos ao final dos testes.
- Modelo2 retornou para o estado `INATIVA` e deve permanecer inativa fora de novos testes autorizados.

## 3. Limpeza e integridade pós-teste

Ao final da execução:

- registros temporários de CRUD foram removidos;
- marcadores usados no teste de isolamento foram removidos;
- oito arquivos XLSX temporários de teste foram removidos;
- alertas e jobs temporários foram tratados;
- não foram identificados resíduos temporários previstos no escopo da validação.

Não foram feitas exclusões das exportações antigas elegíveis para retenção.

## 4. Backups e reversão

Backups disponíveis:

- Backup principal: `runtime/backups/gmodelo_dbp_pre_clone_20260922.sql`.
- Backup do clone: `runtime/backups/gmodelo2_dbp_clone_20260922.sql`.

O plano de reversão deve preservar Modelo2 como `INATIVA` e restaurar o banco principal a partir do backup aprovado, se essa ação for formalmente determinada durante uma janela autorizada.

## 5. Retenção de exportações e alertas

O dry-run da retenção foi executado nos dois bancos:

| Banco | Exportações antigas elegíveis | Alertas elegíveis | Exclusões realizadas |
|---|---:|---:|---:|
| `gmodelo_dbp` | 15 | 0 | 0 |
| `gmodelo2_dbp` | 15 | 0 | 0 |

As 15 exportações elegíveis de cada banco permanecem preservadas. Nenhuma exclusão deve ser realizada sem aprovação formal da política e agendamento pela Infraestrutura/DBA. A rotina deve continuar sendo tratada inicialmente em `dry-run`; a execução efetiva requer autorização explícita e registro operacional.

## 6. Evidências e documentos relacionados

Documentos principais:

- [`docs/historico_completo_modulo_legal_20260923.md`](historico_completo_modulo_legal_20260923.md)
- [`docs/execucao_homologacao_modulo_legal_20260923.md`](execucao_homologacao_modulo_legal_20260923.md)
- [`docs/relatorio_pre_go_live_20260923.md`](relatorio_pre_go_live_20260923.md)
- [`docs/plano_final_go_live_20260923.md`](plano_final_go_live_20260923.md)
- [`docs/retencao_exportacoes_alertas_diretrizes.md`](retencao_exportacoes_alertas_diretrizes.md)

Resultados e evidências:

- `tests/resultado-pre-go-live-final-20260923.json`
- `tests/resultado-crud-laia-lpro-lais-controle-20260923.json`
- `tests/resultado-popups-laia-lpro-lais-20260923.json`
- `tests/resultado-exportacoes-modulo-legal-20260923.json`

## 7. Pendências antes do go-live

As seguintes pendências permanecem abertas:

1. Obter aprovação formal da matriz de permissões.
2. Obter aprovação formal do relatório.
3. Obter aprovação e agendar a retenção com Infraestrutura/DBA.
4. Revalidar o comportamento no ambiente oficial ou em ambiente semelhante à produção.
5. Confirmar formalmente a janela de publicação.
6. Receber ordem explícita para executar o go-live no início da janela autorizada.
7. Executar monitoramento pós-publicação e emitir o relatório correspondente.

Enquanto essas pendências não forem encerradas, o sistema deve ser considerado tecnicamente validado sob premissas, mas não liberado para publicação real.

## 8. Regras para a publicação

- Nenhuma publicação real deve ser executada sem ordem explícita no início da janela autorizada.
- Nenhuma das 15 exportações antigas elegíveis por banco deve ser excluída antes da aprovação da retenção.
- Modelo2 deve permanecer `INATIVA` fora de testes formalmente autorizados.
- Qualquer ocorrência no monitoramento pós-publicação deve ser registrada junto com horário, tenant, usuário, rota, impacto e ação de reversão adotada.

## 9. Estado consolidado

As validações técnicas do Módulo Legal e do isolamento entre Modelo e Modelo2 foram concluídas dentro do escopo registrado. Não há autorização formal de go-live registrada neste documento, não houve publicação real e as sete pendências acima continuam condicionando a liberação.

## 10. Alteração posterior — tooltips das tabs

Em 24/09/2026 foi ajustado `assets/js/main.js` para remover somente as mensagens flutuantes das tabs abertas, incluindo `Fechar aba`. O botão de fechamento continua funcionando e mantém seu `aria-label` para acessibilidade. Os tooltips dos demais componentes foram preservados.

Detalhes e validação: [`docs/alteracao_tooltips_tabs_20260924.md`](alteracao_tooltips_tabs_20260924.md).
