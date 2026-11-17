# Responsabilidades e Meus Controles

`modulolegal_controle.php` possui o botão contextual **Responsabilidades** na barra superior. A seleção de um documento habilita o botão e abre `controle_ml_responsabilidades.php` em popup independente.

O popup permite atribuir o controle a usuários ou grupos. As alterações são persistidas em `responsabilidade_atribuicoes` através das procedures universais e os comandos ficam acima do grid.

`meus_controles.php` usa `vw_meus_controles_grid`, com escopo obrigatório do usuário logado. A view considera atribuições diretas e membros ativos de grupos. A inclusão e exclusão do controle permanecem disponíveis somente no cadastro geral.

A migração `155_responsabilidades_meus_controles.sql` cria as views, o menu e as permissões da transação direcionada.
