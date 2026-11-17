<?php
// Ajuda da tela Administração › Backup (migração 351). Só p, ul/ol/li, strong/b, em/i e a.
return [
    'titulo' => ['pt' => 'Backup', 'en' => 'Backup', 'es' => 'Copia de seguridad'],
    'texto' => [
        'pt' => '<p><strong>Backup automático do GreenPortal.</strong> Todo dia, no horário configurado, o serviço <strong>GreenPortal-Backup</strong> do Agendador do Windows faz:</p>'
            . '<ul><li><strong>Bancos:</strong> todos os bancos *_dbp (empresas, universal e legislação), com estrutura, dados, procedures, eventos e gatilhos, numa cópia consistente, compactados (.sql.gz).</li>'
            . '<li><strong>Aplicação:</strong> a pasta do GreenPortal em um .zip, com o .env (por isso o destino precisa ser protegido). Ficam de fora backups, exports, runtime e storage.</li>'
            . '<li><strong>GED:</strong> um espelho dos arquivos do GED do portal e do GED do sistema anterior. A cada dia só são copiados os arquivos novos ou alterados; um arquivo apagado no GED continua no espelho.</li>'
            . '<li><strong>Manifesto:</strong> MANIFEST.txt com o tamanho e o SHA-256 de cada arquivo do backup.</li></ul>'
            . '<p><strong>Quantos ficam:</strong> os backups diários mais recentes (padrão 14) e, além deles, o último backup de cada semana (padrão 8 semanas). Os demais são apagados pelo serviço. O espelho do GED não é apagado.</p>'
            . '<p><strong>Retenção:</strong> depois do backup, em todas as empresas ativas, são apagadas as exportações para Excel concluídas ou com erro há mais de 30 dias (com o arquivo) e os alertas já lidos há mais de 90 dias. Exportações em andamento e alertas não lidos nunca são apagados.</p>'
            . '<p><strong>Destino:</strong> a pasta BACKUP_DIR do .env, de preferência outro disco ou um compartilhamento de rede. Sem ela, os backups ficam dentro da aplicação, no mesmo disco, e a tela avisa que não estão protegidos.</p>'
            . '<p><strong>Avisos:</strong> se o backup falhar, ou se o último backup concluído tiver mais de 26 horas, a tela mostra o aviso e os administradores recebem um alerta.</p>'
            . '<p><strong>Restauração:</strong> teste de tempos em tempos, em um servidor separado: descompacte o .sql.gz e importe no MySQL; confira os arquivos pelo MANIFEST.txt.</p>',
        'en' => '<p><strong>GreenPortal automatic backup.</strong> Every day, at the configured time, the Windows Task Scheduler service <strong>GreenPortal-Backup</strong> does:</p>'
            . '<ul><li><strong>Databases:</strong> every *_dbp database (companies, universal and legislation), with structure, data, procedures, events and triggers, in a consistent copy, compressed (.sql.gz).</li>'
            . '<li><strong>Application:</strong> the GreenPortal folder in a .zip, including .env (so the destination must be protected). backups, exports, runtime and storage are left out.</li>'
            . '<li><strong>GED:</strong> a mirror of the portal GED files and of the previous system GED. Each day only new or changed files are copied; a file deleted from the GED stays in the mirror.</li>'
            . '<li><strong>Manifest:</strong> MANIFEST.txt with the size and SHA-256 of each backup file.</li></ul>'
            . '<p><strong>How many are kept:</strong> the most recent daily backups (default 14) plus the last backup of each week (default 8 weeks). The others are removed by the service. The GED mirror is never removed.</p>'
            . '<p><strong>Retention:</strong> after the backup, in every active company, Excel exports finished or failed more than 30 days ago (with the file) and alerts read more than 90 days ago are deleted. Exports in progress and unread alerts are never deleted.</p>'
            . '<p><strong>Destination:</strong> the BACKUP_DIR folder in .env, preferably another disk or a network share. Without it, backups stay inside the application, on the same disk, and the screen warns that they are not protected.</p>'
            . '<p><strong>Warnings:</strong> if the backup fails, or the last finished backup is more than 26 hours old, the screen shows a warning and administrators receive an alert.</p>'
            . '<p><strong>Restore:</strong> test it from time to time on a separate server: uncompress the .sql.gz and import it into MySQL; check the files against MANIFEST.txt.</p>',
        'es' => '<p><strong>Copia de seguridad automática del GreenPortal.</strong> Todos los días, en el horario configurado, el servicio <strong>GreenPortal-Backup</strong> del Programador de tareas de Windows hace:</p>'
            . '<ul><li><strong>Bases de datos:</strong> todas las bases *_dbp (empresas, universal y legislación), con estructura, datos, procedimientos, eventos y disparadores, en una copia consistente, comprimidas (.sql.gz).</li>'
            . '<li><strong>Aplicación:</strong> la carpeta del GreenPortal en un .zip, con el .env (por eso el destino debe estar protegido). Quedan fuera backups, exports, runtime y storage.</li>'
            . '<li><strong>GED:</strong> un espejo de los archivos del GED del portal y del GED del sistema anterior. Cada día solo se copian los archivos nuevos o modificados; un archivo borrado del GED sigue en el espejo.</li>'
            . '<li><strong>Manifiesto:</strong> MANIFEST.txt con el tamaño y el SHA-256 de cada archivo de la copia.</li></ul>'
            . '<p><strong>Cuántas se guardan:</strong> las copias diarias más recientes (por defecto 14) y, además, la última copia de cada semana (por defecto 8 semanas). Las demás las borra el servicio. El espejo del GED no se borra.</p>'
            . '<p><strong>Retención:</strong> después de la copia, en todas las empresas activas, se borran las exportaciones a Excel concluidas o con error hace más de 30 días (con el archivo) y las alertas leídas hace más de 90 días. Las exportaciones en curso y las alertas no leídas nunca se borran.</p>'
            . '<p><strong>Destino:</strong> la carpeta BACKUP_DIR del .env, preferiblemente otro disco o una carpeta de red. Sin ella, las copias quedan dentro de la aplicación, en el mismo disco, y la pantalla avisa que no están protegidas.</p>'
            . '<p><strong>Avisos:</strong> si la copia falla, o si la última copia concluida tiene más de 26 horas, la pantalla muestra el aviso y los administradores reciben una alerta.</p>'
            . '<p><strong>Restauración:</strong> pruébela de vez en cuando en un servidor separado: descomprima el .sql.gz e impórtelo en MySQL; verifique los archivos con el MANIFEST.txt.</p>',
    ],
];
