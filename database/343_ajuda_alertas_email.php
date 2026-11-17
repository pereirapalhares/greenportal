<?php
declare(strict_types=1);

// Migração 343 — Ajuda dos alertas por e-mail (trecho entre <!-- ml343 --> no fim do texto atual de cada transação).
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$pt = '<p><strong>Alertas por e-mail</strong></p>'
    . '<p>Os alertas do portal (o sino) também chegam no seu e-mail, do jeito que você escolher em <strong>Minha conta › Alertas por e-mail</strong> (menu do seu nome, no canto de cima):</p><ul>'
    . '<li><strong>Na hora</strong> (padrão): um e-mail logo depois do alerta; os que chegam juntos vêm no mesmo e-mail;</li>'
    . '<li><strong>Resumo diário às 7h</strong>: um e-mail por dia com os alertas que você ainda não leu no portal;</li>'
    . '<li><strong>Não receber</strong>: os alertas continuam só no sino.</li></ul>'
    . '<p>Cada e-mail traz o botão <strong>Abrir no portal</strong>: se você não estiver logado, entra com o seu login e a tela do alerta abre como aba (o alerta fica marcado como lido). Se você estiver logado em outra empresa, o portal pede para entrar na empresa do alerta.</p>'
    . '<p>Não vão por e-mail: os avisos de "Exportação iniciada" (o de exportação concluída vai) e alertas com mais de um dia (por exemplo, se o serviço ficou parado). Os alertas anteriores à ativação do envio não são mandados.</p>'
    . '<p>É preciso ter um e-mail válido no seu cadastro de usuário (Segurança › Usuários). O envio é feito pelo serviço de e-mail do Agendador a cada 2 minutos; os e-mails ficam em Administração › E-mails enviados.</p>';
$en = '<p><strong>Alerts by e-mail</strong></p><p>Portal alerts also reach your e-mail as you choose in <strong>My account › Alerts by e-mail</strong>: <strong>right away</strong> (default), <strong>daily summary at 7 a.m.</strong> (unread alerts) or <strong>do not receive</strong>. Each e-mail has an <strong>Open in the portal</strong> button: sign in if needed and the alert screen opens as a tab. "Export started" alerts and alerts older than one day are not e-mailed. A valid e-mail in your user record is required.</p>';
$es = '<p><strong>Alertas por correo</strong></p><p>Las alertas del portal también llegan a su correo como elija en <strong>Mi cuenta › Alertas por correo</strong>: <strong>en el momento</strong> (predeterminado), <strong>resumen diario a las 7h</strong> (alertas no leídas) o <strong>no recibir</strong>. Cada correo trae el botón <strong>Abrir en el portal</strong>: entre si es necesario y la pantalla de la alerta abre como pestaña. Las alertas de "Exportación iniciada" y las de más de un día no se envían. Se necesita un correo válido en su usuario.</p>';
return [
    'minha_conta_alertas_email' => ['titulo' => $L('Ajuda — Alertas por e-mail', 'Help — Alerts by e-mail', 'Ayuda — Alertas por correo'), 'texto' => $L($pt, $en, $es)],
    'home_alertas' => ['titulo' => $L('Ajuda — Alertas', 'Help — Alerts', 'Ayuda — Alertas'), 'texto' => $L($pt, $en, $es)],
];
