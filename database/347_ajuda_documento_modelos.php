<?php
declare(strict_types=1);

// Migração 347 — Ajuda do modelo do documento (validação documental pela IA) — trecho entre <!-- ml347 -->.
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$pt = '<p><strong>Modelo do documento</strong></p>'
    . '<p>Para cada documento exigido (critério) dá para cadastrar um modelo com o que ele precisa ter. Selecione o critério e clique no botão <strong>Modelo do documento</strong> (grupo Associações).</p>'
    . '<ul><li><strong>Itens</strong>: o que conferir no texto do documento (ex.: "assinatura e CRM do médico", "apto para trabalho em altura", "órgão emissor: Receita Federal"). <em>Obrigatório</em>: se faltar, a IA sugere devolver ao fornecedor; <em>recomendado</em>: só aparece no parecer;</li>'
    . '<li><strong>Regras fixas</strong>: razão social e CNPJ (ou nome e CPF do funcionário) iguais ao cadastro, assinatura ou certificação digital, validade escrita, válido por pelo menos N dias e emitido há no máximo N dias;</li>'
    . '<li><strong>Arquivo de exemplo</strong>: envie um documento bom deste tipo; o texto é lido pelo serviço de OCR e o botão <strong>Sugerir itens pela IA</strong> monta uma lista para você escolher o que usar.</li></ul>'
    . '<p><strong>Validação automática</strong>: com o modelo ativo, cada arquivo novo de um documento "Pendente com a empresa" é lido e conferido pela IA (o serviço verifica a cada 5 minutos). O parecer item a item fica em <strong>Gestão de Arquivos › Análises de IA</strong> do documento e o responsável da empresa recebe um alerta. Arquivos que já estavam nos documentos só são conferidos com a opção "Validar também os arquivos que já estão nos documentos pendentes".</p>'
    . '<p>A IA só sugere: aprovar, reprovar ou devolver ao fornecedor é sempre decisão de uma pessoa, na aba <strong>Revisão dos dados</strong>. O modelo também é usado quando alguém clica em <strong>Analisar com IA</strong>. Cada conferência entra no Consumo de IA da empresa.</p>';
$en = '<p><strong>Document template</strong></p><p>Each required document (criterion) can have a template with what it must contain: items (required or recommended), fixed rules (record match, signature, expiration, minimum validity, maximum issue age) and a sample file from which the AI suggests the items. With the template active, each new file of a document "Pending with the company" is checked by the AI and the item-by-item opinion stays in File management › AI analyses; the owner gets an alert. The AI only suggests: the decision is always a person\'s.</p>';
$es = '<p><strong>Modelo del documento</strong></p><p>Cada documento exigido (criterio) puede tener un modelo con lo que debe tener: ítems (obligatorios o recomendados), reglas fijas (registro, firma, vencimiento, validez mínima, emisión máxima) y un archivo de ejemplo con el que la IA sugiere los ítems. Con el modelo activo, cada archivo nuevo de un documento "Pendiente con la empresa" es revisado por la IA y el dictamen queda en Gestión de archivos › Análisis de IA; el responsable recibe una alerta. La IA solo sugiere: la decisión es siempre de una persona.</p>';
return [
    'homologacaofornecedores_modelo_documento' => ['titulo' => $L('Ajuda — Modelo do documento', 'Help — Document template', 'Ayuda — Modelo del documento'), 'texto' => $L($pt, $en, $es)],
    'homologacaofornecedores_criteriosfornecedores' => ['titulo' => $L('Critérios dos Fornecedores', 'Supplier criteria', 'Criterios de los proveedores'), 'texto' => $L($pt, $en, $es)],
    'homologacaofornecedores_criteriosfuncionarios' => ['titulo' => $L('Critérios dos funcionários', 'Employee criteria', 'Criterios de los empleados'), 'texto' => $L($pt, $en, $es)],
];
