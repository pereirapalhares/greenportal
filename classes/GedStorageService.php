<?php
declare(strict_types=1);
require_once __DIR__ . '/ContentService.php';

/** Armazenamento GED fora da area publica, com fallback somente leitura do legado. */
final class GedStorageService
{
    public function __construct(private readonly PDO $pdo, private readonly array $env, private readonly array $session) {}
    public function root(): string { return rtrim((string)($this->env['GGED_STORAGE_LOCAL_ROOT'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'), '\\/'); }
    public function legacyRoot(): string { return rtrim((string)($this->env['GGED_LEGACY_ROOT'] ?? ('E:' . DIRECTORY_SEPARATOR . 'projetoGreen' . DIRECTORY_SEPARATOR . 'GED')), '\\/'); }
    public function legacyDatabase(): string { $db=(string)($this->session['tenant']['banco_dados']??'');$legacy=trim((string)($this->session['tenant']['banco_legado']??$this->session['tenant']['ged']??''));if($legacy===''){$legacy=preg_replace('/_dbp$/i','',$db)?:$db;$legacy=preg_replace('/^gmodelo2?$/i','green_modelo',$legacy)?:$legacy;if(str_starts_with(strtolower($legacy),'g')&&!str_starts_with(strtolower($legacy),'green_'))$legacy='green_'.substr($legacy,1);}return $legacy; }
    /** Pastas que ficam obrigatoriamente no GED legado: E:\projetoGreen\GED\green_<empresa>\<pasta>\<codigo>. */
    private const PASTAS_GED_LEGADO = ['licencas', 'fo_funcionarios', 'fo_fornecedorxcriterios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos',
        // Migração 300: anexos das abas do fornecedor, nas mesmas pastas do legado (certificados, FDS dos químicos, equipamentos, plano de ação, evidência da auditoria)
        'fo_certificados', 'fo_produtoquimico', 'fo_equipamentos', 'fo_planodeacao', 'fo_fornecedorxauditoria',
        // Não Conformidades: anexos da ocorrência, evidências da análise e arquivos das ações do plano (mesmas pastas do legado)
        'nc_indicacao_ocorrencia', 'nc_analise_ocorrencia', 'nc_planodeacao'];
    public function usaGedLegado(string $logical): bool { return in_array(strtolower(trim($logical)), self::PASTAS_GED_LEGADO, true); }
    /** Licencas, anexos de funcionarios (fo_funcionarios) evidências dos documentos de fornecedor (fo_fornecedorxcriterios) e dos critérios e treinamentos dos funcionários (fo_funcionarioxtiposxcriterios, fo_funcionarioxtiposxtreinamentos) usam E:\projetoGreen\GED\green_<empresa>\<pasta>\<codigo>. */
    public function folder(string $logical,int $id,bool $legacy=false): string
    {
        $logical=trim($logical);
        if($this->usaGedLegado($logical))return $this->legacyRoot().DIRECTORY_SEPARATOR.$this->legacyDatabase().DIRECTORY_SEPARATOR.strtolower($logical).DIRECTORY_SEPARATOR.$id;
        return ($legacy?$this->legacyRoot():$this->root()).DIRECTORY_SEPARATOR.$this->legacyDatabase().DIRECTORY_SEPARATOR.$logical.DIRECTORY_SEPARATOR.$id;
    }
    public function folders(string $logical,int $id): array
    {
        if($this->usaGedLegado($logical)){$canonical=$this->folder($logical,$id,true);$fallback=$this->root().DIRECTORY_SEPARATOR.$this->legacyDatabase().DIRECTORY_SEPARATOR.strtolower(trim($logical)).DIRECTORY_SEPARATOR.$id;return $canonical===$fallback?[$canonical]:[$canonical,$fallback];}
        return [$this->folder($logical,$id,false),$this->folder($logical,$id,true)];
    }
    public function licenseRelativePath(int $codlicenca,string $name): string { return $this->legacyDatabase().DIRECTORY_SEPARATOR.'licencas'.DIRECTORY_SEPARATOR.$codlicenca.DIRECTORY_SEPARATOR.basename($name); }
    public function list(string $logical,int $id,string $urlBase): array { $out=[];$seen=[];foreach($this->folders($logical,$id) as $folder){if(!is_dir($folder))continue;foreach(new DirectoryIterator($folder) as $file){if($file->isDot()||!$file->isFile()||isset($seen[$file->getFilename()]))continue;$seen[$file->getFilename()]=true;$out[]=['name'=>$file->getFilename(),'size'=>$file->getSize(),'created_at'=>date('Y-m-d H:i:s',$file->getCTime()),'url'=>$urlBase.'&action=download&arquivo='.rawurlencode($file->getFilename())];}}usort($out,static fn(array $a,array $b):int=>strcmp($a['name'],$b['name']));return $out; }
    public function resolve(string $logical,int $id,string $name): ?string { $name=basename($name);foreach($this->folders($logical,$id) as $folder){$safe=realpath($folder);$file=$safe===false?false:realpath($safe.DIRECTORY_SEPARATOR.$name);if($file!==false&&is_file($file)&&strncmp(strtolower($file),strtolower($safe.DIRECTORY_SEPARATOR),strlen($safe.DIRECTORY_SEPARATOR))===0)return $file;}return null; }
    /** Grava o arquivo e o registro do envio (content_versions: nome físico e usuário = dono do arquivo, migração 330).
     *  A chave do registro inclui o nome físico: o mesmo conteúdo enviado de novo é outro arquivo, com o seu dono. */
    public function store(string $logical,int $id,array $upload,string $type,int $user): array
    {
        $isLicense=strcasecmp(trim($logical),'licencas')===0;$noLegado=$this->usaGedLegado($logical);$root=$noLegado?$this->legacyRoot():$this->root();
        if(!is_dir($root)&&!@mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Storage GED indisponivel.');
        $folder=$this->folder($logical,$id,$noLegado);if(!is_dir($folder)&&!@mkdir($folder,0770,true)&&!is_dir($folder))throw new RuntimeException('Pasta GED indisponivel.');
        $original=basename((string)$upload['name']);$safe=preg_replace('/[^A-Za-z0-9._-]+/','_',$original)?:'arquivo';$name=($isLicense?'upload_':'').date('Ymd_His').'_'.bin2hex(random_bytes(4)).'_'.$safe;$path=$folder.DIRECTORY_SEPARATOR.$name;
        if(!move_uploaded_file((string)$upload['tmp_name'],$path))throw new RuntimeException('Nao foi possivel armazenar o arquivo.');
        try{$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';$hash=hash_file('sha256',$path);$content=new ContentService($this->pdo);$doc=$content->createDocument(['chave_idempotencia'=>'GED_DOC_'.$type.'_'.$id,'codigo'=>'GED_'.$type.'_'.$id,'titulo'=>'GED '.$type.' '.$id,'tipo_documento'=>$mime,'origem_entidade'=>'GED_'.$type,'origem_id'=>(string)$id],$user);$content->createVersion($doc,['chave_idempotencia'=>'GED_VER_'.$type.'_'.$id.'_'.$hash.'_'.substr(sha1($name),0,12),'nome_arquivo'=>$name,'mime_type'=>$mime,'tamanho_bytes'=>filesize($path),'hash_sha256'=>$hash,'storage_key'=>($noLegado?'legacy/':'local/').($isLicense?$this->licenseRelativePath($id,$name):$this->legacyDatabase().'/'.$logical.'/'.$id.'/'.$name)],$user);if($isLicense){$link=$this->pdo->prepare('CALL sp_ged_licenca_vincular_documento(?,?,?)');$link->execute([$doc,$id,$user]);$link->closeCursor();}}catch(Throwable $e){@unlink($path);throw $e;}
        return ['name'=>$name,'path'=>$path,'mime'=>$mime,'hash'=>$hash,'relative_path'=>$isLicense?$this->licenseRelativePath($id,$name):($noLegado?'legacy/':'local/').$this->legacyDatabase().'/'.$logical.'/'.$id.'/'.$name];
    }
}
