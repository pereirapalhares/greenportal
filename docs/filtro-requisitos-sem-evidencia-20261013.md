# Filtro de requisitos sem evidência

Implementação concluída em 13/10/2026.

## Alterações

- O filtro **Requisitos sem evidência** foi incluído em `modulolegal_requisitoslegais.php`, ao lado de **Lei revogada**.
- O filtro possui as opções Todos, Sim e Não.
- A regra considera vínculos de licença, controle, treinamento, infraestrutura e medição/monitoramento.
- Um requisito é marcado como sem evidência quando existe um vínculo do tipo correspondente para a legislação e unidade, mas não existe vínculo documental ativo no GED para aquela entidade.
- A tela `principal/requisitos_sem_evidencia.php` foi removida.
- O menu `REQUISITOS_SEM_EVIDENCIA` foi desativado e suas permissões foram removidas.
- O exportador de Requisitos legais passou a usar a mesma view filtrável do grid.

## Estrutura técnica

Foi criada a view `vw_modulolegal_requisitos_filtro_grid`, que preserva as colunas do grid e acrescenta internamente `SEM_EVIDENCIA` como campo numérico de filtro (`1` ou `0`). O campo não é exibido como coluna; serve apenas para seleção e contagem.

Aplicada a migração 232 nos bancos `gatlas_dbp`, `gmodelo_dbp` e `gmodelo2_dbp`.

## Validação

- `gatlas_dbp`: 436 requisitos sem evidência.
- `gmodelo_dbp`: 27 requisitos sem evidência.
- `gmodelo2_dbp`: 23 requisitos sem evidência.
- Filtro `1`: contagem e amostra retornaram somente linhas com `SEM_EVIDENCIA=1`.
- Filtro `0`: contagem executada sem erro.
- Menu antigo confirmado como inativo.
- Sintaxe PHP validada nos arquivos alterados.
