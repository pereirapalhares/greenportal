# Minhas Licenças

Foi criada a transação independente `minhas_licencas.php` no Módulo Legal.

Ela reutiliza o padrão do grid de Licenças, incluindo barra de ações, filtros,
relacionamentos, arquivos, exportação e edição conforme as permissões do
perfil. A diferença funcional é que a consulta é sempre limitada ao usuário
logado, considerando a responsabilidade direta registrada em `CODUSUARIO`.

Mesmo que o perfil possua Visualização Total na transação geral de Licenças,
`Minhas Licenças` permanece restrita ao usuário logado. A criação do menu,
permissões e transação é feita por `database/149_menu_minhas_licencas.sql`.
