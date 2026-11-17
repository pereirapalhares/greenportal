@echo off
setlocal
cd /d "%~dp0.."
if not exist storage\logs\migracao mkdir storage\logs\migracao
if not exist "E:\wamp\bin\php\php8.4.15\php.exe" (
    echo [%date% %time%] ERRO: PHP nao encontrado>> storage\logs\migracao\worker.log
    endlocal & exit /b 1
)
E:\wamp\bin\php\php8.4.15\php.exe scripts\migracao-prompts-worker.php >> storage\logs\migracao\worker.log 2>&1
E:\wamp\bin\php\php8.4.15\php.exe scripts\migracao-worker.php >> storage\logs\migracao\worker.log 2>&1
E:\wamp\bin\php\php8.4.15\php.exe scripts\migracao-relatorio-prompts.php >> storage\logs\migracao\worker.log 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
if not "%EXIT_CODE%"=="0" echo [%date% %time%] Worker finalizado com erro: %EXIT_CODE%>> storage\logs\migracao\worker.log
endlocal & exit /b %EXIT_CODE%
