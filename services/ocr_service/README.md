# Serviço OCR do GreenPortal

Serviço REST separado do Apache/WAMP. Escuta somente em `127.0.0.1:8091`.

## Instalação

1. Instalar Python 3.11+ e Tesseract OCR com os idiomas `por`, `eng` e `spa`.
2. Criar um ambiente virtual nesta pasta.
3. Instalar `requirements.txt`.
4. Definir `GREENPORTAL_OCR_TOKEN` no ambiente do serviço e o mesmo valor em `.env` do GreenPortal como `OCR_SERVICE_TOKEN`.
5. Iniciar com `start_ocr_service.ps1`.

O serviço não deve ser publicado em `0.0.0.0`; o GreenPortal acessa apenas localhost.

## Configuração do GreenPortal

No arquivo `.env` do GreenPortal, definir o mesmo token usado pelo serviço:

```text
OCR_SERVICE_URL=http://127.0.0.1:8091
OCR_SERVICE_TOKEN=um-token-interno-forte
OCR_MAX_BYTES=20971520
```

O limite de páginas é controlado no serviço por `GREENPORTAL_OCR_MAX_PAGES` e o
limite de tamanho por `GREENPORTAL_OCR_MAX_BYTES`. O endpoint `/health` exige o
mesmo cabeçalho `X-OCR-Token` e confirma se o Tesseract está disponível.

## Serviço do Windows

Para iniciar manualmente, use `start_ocr_service.ps1`. Para instalação automática
na inicialização do Windows, instale o NSSM, deixe `nssm.exe` no `PATH`, abra o
PowerShell como Administrador e defina `GREENPORTAL_OCR_TOKEN`. Depois execute
`install_ocr_service.ps1`. O script registra o serviço `GreenPortalOCR`, mantém o
bind em `127.0.0.1` e reinicia o processo se ele terminar.

O PHP envia ao serviço apenas PDFs já autorizados pelo tenant, tipo, registro e
permissões. O serviço apaga o arquivo temporário ao final de cada processamento e
registra duração, páginas, tamanho e erro no log configurado.
