<?php
declare(strict_types=1);

/** Extracao local de PDF, imagem e Word para documentos de licenca. */
final class LicenseDocumentExtractionService
{
    public function __construct(private readonly string $root) {}

    public function extract(string $file): array
    {
        if (!is_file($file)) throw new RuntimeException('Arquivo da licenca nao localizado.');
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($extension) {
            'pdf' => $this->pdf($file),
            'jpg','jpeg','png','tif','tiff','bmp' => $this->image($file),
            'docx' => $this->docx($file),
            'doc' => $this->office($file),
            default => throw new RuntimeException('Formato de documento nao suportado.'),
        };
    }

    private function pdf(string $file): array
    {
        $pdfToText = $this->binary('GREEN_PDFTOTEXT', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pdftotext.exe');
        if ($pdfToText !== null) {
            $output = $this->temporary('.txt'); $this->run([$pdfToText, '-enc', 'UTF-8', $file, $output]); $text = trim((string)@file_get_contents($output)); @unlink($output);
            if (mb_strlen($text, 'UTF-8') >= 40) return ['texto'=>$text,'confianca'=>95.0,'fonte'=>'PDF_TEXTO'];
        }
        $ppm = $this->binary('GREEN_PDFTOPPM', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pdftoppm.exe');
        if ($ppm === null) throw new RuntimeException('Engine PDF nao localizada.');
        $dir = $this->temporaryDirectory(); $prefix = $dir . DIRECTORY_SEPARATOR . 'pagina'; $this->run([$ppm, '-png', '-r', '180', $file, $prefix]); $text = ''; $pages = glob($prefix . '-*.png') ?: [];
        foreach ($pages as $page) { $result = $this->image($page); $text .= trim((string)$result['texto']) . "\n"; @unlink($page); }
        @rmdir($dir); $text = trim($text); if ($text === '') throw new RuntimeException('OCR nao encontrou texto no PDF.');
        return ['texto'=>$text,'confianca'=>min(85.0, max(55.0, 55.0 + mb_strlen($text,'UTF-8') / 1000.0)),'fonte'=>'OCR_PDF'];
    }

    private function image(string $file): array
    {
        $tesseract = $this->binary('GREEN_TESSERACT', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'OCR' . DIRECTORY_SEPARATOR . 'tesseract.exe');
        if ($tesseract === null) throw new RuntimeException('Engine OCR nao localizada.');
        $output = $this->temporary(''); $this->run([$tesseract, $file, $output, '-l', 'por+eng']); $textFile = $output . '.txt'; $text = trim((string)@file_get_contents($textFile)); @unlink($textFile); @unlink($output);
        if ($text === '') throw new RuntimeException('OCR nao encontrou texto na imagem.');
        return ['texto'=>$text,'confianca'=>min(85.0, max(50.0, 50.0 + mb_strlen($text,'UTF-8') / 800.0)),'fonte'=>'OCR_IMAGEM'];
    }

    private function docx(string $file): array
    {
        if (class_exists('ZipArchive')) { $zip=new ZipArchive(); if($zip->open($file)===true){$xml=(string)$zip->getFromName('word/document.xml');$zip->close();if($xml!==''){$text=trim(html_entity_decode(strip_tags(str_replace(['</w:p>','</w:tab>'],["\n","\t"],$xml)),ENT_QUOTES|ENT_XML1,'UTF-8'));if($text!=='')return ['texto'=>$text,'confianca'=>94.0,'fonte'=>'DOCX_TEXTO'];}} }
        return $this->office($file);
    }

    private function office(string $file): array
    {
        $office=$this->binary('GREEN_LIBREOFFICE','C:\\Program Files\\LibreOffice\\program\\soffice.exe');if($office===null)throw new RuntimeException('LibreOffice nao localizado para converter o documento Word.');$dir=$this->temporaryDirectory();$this->run([$office,'--headless','--convert-to','pdf','--outdir',$dir,$file]);$pdf=$dir.DIRECTORY_SEPARATOR.pathinfo($file,PATHINFO_FILENAME).'.pdf';try{return $this->pdf($pdf);}finally{@unlink($pdf);@rmdir($dir);}
    }

    private function binary(string $envKey,string $fallback): ?string { $candidate=trim((string)(getenv($envKey)?:''));if($candidate!==''&&is_file($candidate))return $candidate;return is_file($fallback)?$fallback:null; }
    private function temporary(string $extension): string { $dir=$this->temporaryDirectory();$path=tempnam($dir,'licenca_');if($path===false)throw new RuntimeException('Nao foi possivel criar arquivo temporario.');if($extension!==''){ $target=$path.$extension;@rename($path,$target);$path=$target;}return $path; }
    private function temporaryDirectory(): string { $dir=$this->root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'license-extraction';if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Diretorio temporario de extracao indisponivel.');return $dir; }
    private function run(array $command): string { $pipes=[];$process=proc_open($command,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true,'create_process_group'=>true]);if(!is_resource($process))throw new RuntimeException('Nao foi possivel iniciar a engine de extracao.');fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0&&trim((string)$stdout)==='')throw new RuntimeException('Engine de extracao retornou erro: '.trim((string)$stderr));return (string)$stdout; }
}
