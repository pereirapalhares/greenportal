# Responsabilidades de Treinamentos

O grid `modulolegal_treinamentos.php` possui o botão **Responsabilidades** na barra superior. Ele só é habilitado após a seleção de um treinamento e abre o popup independente `treinamento_ml_responsabilidades.php`.

No popup, o usuário pode incluir ou excluir atribuições de responsabilidade para o treinamento selecionado. Cada linha permite escolher a finalidade, o destino (usuário ou grupo), a pessoa/grupo, prioridade e vigência. Os comandos ficam acima do grid: **Incluir**, **Excluir**, **Confirmar** e **Cancelar**; não há botões de ação dentro das linhas.

As alterações são persistidas na tabela `responsabilidade_atribuicoes` exclusivamente pelas procedures de CRUD e validadas por `sp_responsabilidade_validar_duplicidade`. A view `vw_responsabilidade_ml_treinamentos` fornece os dados do popup. A view `vw_minhas_treinamentos_grid` considera atribuições diretas e membros de grupos ativos.

Os textos do botão e da tela respeitam PT, EN e ES.
