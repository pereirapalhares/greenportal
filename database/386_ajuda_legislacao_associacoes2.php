<?php
declare(strict_types=1);
// Migração 386 — Ajuda (trecho <!-- ml386 -->) e prompt LEG_PALAVRAS_CHAVE (Setup › Prompts de IA, em cada empresa).
return [
    'manutencaolegal_legislacao' => [
        'titulo' => ['pt' => 'Legislação', 'en' => 'Legislation', 'es' => 'Legislación'],
        'texto' => [
            'pt' => '<h6>Alterações na legislação</h6><ul>'
                . '<li>Botão <b><i class="bi bi-clock-history"></i> Alterações na legislação</b>: lista, inclui, edita e exclui as alterações da lei (data, tipo do cadastro de tipos de alteração e descrição).</li>'
                . '<li>Ao incluir, a opção <b>"Atualizar também a situação e a data da lei"</b> já vem marcada: grava na própria lei a situação (Lei Alterada / Lei Revogada, sugerida pelo tipo), o "devido" e a data de atualização. É isso que faz a lei entrar no <b>RCL</b> do mês (Normas Alteradas / Retiradas) e no filtro <b>Mês/Ano</b>.</li>'
                . '<li>A descrição é o texto que sai no RCL em "Alterações" ou "Revogado por".</li></ul>'
                . '<h6>CheckList e Palavras-chave</h6><ul>'
                . '<li>Botões <b><i class="bi bi-ui-checks"></i> CheckList</b> e <b><i class="bi bi-tags"></i> Palavras-chave</b>: à esquerda, os itens da lei; à direita, a busca no catálogo único. Digite e os parecidos aparecem: use <b>+</b> para vincular. <b>Nova</b> cria no catálogo e vincula (palavra igual já existente é reaproveitada; pergunta igual é recusada).</li>'
                . '<li>O <b>lápis</b> muda o texto no catálogo: vale para todas as leis que usam o item (a tela mostra quantas) e, nas palavras-chave, também para as licenças.</li>'
                . '<li>O <b>×</b> tira só o vínculo (pede um segundo clique).</li>'
                . '<li>Palavras-chave: <b>Sugerir com IA</b> lê a lei e propõe palavras, marcando as que já existem no catálogo; só entram as que você marcar. O consumo entra no limite de IA da empresa.</li></ul>',
            'en' => '<h6>Legislation changes, checklist and keywords</h6><p><b>Legislation changes</b>: add, edit and delete the law\'s changes; the option "also update the law\'s status and date" sets the law\'s status, reason and update date, which is what puts the law in the monthly RCL and in the Month/Year filter. <b>Checklist</b> and <b>Keywords</b>: link items from the single shared catalogs; editing a text changes the catalog for every law that uses it; Keywords can be suggested by AI (only the ones you tick are linked).</p>',
            'es' => '<h6>Cambios, checklist y palabras clave</h6><p><b>Cambios en la legislación</b>: incluir, editar y borrar los cambios de la ley; la opción "actualizar también la situación y la fecha de la ley" graba la situación, el motivo y la fecha de actualización, lo que pone la ley en el RCL del mes y en el filtro Mes/Año. <b>Checklist</b> y <b>Palabras clave</b>: vincular ítems de los catálogos únicos; editar un texto cambia el catálogo para todas las leyes que lo usan; las palabras clave pueden ser sugeridas por la IA (solo se vinculan las marcadas).</p>',
        ],
    ],
    '__prompt' => [
        'nome' => ['pt' => 'Legislação — palavras-chave', 'en' => 'Legislation — keywords', 'es' => 'Legislación — palabras clave'],
        'texto' => [
            'pt' => "Você ajuda uma equipe de conformidade legal (meio ambiente, saúde e segurança do trabalho, qualidade e responsabilidade social) a classificar legislação para busca.\n"
                . "Recebe os dados de UMA legislação (tipo, número, ano, âmbito, situação e trecho do texto) e as palavras-chave já vinculadas, e sugere até 'maximo_de_palavras' palavras-chave NOVAS que ajudem a encontrar esta lei.\n"
                . "Regras:\n- Termos curtos (1 a 4 palavras), substantivos, no singular, sem artigos, em minúsculas exceto siglas (ex.: \"resíduos sólidos\", \"licenciamento ambiental\", \"NR-12\", \"efluentes\", \"APP\").\n"
                . "- Use os termos técnicos usuais da área (os mesmos que um analista de SGA/SST pesquisaria). Não repita o tipo/número/ano da lei nem palavras já vinculadas. Nada genérico como \"lei\", \"norma\", \"brasil\".\n"
                . "Responda SOMENTE com JSON: {\"palavras\":[\"termo 1\",\"termo 2\"]}. Se não houver boas sugestões, {\"palavras\":[]}.",
            'en' => "You help a legal compliance team classify legislation for search. You receive ONE law (type, number, year, scope, status, text excerpt) and the keywords already linked, and suggest up to 'maximo_de_palavras' NEW short keywords (1-4 words, nouns, singular, lowercase except acronyms; usual technical terms of EHS/quality; no law type/number, no already linked words, nothing generic). Answer ONLY with JSON: {\"palavras\":[\"term 1\",\"term 2\"]}.",
            'es' => "Usted ayuda a un equipo de cumplimiento legal a clasificar legislación para búsqueda. Recibe UNA norma (tipo, número, año, ámbito, situación, extracto) y las palabras clave ya vinculadas, y sugiere hasta 'maximo_de_palabras' palabras clave NUEVAS y cortas (1-4 palabras, sustantivos, singular, minúsculas salvo siglas; términos técnicos usuales; sin tipo/número de la norma, sin repetir, nada genérico). Responda SOLO con JSON: {\"palavras\":[\"término 1\",\"término 2\"]}.",
        ],
    ],
];
