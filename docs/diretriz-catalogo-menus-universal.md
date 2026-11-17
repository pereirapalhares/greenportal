# Diretriz de catálogo universal de menus

O catálogo funcional de menus do GreenPortal pertence ao banco `guniversal_dbp`, na tabela `menus`. Ele é a fonte única para código, identificador, hierarquia, rótulos, URL, ordem, ícone e situação do menu.

As autorizações continuam isoladas por tenant. Cada banco de empresa mantém suas tabelas `perfis`, `perfil_menus` e `permissoes`; essas tabelas referenciam o `codmenu` global e não devem determinar o catálogo funcional.

O `MenuService` lê o catálogo universal e cruza os menus com as permissões efetivas da sessão. O banco do tenant não deve receber uma cópia funcional exclusiva do menu universal. Durante a homologação, o serviço mantém fallback para o catálogo local apenas para permitir a atualização gradual de instalações antigas.

O menu `ADMINISTRACAO_HELPDESK` foi cadastrado no catálogo universal com a URL `/principal/administracao_helpdesk.php`. A transação e suas tabelas de chamados permanecerão no banco do tenant, garantindo que cada empresa consulte somente seus próprios chamados.

As chaves estrangeiras das tabelas de autorização para a tabela local `menus` foram removidas nos tenants homologados, pois a referência passou a ser o catálogo central. Os dados existentes de autorização não foram apagados. A alteração foi registrada em `registros_migracao` com o código `CATALOGO_MENUS_UNIVERSAL` e deve ser idempotente.

Novos menus universais devem ser criados com `codigo` estável, `codmenu` controlado centralmente e URL explícita. A concessão de acesso deve ser feita separadamente nos perfis de cada tenant, nunca automaticamente apenas porque o menu existe no catálogo global.
