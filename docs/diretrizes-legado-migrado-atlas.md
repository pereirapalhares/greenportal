# Diretrizes para o legado migrado da Atlas

## Autorização

Na migração da Atlas, cada registro de `green_atlas_seguranca.grupos` deve originar um perfil correspondente em `gatlas_dbp.perfis`. O nome e a descrição do perfil devem preservar `DSCGRUPO`, e o `CODGRUPO` deve permanecer registrado em `atlas_perfis_legado`.

Quando já existir um perfil oficial com o mesmo significado, ele deve ser reutilizado. Assim, o grupo legado `ADMINISTRADOR` usa o perfil `ADMINISTRADOR`, `ADM DA EMPRESA` usa `ADM_DA_EMPRESA` e `FORNECEDOR` usa `FORNECEDOR`. Não devem permanecer dois perfis ativos para representar o mesmo grupo legado.

As permissões são derivadas de `gruposxmenus`. Cada relação grupo–menu deve ser mapeada para o menu equivalente do GreenPortal e gravada em `perfil_menus` e `permissoes`. O vínculo é feito por código ou identificador funcional do menu, nunca por suposição de que os identificadores numéricos sejam iguais.

Usuários com um grupo recebem o perfil correspondente. Usuários com vários grupos recebem um perfil efetivo individual contendo a união das permissões dos grupos, evitando tanto perda de acesso quanto concessão de uma permissão de grupo a usuários que não a possuem. As associações originais ficam registradas em `atlas_usuarios_perfis_legado`.

Permissões sem menu correspondente não podem ser liberadas por aproximação. Elas devem ser registradas como `NAO_MAPEADA` em `atlas_permissoes_legado`, incluídas no relatório de homologação e tratadas antes da ativação do tenant.

Na primeira carga da Atlas, foram encontrados 667 vínculos grupo–menu e nenhum catálogo de menus legado disponível para relacioná-los às rotas atuais. Portanto, a carga cria os 11 perfis de grupo, atribui os usuários e preserva os vínculos, mas a migração de permissões permanece `PENDENTE` até o catálogo funcional ser fornecido ou os menus do GreenPortal serem relacionados formalmente aos `CODMENU` legados.

## Isolamento e rastreabilidade

Os perfis e permissões são criados exclusivamente no banco do tenant `gatlas_dbp`. O login resolve o tenant pelo cadastro central e nunca recebe livremente o nome do banco. A migração deve ser idempotente, registrar origem, destino, versão, quantidades, status e detalhes em `registros_migracao`, e preservar as tabelas auxiliares de rastreabilidade.

 Antes de ativar a Atlas, devem ser comparados o acesso efetivo legado e o acesso efetivo no GreenPortal para cada usuário, grupo, menu e operação. O administrador pode manter acesso total conforme a política do ambiente, mas isso não substitui a migração dos perfis legados.

## Reflexao de grupos e permissoes

O script `scripts/corrigir-permissoes-legado-atlas.php` garante que os 11 grupos de `green_atlas_seguranca` tenham perfis correspondentes em `gatlas_dbp`, preservando a descricao do grupo. Os perfis oficiais `ADMINISTRADOR`, `ADM_DA_EMPRESA` e `FORNECEDOR` sao reutilizados; os demais usam identificadores tecnicos `ATLAS_GRUPO_<CODGRUPO>` com o mesmo nome exibido.

Cada vinculo de `gruposxmenus` e registrado em `atlas_permissoes_legado`. Quando o caminho da `vgruposxmenus` encontra um menu atual, a permissao de visualizacao e gravada em `perfil_menus` e `permissoes`. Quando nao encontra, permanece como `NAO_MAPEADA`; o sistema nao concede acesso por aproximacao.

Na execucao de 04/10/2026 foram refletidos 108 vinculos e preservados 650 como `NAO_MAPEADA`, pois o catalogo legado de menus nao esta disponivel como tabela/rota no banco de origem. O mapeamento considera o caminho completo do menu, evitando associar nomes genericos como `Perigos` e `Riscos` a modulos diferentes. O registro `ATLAS_GRUPOSXMENUS_REFLETIDOS` fica `PENDENTE` ate que esses menus sejam formalmente relacionados ao catalogo atual.

Na conferencia de `jennifer.silva`, os grupos legados sao `ADM DA EMPRESA`, `FORNECEDOR`, `HOMOLOGACAO` e `SPONSOR`. O perfil efetivo `ATLAS_USUARIO_206` possui 24 menus visiveis e nenhum item de `Manutencao Legal`, em conformidade com o grupo `ADM DA EMPRESA` legado.

## Associacao de usuarios com varios grupos

Para usuarios pertencentes a mais de um grupo, a estrutura oficial e `atlas_usuarios_perfis_legado`. Ela possui uma linha para cada relacao usuario--grupo--perfil e deve ser considerada a fonte da autorizacao efetiva. O campo singular `usuarios.codperfil` permanece preenchido com o primeiro perfil do legado apenas para compatibilidade com telas e rotinas antigas; ele nao substitui as demais associacoes.

Na sessao do usuario, as permissoes de todos os perfis associados sao unificadas por menu, preservando a permissao mais abrangente existente em qualquer grupo. Assim, o usuario legado `beatriz.rocha` fica associado aos grupos e perfis `FORNECEDOR` e `HOMOLOGACAO`, cada um mantendo exclusivamente as permissoes originadas de seu respectivo `gruposxmenus`.

## Excecao de acesso efetivo

Quando houver uma necessidade formal de restringir um usuario especifico sem apagar seus grupos legados, `atlas_usuarios_perfis_legado.efetivo = 1` identifica um perfil efetivo personalizado. A sessao usa somente esse perfil, enquanto as associacoes originais permanecem preservadas com `efetivo = 0` para rastreabilidade. O usuario `alexsandro.lima` utiliza essa regra para acessar somente Portaria, Emails, Help Desk e os dois menus de Homologacao de Fornecedores definidos na migracao 215.
