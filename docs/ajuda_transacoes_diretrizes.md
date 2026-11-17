# Ajuda das transações

## Funcionamento

- A ajuda é armazenada em `guniversal_dbp.ajuda_transacoes`.
- Cada registro é identificado pelo campo `transacao` usado pela tela.
- Os textos possuem versões em português, inglês e espanhol.
- O botão padrão é emitido por `components/botoes_crud.php` quando a opção `transaction` é informada.
- A visualização ocorre no popup comum por `principal/setup_ajuda.php`.
- A edição fica em **Configurações > Ajuda das transações**, usando `principal/configuracoes_ajuda.php`.

## Formatação permitida

O editor permite negrito, itálico, listas e links externos. Os links devem usar `http://` ou `https://` e abrem em uma nova instância do navegador. O servidor remove tags HTML não autorizadas e normaliza os links antes de gravar.

## Controle e auditoria

O acesso de visualização e edição é controlado pela transação `CONFIG_AJUDA_TRANSACOES`. A carga inicial e as cargas complementares foram registradas em `gmodelo_dbp.registros_migracao` com os códigos `AJUDA_TRANSACOES_066` e `AJUDA_TRANSACOES_067`. A correção de acentuação UTF-8 é executada por `database/068_corrigir_acentuacao_ajuda.php`.
