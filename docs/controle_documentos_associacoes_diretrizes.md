# Diretrizes de associações do Controle

## Modal como grid de associação

As associações de um documento são editadas em uma modal própria, apresentada pelo botão correspondente na barra de ações do grid principal.

A modal deve conter um grid CRUD com:

- associações existentes exibidas em linhas somente leitura;
- botão `Incluir` na barra acima do grid;
- cada nova linha com combobox para selecionar a entidade relacionada;
- botão `Excluir` na barra, aplicado à linha selecionada;
- botão `Confirmar` na barra, para sincronizar todas as inclusões e exclusões;
- botão `Cancelar` na barra, descartando alterações não confirmadas.

Nenhum botão de ação pode ser colocado dentro das células ou das linhas do grid. Os botões pertencem exclusivamente à barra de ações da tela ou da modal.

O combobox não pode permitir associações duplicadas. Ao confirmar, a aplicação deve preservar as associações mantidas, inserir as novas e excluir as removidas em uma única operação. O resumo da associação no grid principal deve ser atualizado sem recarregar a página.

## Arquitetura de dados

O PHP não contém SQL de leitura ou gravação para as associações. As views `vw_controle_documentos_rel_*` fornecem o catálogo completo de opções por meio de `sp_crud_select`; os vínculos atuais são lidos pelas tabelas de associação, também por `sp_crud_select`. A confirmação utiliza exclusivamente `sp_crud_insert` e `sp_crud_delete`; a lista branca de tabelas e campos fica na configuração da API e as regras de autorização permanecem no servidor.

Migração das views: `database/124_controle_documentos_associacoes_views.sql`, registro `CONTROLE_DOCUMENTOS_ASSOCIACOES_VIEWS_124`.

## Relacionamentos abrangidos

O padrão se aplica a `documentosxunidades`, `documentosxatividades`, `documentosxaspectos`, `documentosximpactos`, `documentosxperigos`, `documentosxriscos`, `documentosxpalavraschave`, `lais_documentosxaspectos` e `lais_documentosximpactos`.

Para os relacionamentos sociais, os catálogos são `lais_aspectossociais` e `lais_impactossociais`, expostos pelas views `vw_controle_documentos_rel_aspectos_sociais` e `vw_controle_documentos_rel_impactos_sociais`. As tabelas `lais`, `lais_partes_interessadas`, `lais_revisao`, `lais_legislacaoxaspectossociais` e `lais_legislacaoximpactossociais` permanecem disponíveis para os módulos LAIS e legislação, mas não fazem parte deste CRUD de Controle.

## Teste

O teste `scripts/teste-controle-documentos.js` deve verificar a existência da barra de ações, a ausência de botões dentro do grid, a inclusão de linhas com combobox e a confirmação do ciclo de inclusão/exclusão para cada um dos nove relacionamentos quando executado com `--mutar`.
