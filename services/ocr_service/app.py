from __future__ import annotations

import os
import secrets
import tempfile
import time
from pathlib import Path

import fitz
import pytesseract
from fastapi import FastAPI, File, Header, HTTPException, UploadFile
from PIL import Image

app = FastAPI(title="GreenPortal OCR Service", version="1.0.0")
TOKEN = os.getenv("GREENPORTAL_OCR_TOKEN", "")
MAX_BYTES = int(os.getenv("GREENPORTAL_OCR_MAX_BYTES", str(20 * 1024 * 1024)))
MAX_PAGES = int(os.getenv("GREENPORTAL_OCR_MAX_PAGES", "30"))
OCR_LANGUAGES = os.getenv("GREENPORTAL_OCR_LANGUAGES", "por+eng+spa")
LOG_FILE = Path(os.getenv("GREENPORTAL_OCR_LOG", "runtime/ocr_service.log"))
if os.getenv("TESSERACT_CMD"):
    pytesseract.pytesseract.tesseract_cmd = os.environ["TESSERACT_CMD"]


def log(message: str) -> None:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOG_FILE.open("a", encoding="utf-8") as stream:
        stream.write(f"{time.strftime('%Y-%m-%d %H:%M:%S')} {message}\n")


def authorize(token: str | None) -> None:
    if not TOKEN or not token or not secrets.compare_digest(token, TOKEN):
        raise HTTPException(status_code=401, detail="OCR não autorizado.")


@app.get("/health")
def health(x_ocr_token: str | None = Header(default=None)) -> dict:
    authorize(x_ocr_token)
    try:
        version = pytesseract.get_tesseract_version()
        tesseract = str(version).splitlines()[0]
    except Exception as error:
        raise HTTPException(status_code=503, detail=f"Tesseract indisponível: {error}") from error
    return {"ok": True, "service": "ocr", "tesseract": tesseract, "languages": OCR_LANGUAGES, "max_pages": MAX_PAGES}


@app.post("/ocr")
async def ocr(file: UploadFile = File(...), x_ocr_token: str | None = Header(default=None)) -> dict:
    authorize(x_ocr_token)
    started = time.perf_counter()
    temp_path: Path | None = None
    try:
        if (file.filename or "").lower().split(".")[-1] != "pdf":
            raise HTTPException(status_code=422, detail="O OCR aceita somente PDF.")
        content = await file.read(MAX_BYTES + 1)
        if len(content) > MAX_BYTES:
            raise HTTPException(status_code=413, detail="PDF excede o limite configurado.")
        with tempfile.NamedTemporaryFile(prefix="greenportal_ocr_", suffix=".pdf", delete=False) as handle:
            handle.write(content)
            temp_path = Path(handle.name)
        document = fitz.open(temp_path)
        if document.page_count > MAX_PAGES:
            raise HTTPException(status_code=413, detail="PDF excede o limite de páginas.")
        parts: list[str] = []
        confidence: list[float] = []
        for index in range(document.page_count):
            page = document.load_page(index)
            pixmap = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
            image = Image.frombytes("RGB", [pixmap.width, pixmap.height], pixmap.samples)
            parts.append(pytesseract.image_to_string(image, lang=OCR_LANGUAGES))
            try:
                data = pytesseract.image_to_data(image, lang=OCR_LANGUAGES, output_type=pytesseract.Output.DICT)
                values = [float(value) for value in data.get("conf", []) if str(value).strip() not in {"", "-1"} and float(value) >= 0]
                if values:
                    confidence.append(sum(values) / len(values))
            except Exception:
                pass
        text = "\n".join(parts).strip()
        elapsed = round(time.perf_counter() - started, 3)
        log(f"ok arquivo={file.filename!r} paginas={document.page_count} bytes={len(content)} segundos={elapsed} confianca={round(sum(confidence)/len(confidence), 2) if confidence else 0}")
        return {"ok": True, "texto": text, "paginas": document.page_count, "confianca": round(sum(confidence) / len(confidence), 2) if confidence else 0, "processado_por": "ocr", "segundos": elapsed}
    except HTTPException:
        raise
    except Exception as error:
        log(f"erro arquivo={file.filename!r} erro={error}")
        raise HTTPException(status_code=422, detail="Não foi possível executar o OCR.") from error
    finally:
        if temp_path:
            temp_path.unlink(missing_ok=True)
