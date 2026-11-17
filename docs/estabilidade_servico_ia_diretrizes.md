# Estabilidade do serviço de IA

O serviço de IA deve permanecer assíncrono e não pode criar ou alterar
registros operacionais automaticamente. Seu único efeito de negócio é gravar
o resultado da análise na fila de potenciais e emitir os alertas previstos.

## Regras

- Solicitações em `PROCESSANDO` há mais de 30 minutos são recuperadas pela
  procedure `sp_ia_potencial_recuperar_expiradas` e voltam para `PENDENTE`.
- Falhas temporárias de rede, HTTP 408, 409, 425, 429 e HTTP 5xx admitem até
  três tentativas com espera progressiva.
- O log `runtime/inteligencia_artificial_service.log` registra início,
  processamento, conclusão, recuperação e erro, sem registrar a chave da IA,
  o prompt completo ou dados sensíveis.
- A resposta deve conter um objeto JSON com `potenciais`. Itens sem título ou
  com tipo fora do catálogo são descartados antes da procedure de conclusão.
- A confiança é limitada ao intervalo de 0 a 100 e o idioma do conteúdo é
  normalizado para o idioma da solicitação.
- A procedure `sp_ia_prompt_atualizar` exige os três idiomas e a presença do
  contrato de potenciais; cada alteração gera histórico.
- Prompt inválido ou resposta incompatível encerra a solicitação com `ERRO`;
  não há conversão automática para Controles, Treinamentos, Licenças ou
  Infraestrutura.
