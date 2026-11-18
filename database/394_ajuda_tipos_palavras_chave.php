<?php
// Migração 394 — Ajuda das telas Tipos de palavras-chave e Tipos × Palavras-chave (ajuda_transacoes, trecho <!-- ml394 -->).
return [
    'manutencaolegal_tipos_palavras_chave' => [
        'titulo' => ['pt' => 'Tipos de palavras-chave', 'en' => 'Keyword types', 'es' => 'Tipos de palabras clave'],
        'texto' => [
            'pt' => '<p><strong>Tipos de palavras-chave</strong> agrupam as palavras-chave por uso: Controle, Infra-Estrutura, Licença, Medição, Treinamento… A lista é a mesma para todas as empresas.</p>'
                . '<ul><li><b>Incluir</b>: use + e digite o nome; o código é gerado sozinho.</li><li><b>Renomear</b>: altere o nome e salve — vale para todas as palavras do tipo.</li>'
                . '<li><b>Excluir</b>: só tipo sem palavras. Retire antes as palavras em <b>Tipos × Palavras-chave</b>.</li>'
                . '<li>Não é aceito nome repetido (sem diferença de acento ou maiúsculas).</li><li>A coluna <b>Palavras-chave</b> mostra quantas palavras cada tipo tem.</li></ul>',
            'en' => '<p><strong>Keyword types</strong> group keywords by use (Control, Infrastructure, License, Measurement, Training…). The list is shared by all companies.</p>'
                . '<ul><li><b>Add</b>: use + and type the name; the code is generated automatically.</li><li><b>Rename</b>: change the name and save.</li>'
                . '<li><b>Delete</b>: only types without keywords. Remove the keywords first in <b>Types × Keywords</b>.</li><li>Duplicate names are not accepted (ignoring accents and case).</li></ul>',
            'es' => '<p><strong>Tipos de palabras clave</strong> agrupan las palabras clave por uso (Control, Infraestructura, Licencia, Medición, Capacitación…). La lista es la misma para todas las empresas.</p>'
                . '<ul><li><b>Incluir</b>: use + y escriba el nombre; el código se genera solo.</li><li><b>Renombrar</b>: cambie el nombre y guarde.</li>'
                . '<li><b>Eliminar</b>: solo tipos sin palabras. Quite antes las palabras en <b>Tipos × Palabras clave</b>.</li><li>No se aceptan nombres repetidos (sin diferencia de acentos o mayúsculas).</li></ul>',
        ],
    ],
    'manutencaolegal_tiposxpalavras_chave' => [
        'titulo' => ['pt' => 'Tipos × Palavras-chave', 'en' => 'Types × Keywords', 'es' => 'Tipos × Palabras clave'],
        'texto' => [
            'pt' => '<p><strong>Tipos × Palavras-chave</strong> define quais palavras-chave pertencem a cada tipo (uma palavra pode estar em vários tipos).</p>'
                . '<ul><li>Escolha o <b>Tipo</b> no alto da tela.</li><li>À esquerda fica o catálogo único de palavras-chave (o mesmo das leis e das licenças); sob cada palavra aparecem os tipos que ela já tem. Em <b>Mostrar</b>, <b>Sem tipo</b> lista só as palavras ainda sem nenhum tipo.</li>'
                . '<li>À direita ficam as palavras do tipo escolhido.</li><li>Marque e use <b>›</b> para incluir ou <b>‹</b> para retirar (ou dê duplo clique na linha). Grava na hora; a palavra continua no catálogo.</li>'
                . '<li>Para criar ou renomear tipos, use <b>Tipos de palavras-chave</b>.</li></ul>',
            'en' => '<p><strong>Types × Keywords</strong> defines which keywords belong to each type (a keyword may be in several types).</p>'
                . '<ul><li>Choose the <b>Type</b> at the top.</li><li>Left: the single keyword catalog, with the types each keyword already has; <b>Without type</b> lists keywords with no type.</li>'
                . '<li>Right: the keywords of the chosen type.</li><li>Tick and use <b>›</b> to add or <b>‹</b> to remove (or double-click). Saved immediately; the keyword stays in the catalog.</li></ul>',
            'es' => '<p><strong>Tipos × Palabras clave</strong> define qué palabras clave pertenecen a cada tipo (una palabra puede estar en varios tipos).</p>'
                . '<ul><li>Elija el <b>Tipo</b> arriba.</li><li>Izquierda: el catálogo único de palabras clave, con los tipos que cada palabra ya tiene; <b>Sin tipo</b> lista las palabras sin tipo.</li>'
                . '<li>Derecha: las palabras del tipo elegido.</li><li>Marque y use <b>›</b> para incluir o <b>‹</b> para quitar (o doble clic). Se graba al instante; la palabra sigue en el catálogo.</li></ul>',
        ],
    ],
];
