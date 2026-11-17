-- Orientação para criação de legislações derivadas pela matriz.
UPDATE `guniversal_dbp`.`ajuda_transacoes`
SET
    `texto_pt` = '<p>Use a matriz para relacionar uma legislação às unidades.</p><p>Escolha <strong>MATRIZ</strong> para a legislação principal. Ao escolher uma letra de A a Z em uma célula vazia, o sistema criará uma nova legislação com o Código da principal acrescido da letra e copiará seus dados e relacionamentos.</p><p>Exemplo: a lei LB0001 com a opção A será criada como LB0001A para a unidade selecionada.</p>',
    `texto_en` = '<p>Use the matrix to relate legislation to units.</p><p>Choose <strong>MATRIX</strong> for the main legislation. When you choose a letter from A to Z in an empty cell, the system creates a new law using the main Code plus the letter and copies its data and relationships.</p><p>Example: law LB0001 with option A is created as LB0001A for the selected unit.</p>',
    `texto_es` = '<p>Use la matriz para relacionar una legislación con las unidades.</p><p>Elija <strong>MATRIZ</strong> para la legislación principal. Al elegir una letra de A a Z en una celda vacía, el sistema creará una nueva legislación con el Código principal más la letra y copiará sus datos y relaciones.</p><p>Ejemplo: la ley LB0001 con la opción A se creará como LB0001A para la unidad seleccionada.</p>'
WHERE `transacao` = 'manutencaolegal_legislacaoxempresa';
