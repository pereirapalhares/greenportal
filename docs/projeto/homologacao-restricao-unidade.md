# Homologação de Fornecedores — restrição por unidade (migração 316, 19/10/2026)

Complementa `claude/homologacao-fornecedores-etapas.md`. Fecha o ponto em aberto "Restrição por unidade do usuário interno (legado: usuariosxunidades) não aplicada".

## Regra do legado (levantada)
- Gestão de Fornecedores (`fo_fornecedor.php`) e árvore da Gestão de Funcionários: quem não é administrador vê só `CODUNIDADE in (usuariosxunidades do usuário)`. Na Crown, o administrador vê também os inativos.
- Portaria (foFornecedorxCriterios / foFuncionariosXCriterios): filtro por unidade fixo na consulta, vale até para o administrador.
- Relatórios gerenciais, histórico e cadastros: sem filtro por unidade. Pendências: por responsável.

## Decisões do usuário (19/10)
1. ADMINISTRADOR e ADM_DA_EMPRESA veem todos os fornecedores; os demais perfis internos ficam restritos.
2. Interno sem unidade não vê nenhum fornecedor (além dos que responde).
3. O responsável continua vendo os fornecedores pelos quais responde, mesmo de outra unidade (senão os links da Home abririam telas vazias).

## Implementação (c470)
- Modo de acesso: 0 vê tudo, 1 fornecedor externo (296), 2 interno restrito. PHP `HomologacaoFornecedorAcesso::modo()/modoBanco()/restrito()`; SQL `vw_homologacao_usuario_acesso` (usado sem sessão pelo envio semanal).
- `vw_homologacao_fornecedores_acesso_interno` (usuário × fornecedor): unidades (`vw_portaria_usuario_unidades`) ∪ responsável pelo fornecedor/integração ∪ responsável de documento, critério/treinamento de funcionário, plano de ação ∪ responsabilidade atribuída (312).
- Views de tela: `vw_homologacao_fornecedores_unidade_grid`, `_desativados_unidade_grid`, `vw_homologacao_funcionarios_unidade_grid`, `vw_homologacao_unidades_usuario_grid`; procedimento `sp_fo_acesso_interno_registro`.
- `sp_fo_painel_itens` (298) e `sp_fo_relatorio_itens/_contagens/_unificado` (314): `p_externo = 2`.
- Telas: Gestão de Fornecedores e desativados (gravação/exclusão conferidas; unidade gravada tem de ser do usuário), Gestão de Funcionários e abas, Painel, Ficha, Relatórios, planilhas (campo `modo` no pedido), envio semanal, GED (homologação segue a mesma regra; ADM_DA_EMPRESA passa a ver evidências de todas as unidades), questionário do fornecedor.
- Antes da 316 aplicada no banco tudo funciona como antes (`restricaoUnidadeAtiva`).
- Sincronismo diário: `usuariosxunidades` legado (`<legado>_seguranca`) → greenportal, legado prevalece, exclusões copiadas em `fo_sinc_exclusoes`; entra na validação.
- Sem mudança: Portaria (só ADMINISTRADOR vê tudo), Ranking e Histórico (sem filtro, como no legado), pendências da Home.

## Aplicação (19/10, 6 bancos, ok)
| Banco | vê tudo | externo | restritos | com unidade | sem nenhum fornecedor | forn. ativos só p/ admin |
|---|---|---|---|---|---|---|
| gmodelo | 1 | 0 | 1.942 | 997 | 883 | 0 |
| gatlas | 10 | 4 | 117 | 99 | 15 | 6 |
| gmodelo2 | 1 | 0 | 1.242 | 912 | 253 | 0 |
| gcrown | 9 | 0 | 1.116 | 983 | 116 | 1 |
| ggdamericana | 11 | 0 | 26 | 0 | 26 | 1 |
| gganchieta | 12 | 0 | 10 | 0 | 10 | 0 |

`usuariosxunidades` já existia nos 6 bancos com todos os pares do legado (0 copiados; diferenças de contagem = linhas repetidas ou com código 0 no legado). A 260 reaplicada passou a ler a tabela: a Portaria da Crown/MODELO2/Americana/Anchieta, que não mostrava nada aos não administradores, passa a funcionar por unidade.

## Em aberto
- Crown: perfis FORNECEDORES (692) e GESTORES (335) são tratados como internos restritos; confirmar se são usuários de fornecedor (deveriam ser FORNECEDOR / FORNECEDOR_GESTOR).
- Americana e Anchieta: nenhum interno restrito tem unidade (no legado também só 2 e 12 vínculos).
- Teste com usuário não administrador nas telas.
