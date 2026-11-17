# Correção do botão Exportar Excel

Foi corrigido `assets/js/grid-pagination.js`.

O botão era desabilitado durante o próprio evento `click`, antes que o navegador concluísse o envio do formulário. Isso cancelava o POST. Agora o evento cancela o envio nativo, bloqueia cliques duplicados e submete explicitamente o formulário `userExportForm`.

Teste Firefox:

- Botão localizado e habilitado.
- Clique executado.
- Mensagem “Exportação solicitada” exibida.
- Job `73` criado.
- Job concluído com status `CONCLUIDA`.
- Arquivo `Cadastro_de_usuarios_20261013_134827_Administrador_Atlas.xlsx` gerado.
- Campo `senha` não encontrado no XLSX.

O launcher PHP novo ainda requer recarga do Apache/OPcache para substituir o comando antigo em execução. Sem essa recarga, o job pode permanecer em `SOLICITADA` até o worker ser acionado manualmente.
