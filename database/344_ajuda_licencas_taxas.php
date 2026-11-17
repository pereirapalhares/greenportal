<?php
declare(strict_types=1);

// Migração 344 — Ajuda das taxas das licenças (trecho entre <!-- ml344 --> no fim do texto atual de cada transação).
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$pt = '<p><strong>Taxas das licenças</strong></p>'
    . '<p>Cada licença pode ter várias taxas (emissão, renovação, análise, fiscalização anual, publicação ou outra). Abra pelo botão <strong>Taxas</strong> do cadastro de Licenças (grupo Associações) ou pelo menu <strong>Taxas das licenças</strong>, que mostra as taxas de todas as licenças.</p>'
    . '<ul><li><strong>Tipo, descrição e número da guia</strong> (guia, boleto ou DAE);</li>'
    . '<li><strong>Valor</strong> no formato 1.234,56 e <strong>Vencimento</strong> (obrigatório);</li>'
    . '<li><strong>Frequência</strong>: única ou recorrente (mensal, trimestral, semestral, anual, a cada 2 a 5 anos);</li>'
    . '<li><strong>Pago em</strong> e <strong>Valor pago</strong> (sem o valor pago, vale o valor da taxa);</li>'
    . '<li><strong>Comprovante</strong>: selecione a taxa e use o botão do clipe para anexar o comprovante de pagamento.</li></ul>'
    . '<p><strong>Recorrência:</strong> ao registrar o pagamento de uma taxa recorrente, o sistema cria a próxima com o vencimento da frequência e o mesmo valor (Origem = Automática). Confira o valor e o vencimento da nova taxa. Se a próxima for excluída, ela não é criada de novo; para encerrar a recorrência, exclua a próxima ou mude a frequência para Única.</p>'
    . '<p><strong>Avisos:</strong> os responsáveis pela licença recebem um alerta 30 dias antes e outro no dia do vencimento (e por e-mail, conforme Minha conta › Alertas por e-mail). As taxas vencidas e as que vencem em até 30 dias aparecem em Home › Minhas pendências e na Agenda.</p>'
    . '<p><strong>Situação legal:</strong> taxa vencida e não paga deixa a licença válida em <strong>Atenção</strong> nos requisitos legais ligados a ela (o motivo diz quantas taxas e a mais antiga). A licença continua válida. O dashboard da empresa (aba Licenças) mostra as taxas vencidas, as que vencem em 30 dias e as pagas no ano.</p>'
    . '<p>A taxa fica na licença original: continua aparecendo depois da renovação (nova versão) da licença.</p>';
$en = '<p><strong>License fees</strong></p><p>Each license can have several fees (issuance, renewal, review, annual inspection, publication or other), with value, due date, frequency, payment date and value, and a payment receipt (paperclip button). When a recurring fee is paid, the next one is created automatically (same value, due date by frequency). License owners get an alert 30 days before and on the due date; overdue fees and fees due within 30 days appear in My pending items and the Agenda. An overdue unpaid fee puts the license in Attention in the calculated legal status.</p>';
$es = '<p><strong>Tasas de las licencias</strong></p><p>Cada licencia puede tener varias tasas (emisión, renovación, análisis, fiscalización anual, publicación u otra), con valor, vencimiento, frecuencia, fecha y valor del pago y comprobante (botón del clip). Al pagar una tasa recurrente se crea la próxima automáticamente. Los responsables reciben una alerta 30 días antes y el día del vencimiento; las vencidas y las que vencen en 30 días aparecen en Mis pendientes y en la Agenda. Una tasa vencida sin pago deja la licencia en Atención en la situación legal calculada.</p>';
$ptLic = '<p><strong>Taxas da licença</strong></p><p>O botão <strong>Taxas</strong> (grupo Associações) abre as taxas da licença selecionada: valor, vencimento, frequência, pagamento e comprovante. Taxa recorrente paga gera a próxima automaticamente; os responsáveis recebem alerta 30 dias antes e no dia do vencimento; taxa vencida e não paga deixa a licença em Atenção na situação legal calculada. Veja a Ajuda da tela de taxas.</p>';
$enLic = '<p><strong>License fees</strong></p><p>The <strong>Fees</strong> button (Associations group) opens the fees of the selected license. See the help of the fees screen.</p>';
$esLic = '<p><strong>Tasas de la licencia</strong></p><p>El botón <strong>Tasas</strong> (grupo Asociaciones) abre las tasas de la licencia seleccionada. Vea la Ayuda de la pantalla de tasas.</p>';
return [
    'manutencaolegal_licencas_taxas' => ['titulo' => $L('Ajuda — Taxas das licenças', 'Help — License fees', 'Ayuda — Tasas de las licencias'), 'texto' => $L($pt, $en, $es)],
    'manutencaolegal_licencas' => ['titulo' => $L('Ajuda — Licenças', 'Help — Licenses', 'Ayuda — Licencias'), 'texto' => $L($ptLic, $enLic, $esLic)],
];
