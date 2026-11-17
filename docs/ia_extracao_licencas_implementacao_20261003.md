# Extração de dados de Licenças com IA

Data: 03/10/2026  
Transação: `manutencaolegal_licencas`

## Fluxo de uso

1. Abra a transação **Licenças**.
2. Selecione uma licença e abra **Arquivos**.
3. Selecione um arquivo PDF.
4. Clique em **Analisar com IA**.
5. O navegador extrai o texto do PDF e envia somente o texto ao endpoint protegido.
6. A IA retorna uma sugestão com descrição, validade, atividade, órgão emissor, número, observações, confiança e alertas.
7. Revise os dados no modal.
8. Clique em **Confirmar e gravar**.

Sem a confirmação do usuário, nenhum campo da licença é alterado.

## Implementação

- `classes/InteligenciaArtificialLicenca.php`: integração com o provedor e normalização do JSON.
- `principal/api/licencas_ia.php`: validação de sessão, tenant, licença, arquivo, permissão e confirmação.
- `principal/gestao_arquivos.php`: botão, leitura do PDF com PDF.js e modal de revisão.
- `database/173_ia_extracao_licencas_campos.sql`: campos `ORGAO_EMISSOR` e `NUMERO_LICENCA`, além da atualização da view.
- `li_licencas.DADOS_DOCUMENTO_IA`: guarda o JSON da sugestão confirmada, arquivo, usuário e data.

## Segurança e isolamento

- A licença precisa existir no tenant atual.
- O arquivo precisa estar na pasta GED da empresa e dentro da pasta da licença.
- Somente usuários com visualização e edição de Licenças podem confirmar dados.
- O endpoint exige o CSRF da Gestão de Arquivos.
- O processamento da IA não define unidade, empresa, usuário, status ou permissões.
- O processamento de Potenciais IA não foi alterado.

## Limitações

- A leitura depende de o PDF possuir texto selecionável. PDFs exclusivamente digitalizados exigem OCR, que ainda não faz parte deste fluxo.
- A IA gera sugestão; a decisão final permanece com o usuário.
