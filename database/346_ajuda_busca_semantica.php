<?php
declare(strict_types=1);

// Migração 346 — Ajuda da busca semântica e da reavaliação por mudança de lei (trecho entre <!-- ml346 -->).
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$ptBusca = '<p><strong>Busca pelo sentido</strong></p>'
    . '<p>Com a chave <strong>Também pelo sentido</strong> ligada (ao lado de Pesquisar), a pesquisa mostra, depois dos resultados pelas palavras, os itens <strong>parecidos pelo sentido</strong>, mesmo sem as palavras digitadas. Exemplo: "barulho da fábrica" acha a licença e os controles de "ruído ambiental". Cada um vem marcado com a semelhança (%).</p>'
    . '<ul><li>Entram os cadastros (licenças, controles, requisitos, treinamentos, infraestrutura, documentos de fornecedores e funcionários) e o <strong>texto já lido dos arquivos</strong> (OCR). Arquivo que ainda não teve o texto lido aparece só pela busca por palavra;</li>'
    . '<li>Valem as mesmas permissões da pesquisa: só aparece o que você pode ver;</li>'
    . '<li>O índice pelo sentido é atualizado pelo serviço da pesquisa a cada 10 minutos; cada atualização e cada pesquisa usam a IA e entram no Consumo de IA da empresa (custo muito baixo). Com o limite mensal de IA atingido, fica só a busca por palavra.</li></ul>';
$enBusca = '<p><strong>Search by meaning</strong></p><p>With <strong>Also by meaning</strong> on, after the word results the search shows items <strong>similar in meaning</strong>, even without the typed words, marked with the similarity (%). Records and the text already read from files (OCR) are included, with the same permissions. Uses AI (Administration › AI consumption).</p>';
$esBusca = '<p><strong>Búsqueda por sentido</strong></p><p>Con <strong>También por sentido</strong> activado, después de los resultados por palabras la búsqueda muestra los ítems <strong>parecidos por el sentido</strong>, con la semejanza (%). Entran los registros y el texto ya leído de los archivos (OCR), con los mismos permisos. Usa la IA (Consumo de IA).</p>';
$ptReav = '<p><strong>Reavaliação por mudança de lei</strong></p>'
    . '<p>Quando a base de legislação registra uma <strong>alteração ou revogação</strong> de lei, o sistema procura na empresa:</p><ul>'
    . '<li>os <strong>requisitos da própria lei</strong> (em cada unidade);</li>'
    . '<li>os <strong>itens parecidos pelo sentido</strong>: requisitos de outras leis, licenças, controles, treinamentos e infraestrutura que tratam do mesmo assunto (semelhança a partir de 45%).</li></ul>'
    . '<p>Lei nos requisitos: cada responsável pelo requisito (situação do requisito) recebe a tarefa <strong>Revisar</strong> (prazo de 15 dias) com a lista do que conferir; os responsáveis dos itens parecidos recebem um alerta. Lei fora dos requisitos, mas muito parecida (55% ou mais) com itens da empresa: a tarefa vai para o ADM DA EMPRESA, para avaliar se ela passa a ser requisito. Sem nada parecido: fica só registrada como "Sem impacto".</p>'
    . '<p>A tela lista as alterações recebidas, os itens encontrados (clique para abrir) e a situação da tarefa. As alterações anteriores à ativação não geram reavaliação.</p>';
$enReav = '<p><strong>Review on law changes</strong></p><p>When the legislation base records an amendment or revocation, the requirements of that law and the items similar in meaning (other requirements, licenses, controls, training, infrastructure) are listed and the requirement owners get a <strong>Review</strong> task (15 days); owners of similar items get an alert. Laws outside the requirements but very similar to company items go to the company admin.</p>';
$esReav = '<p><strong>Revisión por cambio de ley</strong></p><p>Cuando la base de legislación registra una modificación o derogación, se listan los requisitos de la ley y los ítems parecidos por el sentido, y los responsables reciben la tarea <strong>Revisar</strong> (15 días); los responsables de ítems parecidos reciben una alerta.</p>';
return [
    'busca_documentos' => ['titulo' => $L('Pesquisa de documentos', 'Document search', 'Búsqueda de documentos'), 'texto' => $L($ptBusca, $enBusca, $esBusca)],
    'modulolegal_reavaliacao_leis' => ['titulo' => $L('Ajuda — Reavaliação por mudança de lei', 'Help — Review on law changes', 'Ayuda — Revisión por cambio de ley'), 'texto' => $L($ptReav, $enReav, $esReav)],
    'modulolegal_requisitoslegais' => ['titulo' => $L('Requisitos legais', 'Legal requirements', 'Requisitos legales'), 'texto' => $L($ptReav, $enReav, $esReav)],
];
