# Gestão de taxas das licenças (commit c557, migração 344 — 23/10/2026)

Último item da Onda 1 da apresentação Atlas.

## Decisões do usuário
- Várias taxas por licença: tipo (emissão, renovação, análise, fiscalização anual, publicação, outra), descrição, nº da guia, valor, vencimento (obrigatório), frequência, pago em, valor pago e comprovante.
- Recorrente: ao registrar o pagamento, o sistema cria a próxima (vencimento + frequência, mesmo valor; Origem = Automática). Se a próxima for excluída, não volta (PROXIMA_GERADA).
- Aviso: agenda e Minhas pendências do responsável da licença; alerta 30 dias antes e no dia do vencimento (até 3 dias depois, se o serviço ficou parado). Os alertas viram e-mail pela 343.
- Taxa vencida e não paga deixa a licença válida em **Atenção** na situação legal calculada (284), com o motivo "N taxa(s) vencida(s) sem pagamento (a mais antiga em dd/mm/aaaa)".

## Como funciona
- A taxa fica na licença original (CODLICENCA_BASE) e continua aparecendo depois da renovação. Unidade, descrição e responsável vêm da versão ativa.
- Tela `principal/manutencaolegal_licencas_taxas.php` (ManutencaoLegalCadastroGrid, menu LICENCAS_TAXAS ao lado de Licenças):
  - pelo botão Taxas do cadastro de Licenças (grupo Associações): as taxas da licença;
  - pelo menu: todas as taxas (a licença é escolhida na inclusão);
  - `?codtaxa=N` é o link dos alertas.
- Comprovante: botão do clipe → gestao_arquivos tipo `licencataxas` (pasta li_taxas, permissão LICENCAS_TAXAS ou LICENCAS).
- ManutencaoLegalCadastroGrid ganhou os ganchos `before_save` (ajusta as linhas: valor 1.234,56 → 1234.56; campo vazio não vai ao CRUD, que gravaria '' em DATE/DECIMAL) e `after_save` (sp_li_taxa_limpar para campos apagados e sp_li_taxas_gerar_proximas).
- Serviço de responsabilidades (windows-service.php, `licencaTaxas()`): sp_li_taxas_gerar_proximas e sp_li_taxas_avisar(0).
- Valores em texto: FORMAT(..., 'de_DE'), porque o pt_BR do MySQL não tem separador de milhar.

## Banco
- Tabela li_taxas, com gatilhos de validação (tipo e frequência fora da lista viram OUTRA e UNICA; pago sem valor pago assume o valor).
- Procedures: sp_li_taxas_gerar_proximas, sp_li_taxas_avisar(simular), sp_li_taxa_limpar.
- Views montadas por `classes/LicencaTaxa.php`: vw_li_taxas_grid, vw_li_taxas_licencas, vw_li_taxas_responsaveis (vw_minhas_licencas_grid), vw_minhas_taxas, vw_li_taxas_avisos, vw_dash_li_taxas.
- A 284 ganhou a regra da taxa em `areaLicencas` e os gatilhos trg_sl_litax_ai/au/ad. O runner da 344 reaplica a 284 em cada empresa, e a 284 reaplica a 341.

## Telas que mudaram
- Licenças: botão Taxas.
- MinhasPendencias: vencidas e as que vencem em até 30 dias. Com isso, as taxas entram também na Agenda.
- Dashboard da empresa, aba Licenças: cartões (vencidas, 30 dias, a pagar, pagas no ano, com R$) e a lista das que pedem ação.
- Ajuda: tela nova e nota na Ajuda de Licenças (marcador ml344).

## Aplicar
`E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-344-licencas-taxas.php --simular`, depois sem `--simular` (opção `--sem-284`).

## Testado localmente
- Inclusão (licença do contexto e escolhida na lista), formato do valor e valor inválido.
- Pagamento que gera a próxima (anual e semestral), sem duplicar.
- Desfazer o pagamento.
- Alertas 30 dias e no dia, sem repetir.
- Pendências por responsável direto e por atribuição.
- Situação Atenção com o motivo e o gatilho que enfileira o recálculo.
- Dashboard.
- Migração repetida e empresa sem licenças (Luxottica fica de fora).
