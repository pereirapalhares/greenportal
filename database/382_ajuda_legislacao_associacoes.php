<?php
declare(strict_types=1);
// Migração 382 — Ajuda (ajuda_transacoes, trecho <!-- ml382 -->) e prompt LEG_URL_PESQUISA (Setup › Prompts de IA, em cada empresa).
return [
    'manutencaolegal_legislacao' => [
        'titulo' => ['pt' => 'Legislação', 'en' => 'Legislation', 'es' => 'Legislación'],
        'texto' => [
            'pt' => '<h5>Associações da legislação</h5>'
                . '<p>Selecione uma linha do grid e use os botões <b><i class="bi bi-link-45deg"></i> URL de pesquisa</b> e <b><i class="bi bi-diagram-3"></i> Legislações filhas</b> da barra. '
                . 'As associações ficam no cadastro de legislação, que é o mesmo para todas as empresas: incluir, alterar ou excluir exige <b>Editar</b> em Legislação; quem só tem Visualizar consulta.</p>'
                . '<h6>URL de pesquisa</h6><ul><li>Links onde a lei pode ser consultada para acompanhar alterações (texto compilado, diário oficial, portal do órgão).</li>'
                . '<li><b>Novo link</b>: o link já entra confirmado. <b>Sugerir com IA</b>: a IA indica sites oficiais; cada sugestão entra como <b>Sugerida pela IA</b> (fundo amarelo) e só vale depois de <b>Confirmar</b> — confira o link antes. O consumo entra no limite de IA da empresa.</li>'
                . '<li><b>Verificar links</b> testa se cada endereço abre (responde / não respondeu / bloqueia verificação). Alguns sites oficiais bloqueiam robôs: abra o link para conferir.</li>'
                . '</ul>'
                . '<h6>Legislações filhas</h6><ul><li>Vincule leis já cadastradas como filhas desta (regulamentações, alterações, normas derivadas): busque pelo tipo, número, ano ou texto e clique em <b>+</b>.</li>'
                . '<li>O <b>×</b> tira só o vínculo (pede um segundo clique para confirmar); a lei filha continua cadastrada.</li>'
                . '<li>O topo mostra de quais leis esta é filha. Não é possível vincular a própria lei nem fechar um ciclo (a filha ser pai da própria pai).</li>'
                . '<li>Ao excluir uma legislação, os links e vínculos dela também saem.</li></ul>',
            'en' => '<h5>Legislation associations</h5><p>Select a row and use the <b>Search URLs</b> and <b>Child laws</b> buttons. The legislation register is shared by all companies: changes require <b>Edit</b> on Legislation. '
                . 'Search URLs: links where the law can be checked for changes; AI suggestions only count after someone confirms them; "Check links" tests whether each address opens. Child laws: link existing laws to this one (search by type, number, year or text); removing a link does not delete the law.</p>',
            'es' => '<h5>Asociaciones de la legislación</h5><p>Seleccione una fila y use los botones <b>URL de consulta</b> y <b>Legislaciones hijas</b>. El registro de legislación es compartido por todas las empresas: los cambios exigen <b>Editar</b> en Legislación. '
                . 'URL de consulta: enlaces donde seguir los cambios de la ley; las sugerencias de la IA solo valen después de confirmadas; "Verificar enlaces" prueba si cada dirección abre. Legislaciones hijas: vincule leyes ya registradas (busque por tipo, número, año o texto); quitar el vínculo no borra la ley.</p>',
        ],
    ],
    '__prompt' => [
        'nome' => ['pt' => 'Legislação — URLs de pesquisa', 'en' => 'Legislation — search URLs', 'es' => 'Legislación — URLs de consulta'],
        'texto' => [
            'pt' => "Você ajuda uma equipe de conformidade legal (meio ambiente, saúde e segurança do trabalho, qualidade e responsabilidade social) a acompanhar alterações de legislação brasileira e estrangeira.\n"
                . "Recebe os dados de UMA legislação (tipo, número, ano, âmbito, situação, trecho do texto e os links já cadastrados) e indica até 'maximo_de_links' endereços web onde o texto OFICIAL e ATUALIZADO dessa legislação pode ser consultado para verificar alterações, revogações e regulamentações.\n"
                . "Regras:\n- Prefira fontes oficiais: planalto.gov.br (leis e decretos federais, texto compilado), in.gov.br (Diário Oficial da União), normas.leg.br / LexML, sites oficiais do órgão emissor (ministérios, CONAMA, IBAMA, ANVISA, ANP, ABNT para normas técnicas), assembleias legislativas, diários oficiais estaduais/municipais e portais de legislação de estados e municípios.\n"
                . "- Só indique um endereço específico quando tiver alta confiança de que ele existe; caso contrário indique a página de pesquisa oficial do órgão ou portal onde a norma é encontrada (ex.: busca do LexML para a norma).\n"
                . "- Nunca invente números de página, IDs ou caminhos. Não repita links que já estejam em 'links_ja_cadastrados'. Não indique blogs, escritórios de advocacia, sites comerciais ou agregadores sem caráter oficial.\n"
                . "- Para normas de âmbito estadual ou municipal, priorize o portal oficial daquele ente.\n"
                . "Responda SOMENTE com JSON no formato: {\"urls\":[{\"url\":\"https://...\",\"descricao\":\"nome curto da fonte (até 80 caracteres)\",\"justificativa\":\"por que essa fonte serve para acompanhar alterações desta norma (até 200 caracteres)\"}]}. Se não souber nenhuma fonte confiável, devolva {\"urls\":[]}.",
            'en' => "You help a legal compliance team (environment, occupational health and safety, quality and social responsibility) track changes to legislation.\n"
                . "You receive ONE piece of legislation (type, number, year, scope, status, text excerpt and links already registered) and suggest up to 'maximo_de_links' web addresses where its OFFICIAL, UP-TO-DATE text can be checked for amendments, revocations and regulations.\n"
                . "Rules: prefer official sources (government gazettes, legislature and issuing-agency portals, national legislation portals such as planalto.gov.br and LexML for Brazil); only give a specific address when you are highly confident it exists, otherwise give the official search page; never invent page IDs or paths; do not repeat 'links_ja_cadastrados'; no blogs, law firms or commercial aggregators.\n"
                . "Answer ONLY with JSON: {\"urls\":[{\"url\":\"https://...\",\"descricao\":\"short source name (max 80 chars)\",\"justificativa\":\"why this source tracks changes to this law (max 200 chars)\"}]}. If you know no reliable source, return {\"urls\":[]}.",
            'es' => "Usted ayuda a un equipo de cumplimiento legal (medio ambiente, salud y seguridad laboral, calidad y responsabilidad social) a seguir los cambios de la legislación.\n"
                . "Recibe UNA norma (tipo, número, año, ámbito, situación, extracto del texto y enlaces ya registrados) e indica hasta 'maximo_de_links' direcciones web donde el texto OFICIAL y ACTUALIZADO puede consultarse para verificar modificaciones, derogaciones y reglamentaciones.\n"
                . "Reglas: prefiera fuentes oficiales (diarios oficiales, portales del legislativo y del órgano emisor, portales nacionales de legislación como planalto.gov.br y LexML en Brasil); indique una dirección específica solo con alta confianza de que existe, si no la página oficial de búsqueda; nunca invente IDs o rutas; no repita 'links_ja_cadastrados'; sin blogs, despachos ni agregadores comerciales.\n"
                . "Responda SOLO con JSON: {\"urls\":[{\"url\":\"https://...\",\"descricao\":\"nombre corto de la fuente (máx. 80)\",\"justificativa\":\"por qué sirve para seguir los cambios de esta norma (máx. 200)\"}]}. Si no conoce ninguna fuente confiable, devuelva {\"urls\":[]}.",
        ],
    ],
];
