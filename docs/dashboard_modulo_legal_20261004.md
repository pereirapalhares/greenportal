# Dashboard executivo do Módulo Legal — 2026-10-04

O Dashboard recebeu uma visão executiva do Módulo Legal, com dados calculados
no banco do tenant ativo e respeitando as permissões do usuário.

## Entregas

- oito indicadores: requisitos, licenças, licenças em atenção, situações
  pendentes, controles, treinamentos, infraestrutura e IA pendente;
- bloco de atenção imediata com vencimentos e situações pendentes;
- pendências agrupadas por unidade;
- percentual de cobertura legal, considerando os relacionamentos existentes;
- bloco de análises de IA abertas pelo usuário logado, com indicação de revisão
  humana obrigatória;
- indicadores restritos aos registros em que o usuário esteja envolvido como
  responsável, executor, aprovador, proprietário ou solicitante;
- layout responsivo e abertura dos atalhos no padrão de abas do GreenPortal;
- traduções PT/EN/ES dos novos textos.

O painel não exibe informações de OCR nem uma seção de atalhos. A prioridade é
mostrar o que exige ação do usuário logado. Consultas incompatíveis com um
tenant ou sem permissão retornam estado vazio, sem interromper a abertura do
painel.
