<?php
// Lembretes de Não Conformidades (migração 339): aplica as regras ligadas em Administração › Configuração do módulo (alertas e e-mails).
// Agendador do Windows: \GreenSolutions\GreenPortal-NcLembretes, a cada 60 minutos, como SYSTEM (scripts\criar-agendamento-nc-lembretes.ps1).
// Teste manual: E:\wamp\bin\php\php8.4.15\php.exe scripts\nc-lembretes-worker.php [--database=gcrown_dbp] [--dry-run]
$argv[] = '--service=nc'; require __DIR__ . '/windows-service.php';
