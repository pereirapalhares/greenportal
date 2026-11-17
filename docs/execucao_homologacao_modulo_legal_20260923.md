# Execução complementar de homologação — Módulo Legal

## Resultado

Rodada executada em 23/09/2026 no Chrome via CDP, com dados controlados e sem resíduos permanentes.

- LAIA: 4 popups contextuais aprovados;
- LPRO: 6 popups contextuais aprovados;
- LAIS: 3 popups contextuais aprovados;
- Controle: 9 relacionamentos e Responsabilidades aprovados;
- CRUD administrativo de LAIA, LPRO, LAIS e Controle: aprovado;
- arquivos de Licenças: popup GED aprovado;
- configuração de colunas: modal e salvamento disponíveis;
- exportação de Controle: job 57 concluído e arquivo XLSX gerado;
- paginação LPRO: página 1 → página 2 aprovada;
- filtro textual LPRO: aplicado e retornou 1 linha;
- registros sem unidade: 0 em LAIA, LPRO, LAIS e Licenças;
- isolamento Modelo/Modelo2: marcadores isolados e removidos; Modelo2 retornou a INATIVA;
- regressão de acessos: 49/49 rotas processadas, 0 erros de servidor.

## Correção aplicada

O worker de exportação não possuía configuração explícita para LPRO, LAIS, Controle e Meus Controles. Foram adicionadas as views e nomes funcionais correspondentes em `classes/ExportacaoGridWorker.php`. O job de Controle foi reprocessado e concluído.

## Permissões

A matriz efetiva foi confrontada no banco. ADMINISTRADOR possui CRUD completo nos sete menus. GESTOR possui visualização em Controle, Meus Controles e Minhas Infraestruturas, além de exportação de Minhas Licenças; os demais perfis permanecem bloqueados conforme a matriz atual.

## Evidências

- `tests/resultado-validacao-subitens-modulo-legal-20260923.json`;
- `tests/resultado-crud-laia-lpro-lais-controle-20260923.json`;
- `tests/resultado-popups-laia-lpro-lais-20260923.json`;
- `tests/evidencias-controle-documentos-cdp-20260923/`;
- `tests/evidencias-licencas-export-arquivos-20260923/`;
- `tests/evidencias-popups-laia-lpro-lais-20260923/`.

## Pendências residuais

1. Repetir mutações CRUD com perfis de negócio caso a regra de negócio libere edição para eles.
2. Executar exportação dedicada de cada tela após a correção do worker.
3. Formalizar aprovação funcional da matriz e do relatório pelos responsáveis de negócio.
