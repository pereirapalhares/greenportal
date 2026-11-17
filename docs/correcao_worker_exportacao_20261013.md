# Correção do worker de exportação

Data: 13/10/2026

## Alteração

Foi criado `classes/ExportWorkerLauncher.php`, que executa o `ExportacaoGridWorker.php` no Windows usando o `php.exe` da instalação PHP e uma chamada compatível com `cmd /D /C start /B`. A transação `principal/seguranca_usuarios.php` passou a utilizar esse launcher.

O comando registra a saída em `runtime/export-worker.log`, facilitando o diagnóstico de falhas.

## Testes

- Jobs 71 e 72 processados automaticamente pelo launcher Windows.
- Status final: `CONCLUIDA`.
- Arquivos gerados:
  - `exports/Cadastro_de_usuarios_20261013_134307_Raquel_Ferrari.xlsx`
  - `exports/Cadastro_de_usuarios_20261013_134455_Raquel_Ferrari.xlsx`
- Validação dos XLSX: campo `senha` ausente.

## Observação do ambiente

O Apache ativo não pôde ser reiniciado pelo usuário atual por falta de privilégio para parar o serviço `wampapache64`. Por isso, a chamada feita pelo navegador continuou utilizando o código/cache anterior durante o teste. Após reiniciar o Apache ou recarregar o PHP-FPM/OPcache, deve ser repetido o teste visual para confirmar o acionamento a partir da tela.
