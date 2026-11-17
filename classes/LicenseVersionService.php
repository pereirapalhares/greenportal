<?php
declare(strict_types=1);

require_once __DIR__ . '/GedStorageService.php';

/** Versionamento de licencas no proprio li_licencas, sem apagar o historico. */
final class LicenseVersionService
{
    public function __construct(private readonly PDO $pdo, private readonly array $env, private readonly array $session) {}

    public function createFromUpload(int $parentId, array $upload, int $user): array
    {
        $parent = $this->find($parentId);
        if (!$parent) throw new RuntimeException('Licenca nao localizada.');
        $base = max(1, (int)($parent['CODLICENCA_BASE'] ?? 0) ?: $parentId);
        $version = $this->nextVersion($base);
        $data = [];
        $excluded = ['CODLICENCA','CODLICENCA_BASE','VERSAO','STATUS_VERSAO','CODLICENCA_ANTERIOR','NOME_ARQUIVO','CAMINHO_ARQUIVO','MIME_ARQUIVO','HASH_ARQUIVO','DATA_UPLOAD','CODUSUARIO_UPLOAD','STATUS_EXTRACAO','FONTE_EXTRACAO','CONFIANCA_EXTRACAO','DATA_EXTRACAO','DADOS_EXTRACAO_JSON','DADOS_DOCUMENTO_IA','data_de_alteracao'];
        foreach ($parent as $field => $value) if (!in_array($field, $excluded, true) && preg_match('/^[A-Za-z0-9_]+$/', (string)$field)) $data[$field] = $value;
        $data['CODLICENCA_BASE'] = $base;
        $data['VERSAO'] = $version;
        $data['STATUS_VERSAO'] = 'PROCESSANDO';
        $data['CODLICENCA_ANTERIOR'] = (int)$parentId;
        $data['DATA_UPLOAD'] = date('Y-m-d H:i:s');
        $data['CODUSUARIO_UPLOAD'] = $user > 0 ? $user : null;
        $data['STATUS_EXTRACAO'] = 'PENDENTE';
        $data['FONTE_EXTRACAO'] = 'PENDENTE';
        $data['CONFIANCA_EXTRACAO'] = null;
        $data['DATA_EXTRACAO'] = null;
        $data['DADOS_EXTRACAO_JSON'] = null;
        $this->pdo->beginTransaction(); $stored = null; $newId = 0;
        try {
            $newId = (int)($this->call('CALL sp_crud_insert(?, ?)', ['li_licencas', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])[0]['chave_gerada'] ?? 0);
            if ($newId < 1) throw new RuntimeException('Nao foi possivel criar a nova versao da licenca.');
            $storage = new GedStorageService($this->pdo, $this->env, $this->session);
            $stored = $storage->store('licencas', $base, $upload, 'LICENCAS', $user);
            $this->call('CALL sp_crud_update(?, ?, ?)', ['li_licencas', json_encode(['CODLICENCA'=>$newId]), json_encode(['NOME_ARQUIVO'=>$stored['name'],'CAMINHO_ARQUIVO'=>$stored['relative_path'],'MIME_ARQUIVO'=>$stored['mime'],'HASH_ARQUIVO'=>$stored['hash'],'STATUS_EXTRACAO'=>'PENDENTE','FONTE_EXTRACAO'=>'UPLOAD'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $this->audit('NOVA_VERSAO', $newId, $user, ['codlicenca_base'=>$base,'versao'=>$version,'arquivo'=>$stored['name'],'caminho'=>$stored['relative_path']]);
            $this->pdo->commit();
            return ['codlicenca'=>$newId,'codlicenca_base'=>$base,'versao'=>$version,'arquivo'=>$stored['name'],'caminho'=>$stored['relative_path'],'status'=>'PROCESSANDO'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (is_array($stored) && !empty($stored['path']) && is_file($stored['path'])) @unlink((string)$stored['path']);
            throw $e;
        }
    }

    public function confirmExtraction(int $versionId, array $suggestion, int $user, string $fileName): void
    {
        $version = $this->find($versionId); if (!$version) throw new RuntimeException('Versao da licenca nao localizada.');
        $base = max(1, (int)($version['CODLICENCA_BASE'] ?? 0) ?: $versionId);
        $date = trim((string)($suggestion['data_validade'] ?? '')); $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        $confidence = self::percentual($suggestion['confianca'] ?? 0);
        $suggestion['confianca'] = $confidence;
        $raw = ['arquivo'=>$fileName,'analisado_em'=>date('c'),'codusuario'=>$user,'sugestao'=>$suggestion];
        $this->pdo->beginTransaction();
        try {
            // Ativa a versão e transfere vínculos das versões anteriores (procedure, dentro desta transação)
            $p=['descricao'=>trim((string)($suggestion['descricao'] ?? $version['DSCLICENCA'] ?? '')),'validade'=>$date,'atividade'=>trim((string)($suggestion['atividade'] ?? $version['atividade'] ?? '')) ?: null,'orgao'=>trim((string)($suggestion['orgao_emissor'] ?? '')) ?: null,'numero'=>trim((string)($suggestion['numero_licenca'] ?? '')) ?: null,'observacao'=>trim((string)($suggestion['observacao'] ?? '')) ?: null,'ia'=>json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'extracao'=>json_encode($suggestion,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'confianca'=>$confidence,'id'=>$versionId];
            $r = $this->call('CALL sp_licenca_ativar_versao(:id,:base,:descricao,:validade,:atividade,:orgao,:numero,:observacao,:ia,:extracao,:confianca)', ['id'=>$versionId,'base'=>$base] + $p);
            $previous = array_values(array_filter(array_map('intval', explode(',', (string)($r[0]['substituidas'] ?? '')))));
            $this->audit('ATIVAR_VERSAO', $versionId, $user, ['codlicenca_base'=>$base,'versao'=>$version['VERSAO']??null,'substituidas'=>$previous,'confianca'=>$confidence]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function call(string $sql, array $params): array { $q=$this->pdo->prepare($sql); $q->execute($params); $r=$q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor(); return $r; }
    public function find(int $id): ?array { $q=$this->pdo->prepare('SELECT * FROM vw_li_licencas_completo WHERE CODLICENCA=:id LIMIT 1');$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null; }
    private static function percentual(mixed $value): float { $confidence=(float)$value; if($confidence>0 && $confidence<=1)$confidence*=100; return round(max(0,min(100,$confidence)),2); }
    private function nextVersion(int $base): string { $q=$this->pdo->prepare('SELECT VERSAO FROM vw_li_licencas_completo WHERE CODLICENCA_BASE=:base ORDER BY CODLICENCA');$q->execute(['base'=>$base]);$major=1;$minor=-1;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $value){if(preg_match('/^(\d+)\.(\d+)$/',(string)$value,$m)){if((int)$m[1]>$major){$major=(int)$m[1];$minor=(int)$m[2];}elseif((int)$m[1]===$major)$minor=max($minor,(int)$m[2]);}}return $major.'.'.($minor+1); }
    private function audit(string $action,int $id,int $user,array $details): void { try{$q=$this->pdo->prepare('CALL sp_auditoria_registrar(:usuario,\'manutencaolegalrequisitoslegais_licencas\',:acao,\'li_licencas\',:registro,\'SUCESSO\',:detalhes)');$q->execute(['usuario'=>(int)$user,'acao'=>$action,'registro'=>(string)$id,'detalhes'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$q->closeCursor();}catch(Throwable){} }
}
