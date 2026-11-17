# Regras automáticas de situação dos documentos (legado) × greenportal

Análise de 17/10/2026. Fontes (servidor, E:\projetoGreen\processos):
- `enviadoodocumento\enviadoodocumento.php` (rodado por `enviadoodocumento.bat`, Agendador do Windows): documentos de fornecedor e de funcionário, em todos os bancos green_*.
- `homologacaodefornecedores\homologacao.php`: vencimento e protocolo dos documentos de fornecedor (origem da situação "Aguardando vencimento do protocolo").
- `pendencias\pendencias.php`: pendências de planos de ação (MM, NC…) — não trata os documentos da homologação.
- Página `www\configuracoes\fo_auditoriacriterios.php`: cria os documentos ao abrir a aba (só o 1º tipo; ver comparação de geração).

**Segurança:** os três processos têm a senha do root do MySQL escrita no código. Recomenda-se trocar a senha e ler de um arquivo de configuração fora do código.

## 1. Documentos de fornecedor — enviadoodocumento.php (situação ≠ NA)
Em ordem:
1. Aprovado + validade vencida + fora de protocolo → "Pendente com o fornecedor" (obs. "Data de vencimento expirada").
2. Pendente com o fornecedor + validade futura + Enviado = Sim + fora de protocolo → "Pendente com a empresa" (obs. "Data de vencimento alterada pelo fornecedor").
3. Para cada documento:
   - Reprovado → "Pendente com o fornecedor".
   - Tem arquivo no GED (fo_fornecedorxcriterios\<código>) → Enviado = Sim; sem arquivo → Enviado = Não.
   - Sem responsável da empresa ou do fornecedor, validade futura, situação não aprovada → "Pendente com a empresa".
   - Com arquivo:
     - Aprovado/Ciente sem validade, ou NA → nada;
     - validade vencida (e não Aprovado/Ciente/NA) → "Pendente com o fornecedor";
     - Pendente com o fornecedor, sem validade ou validade a mais de 30 dias, e a última alteração (histórico) foi de um usuário do fornecedor → "Pendente com a empresa" ("critério atualizado pelo fornecedor").
   - Sem arquivo → "Pendente com o fornecedor" ("Não tem documento anexado") — inclusive Aprovado sem validade, Ciente e "Não enviar ao fornecedor".
- O texto do motivo é concatenado na observação (e na do fornecedor). A regra só é aplicada se a observação ainda não contém aquele texto, ou seja, cada motivo dispara uma única vez na vida do documento.

## 2. Documentos de fornecedor — homologacao.php
- Validade vencida + Enviado = Sim (≠ NA, ≠ Não enviar) → "Pendente com o fornecedor", observação substituída por "Data de validade vencida" (perde a observação da empresa; vale também para Aprovado, Reprovado, Pendente com a empresa).
- Protocolo com cobrança vencida → "Pendente com o fornecedor" (obs. "Protocolo vencido"); o protocolo continua S.
- Protocolo com cobrança futura → "Aguardando vencimento do protocolo".
- Protocolo S sem data de cobrança: não altera.

## 3. Documentos de funcionário — enviadoodocumento.php (atende ≠ NAP, Afastado, Desligado)
- Com arquivo e sem responsáveis → Atende = Sim, situação "Pendente com a empresa".
- Com arquivo → Atende = Sim, "Tem anexo e data de validade aceita", se `validade >= hoje OR ano(validade) > 0`. **Defeito:** o "OR ano > 0" aceita qualquer data, então documento vencido com arquivo fica "Sim" (só green_crown usa ano = 0). As regras seguintes (vence em 0–30 dias → Não/Pendente com a empresa; vencido → Não/Pendente com o fornecedor) praticamente nunca são alcançadas.
- Sem arquivo → Atende = Não, "Pendente com o fornecedor" (exceto green_luxottica).
- A observação é sobrescrita a cada execução.

## 4. Greenportal antes da migração 301
- Cor única calculada na view (vencido = vermelho, vence nos dias de reação = amarelo), sem mudar a situação gravada.
- Protocolo: migração 300 (gravação + serviço diário homologacao-protocolo-worker.php) — equivalente ao homologacao.php, com histórico, alerta e encerramento do protocolo.
- Pendências: Home do fornecedor; Aprovações da empresa; Painel de Homologação; resumo semanal.
- Não tinha as mudanças automáticas de situação do enviadoodocumento.php.

## 5. Implementado no greenportal (c410/c412, migração 301 — 17/10/2026)
Pedido do usuário: "as mesmas regras, adaptadas ao greenportal; todo serviço novo é um PHP no Agendador do Windows".
- `database/301_regras_documentos_homologacao.sql`: tabela `fo_regras_documentos` (cada regra pode ser desligada por empresa), log `fo_regras_documentos_log` (+ views `vw_fo_regras_documentos`, `vw_fo_regras_documentos_log`), procedures `sp_fo_regras_documentos_listar(escopo)` e `sp_fo_regras_documentos_aplicar(escopo, contagens JSON, aplicar, usuário)`.
- `scripts/homologacao-documentos-worker.php` (Agendador, a cada 60 min, tarefa \GreenSolutions\GreenPortal-DocumentosHomologacao, criada por `scripts/criar-agendamento-documentos-homologacao.ps1`): conta os arquivos no GED (mesmas pastas do legado, via GedStorageService), aplica, grava histórico/log e alerta os responsáveis. Opções `--simular`, `--sem-alertas`, `--banco=`, `--ged=`.
- `scripts/aplicar-migracao-301-regras-documentos-homologacao.php`: instala nos 3 bancos e registra no catálogo de Serviços (universal) HOMOLOGACAO_DOCUMENTOS, HOMOLOGACAO_PROTOCOLO e HOMOLOGACAO_RESUMO.
- Protocolo em vigor continua com o serviço da migração 300.

Regras (a primeira que casar; testadas em MySQL 8 — a segunda execução não muda nada):

| Regra | Quando | Resultado |
|---|---|---|
| FORN_ENVIADO | sempre (menos NA) | Enviado = Sim se há arquivo no GED, senão Não |
| FORN_VENCIDO | validade vencida, não está com o fornecedor nem reprovado | Pendente com o fornecedor |
| FORN_SEM_ARQUIVO | sem arquivo (menos Aprovado sem validade, Ciente, Reprovado, já com o fornecedor e o caso "sem responsável com validade futura") | Pendente com o fornecedor |
| FORN_SEM_RESPONSAVEL | sem responsável da empresa ou do fornecedor, validade futura, não aprovado | Pendente com a empresa |
| FORN_ATUALIZADO | com arquivo, não vencido, pendente com o fornecedor/reprovado/sem situação, e o fornecedor agiu depois da última ação da empresa (alteração de usuário do fornecedor no histórico ou arquivo mais novo) | Pendente com a empresa |
| FUNC_SEM_ARQUIVO | sem arquivo | Atende = Não; Pendente com o fornecedor |
| FUNC_VENCIDO | validade vencida | Atende = Não; Pendente com o fornecedor |
| FUNC_SEM_RESPONSAVEL | sem responsável da empresa ou do fornecedor | Atende = Sim; Pendente com a empresa |
| FUNC_ATENDE | resto | Atende = Sim; sem pendência |

Ignorados sempre: NA, "Não enviar ao fornecedor", "Aguardando vencimento do protocolo", protocolo em vigor (Em protocolo = Sim com cobrança de hoje em diante ou sem data); funcionário NAP/Afastado/Desligado e funcionário inativo. **Protocolo com cobrança já vencida** (carga do legado: o homologacao.php deixa Em protocolo = Sim depois de vencer) segue as regras normais (correção c412).

Adaptações em relação ao legado: motivo no log/histórico/alerta (não concatena nas observações); decisão pelo estado atual (não pelo texto na observação); Reprovado continua Reprovado até o fornecedor agir (no legado a reprovação voltava para a empresa no dia seguinte se houvesse arquivo e validade futura); funcionário vencido com arquivo passa a "Não" (corrige o defeito do legado).
Mantido do legado: o "Atende" do funcionário é recalculado pelo GED e pode sobrescrever um "Não" manual quando há arquivo válido.

## 6. Primeira execução (17/10/2026 13:50, --sem-alertas) e tarefa criada (a cada 60 min)
- **Atlas** (GED green_atlas: 224 de 557 documentos de fornecedor e 1.626 de 1.750 de funcionário com arquivo) — resultado coerente: fornecedor 152 mudanças (29 sem situação com arquivo → Pendente com a empresa; 109 sem arquivo → Pendente com o fornecedor; Enviado ajustado); funcionário 855 (343 → atende/sem pendência; 437 vencidos → Pendente com o fornecedor; 75 sem arquivo).
- **MODELO e MODELO2** (GED green_modelo: só 1 documento de fornecedor e nenhum de funcionário com arquivo; a pasta fo_funcionarioxtiposxcriterios nem existe) — quase tudo virou "Pendente com o fornecedor": 1.404 sem situação, 47 Aprovado, 29 Pendente com a empresa, 13 vencidos; funcionário 1.528. Decisão do usuário: deixar assim (base de teste; é o que o legado também faria sem os arquivos).
- **Para a virada:** as regras dependem do GED ter os arquivos de cada documento. Se o GED de produção do legado não for o mesmo E:\projetoGreen\GED, copiar antes de ligar o serviço; senão documentos aprovados voltam para o fornecedor por "sem arquivo".

## 7. Protocolo sem data de cobrança (migração 302 — 17/10/2026)
- Decisão do usuário: documentos "Em protocolo" = Sim sem data de cobrança → "Aguardando vencimento do protocolo", cobrança em 30 dias. `scripts/aplicar-migracao-302-protocolo-sem-cobranca.php` (procedure `sp_fo_protocolo_sem_cobranca_converter`, histórico + log PROTOCOLO_SEM_COBRANCA).
- Resultado: 2 na MODELO e 2 na MODELO2 (cobrança 16/11/2026); 0 na Atlas. Os demais documentos com Em protocolo = Sim (77 na MODELO) já tinham a cobrança vencida — tratados pela correção c412 da seção 5.
- Situação da MODELO depois: Pendente com o fornecedor 5.170, NA 334, Não enviar 45, Pendente com a empresa 8, Aprovado sem validade 7, Aprovado 4, Reprovado 2, Aguardando protocolo 2, Ciente 1. Atlas: Pendente com o fornecedor 403, Pendente com a empresa 73, Aprovado 59, Aprovado sem validade 20, NA 5, sem situação 2.
