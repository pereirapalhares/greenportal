# OCR de PDFs no GreenPortal

## Implementação

Foi criado o serviço REST `services/ocr_service`, separado do WAMP e limitado a
`127.0.0.1:8091`. O fluxo de análise de documentos agora é:

1. O navegador tenta extrair texto com PDF.js.
2. PDFs com texto seguem diretamente para a IA.
3. PDFs sem texto selecionável são enviados ao endpoint PHP
   `principal/api/documentos_ocr.php`.
4. O endpoint valida sessão, tenant, tipo, registro, arquivo PDF, permissão,
   CSRF e limite de tamanho antes de chamar o OCR.
5. O serviço executa PyMuPDF + Tesseract, limita tamanho e páginas, registra o
   tempo e remove o arquivo temporário.
6. O texto retornado segue para a IA e a tela informa: `Texto obtido por OCR.
   Revise a sugestão com atenção.`

O endpoint `/health` exige o token interno e confirma a disponibilidade do
Tesseract. Upload e exclusão não ficam expostos pelo serviço: o PHP envia apenas
o arquivo já autorizado pelo GreenPortal.

## Configuração pendente no ambiente

Neste ambiente, Python, Tesseract e o launcher `py` não estão disponíveis no
`PATH`; portanto o serviço ainda não foi iniciado nem submetido a teste real de
OCR. Para habilitar:

- instalar Python 3.11+;
- instalar Tesseract OCR com `por`, `eng` e `spa`;
- criar o ambiente virtual e instalar `services/ocr_service/requirements.txt`;
- definir `GREENPORTAL_OCR_TOKEN` no serviço;
- definir no `.env` do GreenPortal o mesmo valor em `OCR_SERVICE_TOKEN` e usar
  `OCR_SERVICE_URL=http://127.0.0.1:8091`;
- iniciar `start_ocr_service.ps1` ou registrar `GreenPortalOCR` pelo script de
  instalação com NSSM, sempre mantendo o bind em localhost.

As validações PHP foram executadas com sucesso em
`principal/gestao_arquivos.php` e `principal/api/documentos_ocr.php`. A
validação funcional completa depende da instalação das dependências OCR.
