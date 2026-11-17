# Responsabilidades de Infraestrutura

O grid `modulolegal_InfraEstrutura.php` possui o botão **Responsabilidades** na barra superior. Após selecionar uma infraestrutura, o botão abre o popup independente `infraestrutura_ml_responsabilidades.php`.

O popup permite incluir e excluir responsáveis por usuário ou grupo. As ações ficam acima do grid e a confirmação é feita por popup de pergunta. A persistência utiliza exclusivamente `sp_crud_select`, `sp_crud_insert` e `sp_crud_update`, com os dados armazenados em `responsabilidade_atribuicoes`.

A view `vw_responsabilidade_ml_infraestrutura` relaciona as atribuições com `ml_infraestrutura`. O acesso e as alterações respeitam a permissão da transação `MODULOLEGAL_INFRAESTRUTURA`.
