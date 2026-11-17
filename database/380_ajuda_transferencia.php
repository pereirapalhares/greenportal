<?php
declare(strict_types=1);
// Migração 380 — Ajuda da tela Home › Transferências (ajuda_transacoes, trecho <!-- ml380 -->).
return [
    'transferencia_responsabilidades' => [
        'titulo' => ['pt' => 'Transferência de responsabilidades', 'en' => 'Responsibility handover', 'es' => 'Transferencia de responsabilidades'],
        'texto' => [
            'pt' => '<h5>Para que serve</h5><p>Passar para outra pessoa, por um período (férias, afastamento) ou em definitivo (desligamento, troca de função), o que está com alguém nos módulos escolhidos: requisitos legais, licenças, medição, controles, treinamentos, infraestrutura, fornecedores, não conformidades e agenda.</p>'
                . '<h5>Como funciona</h5><ul><li><b>Os registros não mudam.</b> Enquanto a transferência vale, as pendências (Minhas pendências, sino, Agenda), os prazos e o calendário da barra direita, os alertas e os e-mails de quem passa, nesses módulos, vão para quem assume, com "de Fulano".</li>'
                . '<li>Quem passa deixa de receber esses itens. Ao encerrar, ou depois do último dia da temporária, tudo volta sozinho.</li>'
                . '<li>Até a virada do sistema anterior, trocar o responsável nos registros seria desfeito pelo sincronismo; por isso a transferência redireciona.</li></ul>'
                . '<h5>Passo a passo</h5><ol><li>Escolha quem passa: aparece quantos itens a pessoa tem em cada módulo.</li><li>Marque os módulos (todos marcados vale também para alertas gerais).</li><li>Escolha quem assume, se é temporária (com o último dia) ou definitiva, o início e o motivo.</li><li>Clique em Transferir e confirme.</li></ol>'
                . '<h5>Quem pode</h5><p>ADMINISTRADOR e ADM DA EMPRESA (todos os usuários) e gestores (a própria equipe). Tudo fica na aba Log; quem passa, quem assume e o gestor de quem passa recebem um alerta.</p>'
                . '<h5>O que ainda não muda</h5><p>As telas de Minhas atividades e os dashboards continuam mostrando só o que é da própria pessoa; os itens transferidos aparecem nas pendências, na agenda, na barra direita e nos alertas.</p>',
            'en' => '<p>Hand over to another person, for a period (vacation, leave) or permanently, what is assigned to someone in the chosen modules. Records do not change: while the handover is active, that person\'s pending items, deadlines, alerts and e-mails in those modules go to the person taking over, and come back automatically when it ends. Mandatory reason; everything is logged.</p>',
            'es' => '<p>Pasar a otra persona, por un período (vacaciones, licencia) o en definitiva, lo que está con alguien en los módulos elegidos. Los registros no cambian: mientras la transferencia vale, los pendientes, plazos, alertas y correos de esa persona en esos módulos van a quien asume y vuelven solos al terminar. Motivo obligatorio; todo queda en el registro.</p>',
        ],
    ],
];
