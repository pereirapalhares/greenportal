# Não Conformidades › Administração — Fase 4: Configuração do módulo (c549, migração 339, 23/10/2026)

Continuação de claude/proposta-administracao-nc.md (fases 1 a 3).

- **Fase 4 (c549, migração 339, 23/10/2026; decisões do usuário: as três partes; campos do legado só consulta):**
  - **Tela:** Não Conformidades › Administração › **Configuração › Configuração do módulo** (principal/naoconformidades_configuracao.php).
    - Menu NAO_CONFORMIDADES_CONFIGURACAO no subgrupo NAO_CONFORMIDADES_ADM_CONFIGURACAO; permissão só ADMINISTRADOR.
    - Abas: Campos por tipo, Parâmetros, Lembretes, Lembretes enviados e Histórico (nc_admin_historico, objeto CONFIGURACAO).
  - **Campos por tipo:**
    - Os campos que cada tipo mostra continuam vindo do legado (nc_tipo_de_naoconformidadexcampos), só consulta.
    - Marca-se o que o greenportal **exige no envio** (nc_config_obrigatorios), além das regras fixas ("Regra fixa" na tela; as do legado continuam em sp_nc_ocorrencia_salvar).
    - Validação: sp_nc_config_validar_envio, chamada pela tela depois de sp_nc_ocorrencia_salvar, na mesma transação. Se falta algo, nada é gravado.
    - Os campos exigidos ganham * no formulário.
  - **Parâmetros (nc_config_parametros):**
    - EFICACIA_EXIGE_PRAZO e LISTAS_FORNECEDOR_SEPARADAS: antes fixos para a Crown; o padrão repete o comportamento anterior (NaoConformidadeConfig::padrao).
    - IA_ANALISE_CAUSA e IA_EFICACIA: ligam e desligam os botões de IA.
    - LEMBRETES_INCLUIR_LEGADO: padrão N, só NCs do greenportal, porque o legado tem a própria política de e-mails.
    - LEMBRETES_DESDE.
  - **Lembretes:**
    - Regras em nc_lembrete_regras, criadas DESLIGADAS. São 7 situações: GESTOR_PENDENTE, EMITENTE_RECUSADA, ANALISE_PARADA, CONCLUSAO_VENCIDA, ACAO_VENCENDO, ACAO_VENCIDA e EFICACIA_PENDENTE (vw_nc_lembrete_candidatos).
    - Cada regra tem: dias, repetição (0 = uma vez), aviso ao superior depois de N lembretes (usuarios.codusuario_superior), e-mail e tipos de NC.
    - sp_nc_lembretes_processar(simular):
      - grava o alerta e o e-mail (email_fila), até 500 por regra e execução;
      - controla em nc_lembrete_envios para não repetir antes do intervalo;
      - o botão "Simular" da tela só conta, sem enviar.
    - **Serviço:** "nc" do Agendador.
      - Arquivos: scripts/nc-lembretes-worker.php (windows-service --service=nc); tarefa GreenPortal-NcLembretes a cada 60 min (scripts/criar-agendamento-nc-lembretes.ps1).
      - Aparece como NC_LEMBRETES em Administração › Serviços.
  - **Pendente:**
    - rodar a 339 e instalar a tarefa;
    - ligar as regras desejadas;
    - SMTP no .env, para os e-mails.
