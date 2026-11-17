@echo off
setlocal EnableDelayedExpansion
REM Sincronismo diario legado -> novo: tabelas fo_* (Homologacao de Fornecedores), Modulo Legal das empresas e legislacao central.
REM O relatorio de cada execucao fica em storage\logs\sincronismo_fo_AAAAMMDD_HHMMSS.json
REM O --aplicar faz a sua propria pre-validacao: banco com erro fica de fora e os outros sao sincronizados.
cd /d E:\wamp\apps\greenportal
if not exist storage\logs mkdir storage\logs
forfiles /p "E:\wamp\apps\greenportal\storage\logs" /m "sincronismo_fo_*.json" /d -30 /c "cmd /c del /q @path" >nul 2>&1
if not exist "E:\wamp\bin\php\php8.4.15\php.exe" (
    echo [%date% %time%] ERRO: PHP nao encontrado>> storage\logs\sincronismo_fo_erros.log
    exit /b 1
)
E:\wamp\bin\php\php8.4.15\php.exe scripts\sincronizar-fo-legado.php --aplicar >> storage\logs\sincronismo_fo_worker.log 2>> storage\logs\sincronismo_fo_erros.log
set "EXIT_CODE=!ERRORLEVEL!"
if not "!EXIT_CODE!"=="0" echo [%date% %time%] Sincronismo terminou com erro em algum banco (codigo !EXIT_CODE!); veja o ultimo storage\logs\sincronismo_fo_*.json>> storage\logs\sincronismo_fo_erros.log
endlocal & exit /b %EXIT_CODE%
