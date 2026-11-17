# CRUD restrito de prompts de IA

## Transacao

A transacao SETUP_IA_PROMPT apresenta a tabela ia_prompts por meio da view
vw_ia_prompts_grid. Ela permite somente a alteracao de registros existentes.
Inclusao e exclusao permanecem desabilitadas por decisao funcional.

## Padrao de grid

- A barra padrao de acoes fica acima do grid.
- Acoes especificas sao Salvar e Historico.
- Busca geral, filtros por coluna, ordenacao, redimensionamento e colunas
  seguem os componentes comuns de grid.
- Nenhum botao de acao fica dentro das linhas ou celulas.
- Os textos de colunas, busca e mensagens respeitam PT, EN e ES.

## Persistencia e seguranca

- A leitura usa sp_crud_select sobre views autorizadas.
- A alteracao usa exclusivamente sp_ia_prompt_atualizar.
- O PHP nao executa SELECT, INSERT, UPDATE ou DELETE diretamente.
- A alteracao exige permissao pode_editar da transacao.
- O token CSRF e validado nas operacoes POST.
- Cada alteracao valida os tres idiomas e preserva o historico no banco.

## Historico

O botao Historico consulta vw_ia_prompts_historico_grid por sp_crud_select e
abre o resultado em popup, mantendo a tela principal.
