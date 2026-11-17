<?php
declare(strict_types=1);
// Migração 381 — Ajuda das Homes por módulo (ajuda_transacoes, trecho <!-- ml381 -->). Uma entrada por Home (HomeModulo::MENUS).
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
];
