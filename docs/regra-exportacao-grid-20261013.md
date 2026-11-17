# Regra de exportação baseada no grid

Implementação concluída:

- O navegador envia os filtros ativos, a busca, as colunas visíveis e a ordem atual do grid.
- O worker exporta todos os registros que correspondem aos filtros, não somente a página atual.
- Os rótulos usados no XLSX correspondem aos rótulos visíveis no cabeçalho.
- Para `seguranca_usuarios`, o servidor remove sempre o campo `senha`, mesmo que o navegador o envie entre as colunas visíveis.
- Campos relacionais de usuários são normalizados: `Perfil` usa `perfil_nome`, `Grupos` usa `grupo_nome` e `Usuário superior` usa `usuario_superior_nome`. `Ativo` é exportado como `Sim`/`Não`.
- A regra foi aplicada ao transporte padrão dos grids administrativos de menu, grupos, usuários, perfis, permissões, formulários e listas.

Validação:

- Payload Firefox: filtro `userid=admin` e coluna `nome` removida do grid não foram exportados.
- XLSX de usuários: campo `senha` ausente mesmo quando foi incluído artificialmente no payload.
