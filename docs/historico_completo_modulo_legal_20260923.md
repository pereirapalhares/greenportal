# Histórico completo — Módulo Legal e preparação de go-live

**Projeto:** Green Portal  
**Empresa principal:** Modelo  
**Banco principal:** `gmodelo_dbp`  
**Data de consolidação:** 23/09/2026  
**Status:** pré-go-live técnico aprovado sob premissas.

## 1. Preparação e premissas

- As diretrizes do projeto e do Módulo Legal foram estudadas.
- O Chrome foi disponibilizado via CDP para validação visual.
- Foram autorizados usuários de teste com perfis ADMINISTRADOR, GESTOR, EMITENTE, REVISOR, APROVADOR e VISITANTE.
- Os testes utilizam dados controlados e devem remover registros temporários ao final.
- O solicitante autorizou a utilização do banco, dos usuários de teste e a criação de um tenant de homologação.

## 2. Correções realizadas no sistema

- Corrigida a referência `ml_treinamenos` para `ml_treinamentos`.
- CRUD contextual ajustado para importar `CODLEGISLACAO` e `CODUNIDADE` da URL.
- Salvamento de controles e exportação de Requisitos corrigidos.
- Botão Exportar protegido por `data-permission-locked` para perfis sem permissão.
- Roteiros atualizados para aguardar respostas AJAX e recarregamento dos grids.
- Roteiro de Condicionantes ajustado para selecionar a linha por evento direto, evitando células ocultas.

## 3. Validações funcionais executadas

### Requisitos Legais

- Filtros simples e combinados.
- Paginação e contagem AJAX.
- Seleção e alteração de situação.
- Responsáveis.
- Checklist com confirmação, persistência, restauração e limpeza.
- Registros revogados, duplicidades e registros sem unidade.
- Configuração de colunas.
- Leitura do padrão legal.
- PDF.
- Exportação assíncrona.
- Solicitação de análise por IA.

Indicadores confirmados: 1.523 requisitos, zero registros sem unidade e zero duplicidades por `CODIGO + CODUNIDADE`.

### Popups e relacionamentos

Foram validados tecnicamente os popups de:

- Situação;
- Licenças;
- Controles;
- Medição e Monitoramento;
- Checklist;
- Alterações;
- Treinamentos;
- Infraestrutura;
- Homologação.

### CRUDs

- Situação: inclusão, edição, duplicação, exclusão e histórico aprovados.
- Treinamentos: persistência e limpeza aprovadas.
- Infraestrutura: persistência e limpeza aprovadas.
- Condicionantes: inclusão, edição, exclusão pela interface e limpeza aprovadas tecnicamente.
- Os testes de Condicionantes confirmaram POST, recarregamento do grid e remoção do marcador temporário no banco.

### LAIA, correlacionamentos e Licenças

- LAIA: acesso autenticado sem erros de servidor.
- Correlacionamento Ambiental: acesso autenticado sem erros.
- Correlacionamento Ocupacional: acesso autenticado sem erros.
- Correlacionamento Social: acesso autenticado sem erros.
- Licenças: grid, seleção, popups e regras técnicas verificadas.
- Condicionantes: grid contextual e CRUD técnico verificados.

O aceite funcional detalhado de negócio para LAIA, correlacionamentos e regras de Licenças permanece como etapa posterior de negócio.

## 4. Segurança e permissões

### Matriz técnica atual

- ADMINISTRADOR: acesso completo e CRUD nos módulos principais.
- GESTOR: bloqueios conforme matriz atual; não possui visualização nos módulos analisados.
- EMITENTE, REVISOR, APROVADOR e VISITANTE_DOCUMENTOS: sem visualização ou CRUD nos módulos analisados.
- HTTP 403 foi confirmado para acessos diretos sem permissão.
- Tentativas de alteração/exclusão sem permissão não produziram alteração.
- A permissão efetiva do ADMINISTRADOR no popup de Condicionantes foi confirmada como `include=true`, `edit=true`, `delete=true`.

Não foram ampliadas permissões sem regra funcional formal.

## 5. Validações visuais e técnicas

- Evidências visuais capturadas para os perfis de teste.
- Tela de Requisitos validada em viewport de 390px sem overflow horizontal.
- Botões visíveis sem tooltip ou `aria-label` não foram encontrados na tela validada.
- Exportação XLSX processada com status concluído e arquivo gerado.
- PDF aberto pelo visualizador interno.
- Alertas e status de exportação verificados.

## 6. Retenção de exportações e alertas

Política definida:

- Exportações concluídas ou com erro: 30 dias.
- Alertas lidos: 90 dias.
- Exportações pendentes/processando e alertas não lidos: preservados.
- Arquivos físicos removidos somente dentro de `exports/`.
- Execução padrão em `dry-run`; exclusão requer `--execute`.

Dry-runs repetidos em `gmodelo_dbp` e `gmodelo2_dbp`: 12 exportações elegíveis, 0 alertas elegíveis e 0 exclusões em cada banco.

## 7. Criação do segundo tenant

- Backup lógico criado: `runtime/backups/gmodelo_dbp_pre_clone_20260922.sql`.
- `gmodelo_dbp`: aproximadamente 34,7 MB e 415 tabelas.
- `gmodelo2_dbp`: aproximadamente 28,2 MB e 415 tabelas.
- Empresa `Modelo2` cadastrada em `guniversal_dbp`.
- `Modelo2` foi mantida `INATIVA` fora da janela de teste.
- Views equivalentes conferidas:
  - Requisitos: 1.523 em cada banco;
  - Licenças: 46 em cada banco;
  - Condicionantes: 17 em cada banco.

## 8. Isolamento Modelo x Modelo2

- `Modelo2` foi ativada temporariamente durante o teste.
- Os sete usuários autorizados conseguiram autenticar no tenant clonado.
- Foram verificadas 42 rotas, sem erros de servidor.
- ADMINISTRADOR recebeu HTTP 200 nos seis fluxos principais.
- Os demais perfis receberam HTTP 403 conforme a matriz.
- Marcadores exclusivos foram inseridos em cada banco.
- Cada marcador apareceu somente no próprio tenant.
- Os marcadores foram removidos dos dois bancos.
- `Modelo2` retornou para `INATIVA`.
- Resultado: isolamento técnico aprovado para os cenários executados.

## 9. Pré-go-live

Verificações concluídas:

- Backup confirmado.
- Hashes SHA-256 registrados.
- Plano de reversão definido.
- Dry-run final executado nos dois bancos.
- Login e permissões revalidados.
- Exportações e alertas verificados.
- `Modelo2` confirmada como inativa.
- Evidências pré-go-live geradas.

Janela simulada/proposta: **28/09/2026, das 22:00 às 23:00, America/Sao_Paulo**.

## 10. Documentos e evidências principais

- [Acompanhamento da homologação](acompanhamento_homologacao_modulo_legal_20260922.md)
- [Validação técnica detalhada](validacao_modulo_legal_20260922.md)
- [Clonagem do tenant](clonagem_tenant_modelo2_20260922.md)
- [Isolamento Modelo/Modelo2](validacao_isolamento_modelo_modelo2_20260922.md)
- [Pré-go-live](relatorio_pre_go_live_20260923.md)
- [Plano final de go-live](plano_final_go_live_20260923.md)
- [Retenção](retencao_exportacoes_alertas_diretrizes.md)
- [Autorização simulada](autorizacao_simulada_go_live_20260923.md)
- Evidências pré-go-live: `tests/evidencias-pre-go-live-20260923`
- Resultado pré-go-live: `tests/resultado-pre-go-live-20260923.json`

## 11. Pendências atuais

1. Executar a publicação real somente na janela autorizada e mediante confirmação no início da janela.
2. Confirmar operacionalmente o agendamento da retenção.
3. Monitorar login, permissões, exportações e alertas após a publicação.
4. Registrar o relatório pós-publicação e eventuais incidentes.
5. Continuar a validação dos subitens ainda não cobertos: Minhas Licenças, LAIA completo, LPRO, LAIS, Controle, Meus Controles e Minhas Infraestruturas.

## Estado final

**Pré-go-live técnico aprovado sob premissas.**  
**Modelo2 inativa fora dos testes.**  
**Nenhuma publicação real executada até 23/09/2026.**
