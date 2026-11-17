<?php
// Taxímetro de IA (migração 340): grava a cotação PTAX do dia (Banco Central) no universal e envia os avisos do limite mensal de IA
// (80%, limite atingido e bloqueio) aos ADM DA EMPRESA e ao ADMINISTRADOR de cada empresa, por alerta e e-mail.
// Agendador do Windows: \GreenSolutions\GreenPortal-IaConsumo, a cada 30 minutos, como SYSTEM (scripts\criar-agendamento-ia-consumo.ps1).
// Teste manual: E:\wamp\bin\php\php8.4.15\php.exe scripts\ia-consumo-worker.php [--database=gcrown_dbp] [--dry-run]
$argv[] = '--service=iaconsumo'; require __DIR__ . '/windows-service.php';
