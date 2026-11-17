<?php
declare(strict_types=1);

// Migração 349 — Ajuda da análise de adulteração (trecho entre <!-- ml349 -->). Só p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$pt = '<p><strong>Análise de adulteração dos documentos</strong></p>'
    . '<p>Cada arquivo novo de documento (do fornecedor ou do funcionário) é analisado sozinho em alguns minutos. O resultado aparece nos <strong>Arquivos</strong> do documento, na aba <strong>Adulteração</strong>, e um ícone de escudo ao lado do nome do arquivo mostra o risco. Arquivos que já estavam no sistema são analisados pelo botão <em>Analisar este arquivo</em>.</p>'
    . '<p>O que é conferido:</p>'
    . '<ul><li><strong>Metadados</strong>: programa que gerou ou editou o arquivo (editor de imagem ou de PDF), datas de criação e modificação, PDF salvo por cima do original;</li>'
    . '<li><strong>Assinatura digital</strong>: se o conteúdo confere com a assinatura e se o PDF foi alterado depois de assinado;</li>'
    . '<li><strong>Imagem</strong>: programa de edição nos dados da foto e regiões com compressão diferente do resto (possível colagem; indício fraco);</li>'
    . '<li><strong>Conteúdo</strong>: CNPJ ou CPF com dígito inválido, datas impossíveis, emissão no futuro ou depois da última gravação do arquivo, e a leitura da IA, que aponta incoerências do texto.</li></ul>'
    . '<p>O risco é <strong>sem indícios</strong>, <strong>baixo</strong>, <strong>médio</strong> ou <strong>alto</strong>. Com risco médio ou alto, o responsável pelo documento recebe um alerta. A análise só aponta: confira o arquivo e, se preciso, o emissor (código de autenticação no site do órgão).</p>'
    . '<p>Com <strong>risco alto</strong>, o documento não pode ser aprovado (fornecedor: Aprovado; funcionário: Atende = Sim) até alguém que pode editar a documentação registrar a <strong>ciência com o motivo</strong> na aba Adulteração. As regras automáticas levam esse documento para <em>Pendente com a empresa</em>. Excluir o arquivo suspeito também libera a aprovação. O fornecedor não vê a análise.</p>';
$en = '<p><strong>Document tampering analysis</strong></p><p>Each new document file (supplier or employee) is analyzed automatically: metadata (editing software, creation and modification dates, PDF saved over the original), digital signature (content matches, changes after signing), image (editing software, regions with different compression) and content (invalid CNPJ/CPF check digits, impossible dates, issue date in the future or after the file was last saved, and the AI reading). The result is shown in the document Files, Tampering tab. With high risk the document can only be approved after someone records the acknowledgment with the reason. The analysis only points out signs; the decision is yours.</p>';
$es = '<p><strong>Análisis de adulteración de los documentos</strong></p><p>Cada archivo nuevo de documento (proveedor o empleado) se analiza solo: metadatos (programa de edición, fechas de creación y modificación, PDF grabado sobre el original), firma digital (el contenido coincide, cambios después de firmar), imagen (programa de edición, regiones con compresión diferente) y contenido (dígito verificador de CNPJ/CPF inválido, fechas imposibles, emisión futura o posterior a la última grabación del archivo, y la lectura de la IA). El resultado aparece en los Archivos del documento, pestaña Adulteración. Con riesgo alto el documento solo puede aprobarse después de registrar el conocimiento con el motivo. El análisis solo señala indicios; la decisión es suya.</p>';
$t = $L($pt, $en, $es);
return [
    'portaria_documentacaofornecedor' => ['titulo' => $L('Documentação de fornecedor', 'Supplier documentation', 'Documentación de proveedor'), 'texto' => $t],
    'portaria_documentacaofuncionario' => ['titulo' => $L('Documentação de funcionário', 'Employee documentation', 'Documentación de empleado'), 'texto' => $t],
    'homologacaofornecedor_fornecedor' => ['titulo' => $L('Gestão de Fornecedores', 'Supplier management', 'Gestión de proveedores'), 'texto' => $t],
    'homologacaofornecedor_funcionario' => ['titulo' => $L('Gestão de Funcionários', 'Employee management', 'Gestión de empleados'), 'texto' => $t],
];
