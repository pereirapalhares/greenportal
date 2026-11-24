<?php
declare(strict_types=1);
// Migração 381 — Ajuda das Homes por módulo (ajuda_transacoes, trecho <!-- ml381 -->). Uma entrada por Home (HomeModulo::MENUS).
// 24/11/2026: Legal, Medição, Controles, Treinamentos, Infraestrutura e Não conformidades.
return [
    'home_licencas' => [
        'titulo' => ['pt' => 'Home de Licenças', 'en' => 'Licenses home', 'es' => 'Inicio de Licencias'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver de uma vez a situação das licenças e entregas periódicas, das condicionantes e das taxas, e chegar ao registro com um clique.</p>'
                . '<h5>Filtros do topo</h5><ul><li><b>Unidade</b>, <b>Responsável</b> e <b>Tipo</b> (licenças / entregas periódicas) valem para a página toda.</li>'
                . '<li><b>Vencimento</b> (já vencidos, até 30/60/90 dias, próximos 12 meses, este ano, sem data ou entre duas datas) vale para os cartões, os gráficos e a Lista; o Calendário tem o próprio mês.</li>'
                . '<li>Clique em <b>Filtrar</b>; <b>Limpar filtros</b> volta a mostrar tudo.</li></ul>'
                . '<h5>Cartões</h5><p>As mesmas faixas do Dashboard da empresa: vencidas, vencem em até 30, 31–60 e 61–90 dias, vigentes, em protocolo (renovação pedida no prazo continua válida), sem data de validade e entregas periódicas. '
                . 'Clique num cartão para listar os itens na aba <b>Lista</b>; clique de novo (ou no × da barra do grid) para tirar o filtro.</p>'
                . '<h5>Sub-abas</h5><ul><li><b>Gráficos</b>: situação, vencimentos nos próximos 12 meses, por unidade, por responsável, por tipo documental, por classificação e por órgão emissor (quando preenchido). Cada barra filtra a Lista.</li>'
                . '<li><b>Lista</b>: grid padrão (busca, filtro por coluna, ordenação, colunas). Duplo clique ou o botão Abrir abre a licença.</li>'
                . '<li><b>Calendário</b>: prazos do mês — vencimento (vermelho se já passou), início da renovação (dias de reação), ações das condicionantes e taxas. Clique num dia para listar abaixo; as setas mudam o mês.</li>'
                . '<li><b>Condicionantes e planos</b>: condicionantes atendidas, não atendidas e sem avaliação, e as ações dos planos (abre já filtrado nas abertas).</li>'
                . '<li><b>Taxas</b>: aparece quando há taxas cadastradas — vencidas, a vencer em 30 dias, a pagar e pagas no ano.</li></ul>'
                . '<h5>O que cada pessoa vê</h5><p>Quem tem <b>Visualizar total</b> em Licenças vê a empresa toda; os demais veem só as licenças sob a própria responsabilidade (responsável, atribuições e regras de Responsáveis e avisos).</p>',
            'en' => '<p>Overview of licenses and periodic submissions, conditions and fees. The filters at the top (unit, owner, kind, due date) apply to the whole page (the calendar has its own month). '
                . 'Click a card, a chart bar or a calendar day to list the items; click again to clear. Double-click a row to open the record. Users with "View all" on Licenses see the whole company; the others see only the licenses under their responsibility.</p>',
            'es' => '<p>Vista general de licencias y entregas periódicas, condicionantes y tasas. Los filtros del topo (unidad, responsable, tipo, vencimiento) valen para toda la página (el calendario tiene su propio mes). '
                . 'Haga clic en una tarjeta, una barra o un día para listar; clic de nuevo para quitar. Doble clic abre el registro. Quien tiene "Visualizar total" en Licencias ve toda la empresa; los demás, solo las licencias bajo su responsabilidad.</p>',
        ],
    ],
    'home_legal' => [
        'titulo' => ['pt' => 'Home do Módulo Legal', 'en' => 'Legal module home', 'es' => 'Inicio del Módulo Legal'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver de uma vez a situação de atendimento dos requisitos legais, o risco, as próximas avaliações e o que mudou na legislação no mês.</p><h5>Cartões</h5><p>Requisitos (com a taxa de conformidade = atendidos ÷ avaliados), atendidos, parcialmente, não atendidos, não avaliados, avaliação vencida ou a vencer em 30 dias, risco crítico ou alto, pendência crítica, avaliação manual diferente da calculada e sem responsável.</p><h5>Sub-abas</h5><ul><li><b>Gráficos</b>: situação, risco, por unidade, responsável, origem, nível de controle, área e próximas avaliações em 12 meses.</li><li><b>Lista</b>: os requisitos; abre o requisito em Requisitos legais.</li><li><b>Calendário das avaliações</b>: o vencimento da avaliação de cada requisito no mês.</li><li><b>Atualização do mês</b>: requisitos da empresa inseridos, alterados e revogados no mês (mesma regra da RCL Completa).</li><li><b>Pendências críticas</b>: item não atendido com risco crítico.</li></ul><p>Quem tem <b>Visualizar total</b> em Requisitos legais vê a empresa toda; os demais, só os requisitos de que são responsáveis. Por padrão as leis revogadas ficam de fora (filtro Leis revogadas).</p>' . '<h5>Como usar</h5><ul><li>Os <b>filtros do topo</b> valem para a página toda; clique em <b>Filtrar</b> e use <b>Limpar filtros</b> para voltar a ver tudo.</li><li>Clique num <b>cartão</b> ou numa <b>barra</b> de gráfico para listar os itens na aba da lista; clique de novo (ou no × da barra do grid) para tirar o filtro.</li><li>A lista é o grid padrão (busca, filtro por coluna, ordenação, colunas). <b>Duplo clique</b> ou o botão Abrir abre o registro.</li></ul>',
            'en' => '<p>Overview of the legal requirements: compliance status, risk, upcoming assessments and the monthly legislation update. Filters at the top apply to the whole page; click a card or a bar to list; double-click opens the requirement.</p>',
            'es' => '<p>Vista general de los requisitos legales: situación de atención, riesgo, próximas evaluaciones y la actualización mensual. Los filtros valen para toda la página; haga clic en una tarjeta o barra para listar; doble clic abre el requisito.</p>',
        ],
    ],
    'home_medicao' => [
        'titulo' => ['pt' => 'Home de Medição e Monitoramento', 'en' => 'Measurement and monitoring home', 'es' => 'Inicio de Medición y Monitoreo'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver a situação dos planos de medição pelo cronograma, as datas de reação, o risco e os requisitos ligados.</p><h5>Situação</h5><ul><li><b>Atrasada</b>: há um mês previsto no cronograma, anterior ao atual, sem medição registrada depois dele.</li><li><b>Prevista neste mês</b>: o mês atual está no cronograma e ainda não foi medido.</li><li><b>Sem cronograma</b>: nenhum mês marcado.</li><li><b>Em dia</b>: os demais.</li></ul><p>O <b>Calendário</b> mostra as medições previstas (no dia 1º do mês marcado), as datas de reação e as medições feitas.</p>' . '<h5>Como usar</h5><ul><li>Os <b>filtros do topo</b> valem para a página toda; clique em <b>Filtrar</b> e use <b>Limpar filtros</b> para voltar a ver tudo.</li><li>Clique num <b>cartão</b> ou numa <b>barra</b> de gráfico para listar os itens na aba da lista; clique de novo (ou no × da barra do grid) para tirar o filtro.</li><li>A lista é o grid padrão (busca, filtro por coluna, ordenação, colunas). <b>Duplo clique</b> ou o botão Abrir abre o registro.</li></ul>',
            'en' => '<p>Measurement plans by schedule (overdue, due this month, no schedule, on schedule), reaction dates, risk and linked requirements. Click a card, bar or calendar day to list; double-click opens the plan.</p>',
            'es' => '<p>Planes de medición por cronograma (atrasada, prevista este mes, sin cronograma, al día), fechas de reacción, riesgo y requisitos vinculados. Haga clic para listar; doble clic abre el plan.</p>',
        ],
    ],
    'home_controles' => [
        'titulo' => ['pt' => 'Home de Controles', 'en' => 'Controls home', 'es' => 'Inicio de Controles'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver a situação legal, o risco e os responsáveis dos controles (documentos).</p><h5>Situação legal do item</h5><p>É a pior situação do item entre os requisitos a que ele está ligado, pela mesma regra do cálculo da situação dos requisitos (Falha, Vencido, Atenção, Ok). Item sem vínculo com requisito aparece como <b>Sem vínculo</b>.</p><h5>Sub-abas</h5><ul><li><b>Gráficos</b>: situação, risco, por unidade (dos requisitos ligados) e por responsável.</li><li><b>Lista</b>: os itens; abre o cadastro.</li><li><b>Requisitos ligados</b>: cada item × requisito, com a situação e o motivo; abre o requisito.</li></ul><p>Quem tem acesso à tela do cadastro vê todos os itens; quem só tem a tela "Minhas/Meus" vê só os itens de que é responsável.</p>' . '<h5>Como usar</h5><ul><li>Os <b>filtros do topo</b> valem para a página toda; clique em <b>Filtrar</b> e use <b>Limpar filtros</b> para voltar a ver tudo.</li><li>Clique num <b>cartão</b> ou numa <b>barra</b> de gráfico para listar os itens na aba da lista; clique de novo (ou no × da barra do grid) para tirar o filtro.</li><li>A lista é o grid padrão (busca, filtro por coluna, ordenação, colunas). <b>Duplo clique</b> ou o botão Abrir abre o registro.</li></ul>',
            'en' => '<p>Legal status, risk and owners of the controls. Click a card or bar to list; double-click opens the record.</p>',
            'es' => '<p>Situación legal, riesgo y responsables de los controles. Haga clic para listar; doble clic abre el registro.</p>',
        ],
    ],
    'home_treinamentos' => [
        'titulo' => ['pt' => 'Home de Treinamentos', 'en' => 'Trainings home', 'es' => 'Inicio de Capacitaciones'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver a situação legal, o risco e os responsáveis dos treinamentos.</p><h5>Situação legal do item</h5><p>É a pior situação do item entre os requisitos a que ele está ligado, pela mesma regra do cálculo da situação dos requisitos (Falha, Vencido, Atenção, Ok). Item sem vínculo com requisito aparece como <b>Sem vínculo</b>.</p><h5>Sub-abas</h5><ul><li><b>Gráficos</b>: situação, risco, por unidade (dos requisitos ligados) e por responsável.</li><li><b>Lista</b>: os itens; abre o cadastro.</li><li><b>Requisitos ligados</b>: cada item × requisito, com a situação e o motivo; abre o requisito.</li></ul><p>Quem tem acesso à tela do cadastro vê todos os itens; quem só tem a tela "Minhas/Meus" vê só os itens de que é responsável.</p>' . '<h5>Como usar</h5><ul><li>Os <b>filtros do topo</b> valem para a página toda; clique em <b>Filtrar</b> e use <b>Limpar filtros</b> para voltar a ver tudo.</li><li>Clique num <b>cartão</b> ou numa <b>barra</b> de gráfico para listar os itens na aba da lista; clique de novo (ou no × da barra do grid) para tirar o filtro.</li><li>A lista é o grid padrão (busca, filtro por coluna, ordenação, colunas). <b>Duplo clique</b> ou o botão Abrir abre o registro.</li></ul>',
            'en' => '<p>Legal status, risk and owners of the trainings. Click a card or bar to list; double-click opens the record.</p>',
            'es' => '<p>Situación legal, riesgo y responsables de los capacitaciones. Haga clic para listar; doble clic abre el registro.</p>',
        ],
    ],
    'home_infraestrutura' => [
        'titulo' => ['pt' => 'Home de Infraestrutura', 'en' => 'Infrastructure home', 'es' => 'Inicio de Infraestructura'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver a situação legal, o risco e os responsáveis dos itens de infraestrutura.</p><h5>Situação legal do item</h5><p>É a pior situação do item entre os requisitos a que ele está ligado, pela mesma regra do cálculo da situação dos requisitos (Falha, Vencido, Atenção, Ok). Item sem vínculo com requisito aparece como <b>Sem vínculo</b>.</p><h5>Sub-abas</h5><ul><li><b>Gráficos</b>: situação, risco, por unidade (dos requisitos ligados) e por responsável.</li><li><b>Lista</b>: os itens; abre o cadastro.</li><li><b>Requisitos ligados</b>: cada item × requisito, com a situação e o motivo; abre o requisito.</li></ul><p>Quem tem acesso à tela do cadastro vê todos os itens; quem só tem a tela "Minhas/Meus" vê só os itens de que é responsável.</p>' . '<h5>Como usar</h5><ul><li>Os <b>filtros do topo</b> valem para a página toda; clique em <b>Filtrar</b> e use <b>Limpar filtros</b> para voltar a ver tudo.</li><li>Clique num <b>cartão</b> ou numa <b>barra</b> de gráfico para listar os itens na aba da lista; clique de novo (ou no × da barra do grid) para tirar o filtro.</li><li>A lista é o grid padrão (busca, filtro por coluna, ordenação, colunas). <b>Duplo clique</b> ou o botão Abrir abre o registro.</li></ul>',
            'en' => '<p>Legal status, risk and owners of the infrastructure items. Click a card or bar to list; double-click opens the record.</p>',
            'es' => '<p>Situación legal, riesgo y responsables de los ítems de infraestructura. Haga clic para listar; doble clic abre el registro.</p>',
        ],
    ],
    'home_nao_conformidades' => [
        'titulo' => ['pt' => 'Home de Não Conformidades', 'en' => 'Nonconformities home', 'es' => 'Inicio de No Conformidades'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Ver as ocorrências abertas: em que etapa estão, com quem, há quanto tempo, com que prazo, e as ações de plano vencidas.</p><h5>Cartões</h5><p>Abertas (e as abertas nos últimos 30 dias), por etapa (criação, gestor, análise e plano, eficácia), conclusão prevista vencida, paradas há mais de 90 dias, abertas há mais de 1 ano, sem pessoa na etapa ou com usuário inativo e ações de plano vencidas.</p><h5>Sub-abas</h5><ul><li><b>Gráficos</b>: por etapa, tempo em aberto, tipo, unidade, com quem estão e abertas × finalizadas por mês.</li><li><b>Abertas</b>: a lista; o duplo clique abre a ocorrência na etapa atual.</li><li><b>Calendário</b>: datas previstas de conclusão e ações vencidas.</li><li><b>Ações vencidas</b>.</li></ul><p>Quem tem a Lista geral de ocorrências vê todas; quem só tem Minhas ocorrências vê as que emitiu, gerencia, responde ou em que tem a ação da etapa.</p>' . '<h5>Como usar</h5><ul><li>Os <b>filtros do topo</b> valem para a página toda; clique em <b>Filtrar</b> e use <b>Limpar filtros</b> para voltar a ver tudo.</li><li>Clique num <b>cartão</b> ou numa <b>barra</b> de gráfico para listar os itens na aba da lista; clique de novo (ou no × da barra do grid) para tirar o filtro.</li><li>A lista é o grid padrão (busca, filtro por coluna, ordenação, colunas). <b>Duplo clique</b> ou o botão Abrir abre o registro.</li></ul>',
            'en' => '<p>Open occurrences by stage, who holds them, time open, planned dates and overdue action items. Click a card, bar or calendar day to list; double-click opens the occurrence.</p>',
            'es' => '<p>Ocurrencias abiertas por etapa, con quién están, tiempo abierta, plazos y acciones vencidas. Haga clic para listar; doble clic abre la ocurrencia.</p>',
        ],
    ],
];
