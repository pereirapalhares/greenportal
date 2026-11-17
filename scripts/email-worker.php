<?php
// Serviço de e-mail (migração 333): envia a fila email_fila de cada empresa por SMTP (.env: SMTP_*).
// Agendador do Windows: \GreenSolutions\GreenPortal-EmailWorker, a cada 2 minutos, como SYSTEM (scripts\criar-agendamento-email.ps1).
// Teste manual: E:\wamp\bin\php\php8.4.15\php.exe scripts\email-worker.php [--database=gcrown_dbp] [--dry-run]
$argv[] = '--service=email'; require __DIR__ . '/windows-service.php';
