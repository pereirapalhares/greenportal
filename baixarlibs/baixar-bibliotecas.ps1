
  $base = "E:\wamp\apps\greenportal\assets\vendor"

  $arquivos = @{
      "jquery/jquery-3.7.1.min.js" = "https://code.jquery.com/jquery-3.7.1.min.js"

      "bootstrap/css/bootstrap.min.css" = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      "bootstrap/js/bootstrap.bundle.min.js" =
      "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"

      "bootstrap-icons/font/bootstrap-icons.min.css" =
      "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
      "bootstrap-icons/font/fonts/bootstrap-icons.woff2" =
      "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"

      "pdfjs/pdf.min.js" = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"
      "pdfjs/pdf.worker.min.js" = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js"
  }

  [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

  foreach ($item in $arquivos.GetEnumerator()) {
      $destino = Join-Path $base $item.Key
      $pasta = Split-Path $destino -Parent

      New-Item -ItemType Directory -Path $pasta -Force | Out-Null

      Write-Host "Baixando: $($item.Value)"
      Invoke-WebRequest `
          -Uri $item.Value `
          -OutFile $destino `
          -UseBasicParsing

      if (-not (Test-Path $destino)) {
          throw "Falha ao salvar: $destino"
      }

      Write-Host "Salvo em: $destino" -ForegroundColor Green
  }

  Write-Host ""
  Write-Host "Bibliotecas baixadas com sucesso." -ForegroundColor Green


