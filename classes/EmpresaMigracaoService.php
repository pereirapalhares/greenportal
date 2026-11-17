<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

final class EmpresaMigracaoService
{
    public function __construct(private readonly array $env)
    {
    }

    public function central(): PDO
    {
        return GreenPortalDatabase::connect($this->env, (string)($this->env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    }

    public function legado(): PDO
    {
        return GreenPortalDatabase::connect($this->env, 'green_seguranca', 'LEGADO_SEGURANCA');
    }

    public function garantirEstrutura(PDO $central): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__) . '/database/266_gestao_migracao_empresas.sql');
        $sql = preg_replace('/^USE `guniversal_dbp`;\s*/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^SET NAMES[^;]+;\s*/mi', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [])) as $statement) {
            if ($statement !== '') $central->exec($statement);
        }
    }

    public function sincronizar(): array
    {
        $central = $this->central();
        $legado = $this->legado();
        $this->garantirEstrutura($central);
        $normalizar = static function (?string $valor, string $fallback): string {
            $valor = trim((string)$valor) ?: $fallback;
            $valor = function_exists('iconv') ? (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor) : $valor;
            return trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper($valor)), '_') ?: 'EMPRESA';
        };
        $novo = static function (string $db): string {
            $base = preg_replace('/^green_/i', '', trim($db)) ?? trim($db);
            return 'g' . strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $base)) . '_dbp';
        };
        // A seguranca do tenant faz parte do mesmo banco operacional. O campo
        // separado permanece apenas para registrar a origem legada, nao para
        // provisionar ou consultar um banco novo adicional.
        $novoSeg = static fn(string $db): string => $db;
        $rows = $legado->query('SELECT * FROM `empresas` ORDER BY `CODEMPRESA`')->fetchAll(PDO::FETCH_ASSOC);
        $central->beginTransaction();
        try {
            $find = $central->prepare('SELECT * FROM `empresa` WHERE `codigo_legado`=:legado OR `banco_dados` IN (:legado_banco,:novo_banco) LIMIT 1');
            $insert = $central->prepare('INSERT INTO `empresa` (`codempresa`,`codigo_legado`,`codigo`,`nome`,`banco_dados`,`status`,`endereco`,`bairro`,`cidade`,`cep`,`email`,`cnpj`,`contatos`,`telefones`,`observacoes`,`ged`,`ips`,`multempresa`,`logotipo`,`bloqueada`) VALUES (:id,:legado,:codigo,:nome,:banco,:status,:endereco,:bairro,:cidade,:cep,:email,:cnpj,:contatos,:telefones,:observacoes,:ged,:ips,:multempresa,:logotipo,:bloqueada)');
            $update = $central->prepare('UPDATE `empresa` SET `codigo_legado`=:legado,`codigo`=:codigo,`nome`=:nome,`banco_dados`=:banco,`status`=:status,`endereco`=:endereco,`bairro`=:bairro,`cidade`=:cidade,`cep`=:cep,`email`=:email,`cnpj`=:cnpj,`contatos`=:contatos,`telefones`=:telefones,`observacoes`=:observacoes,`ged`=:ged,`ips`=:ips,`multempresa`=:multempresa,`logotipo`=:logotipo,`bloqueada`=:bloqueada WHERE `codempresa`=:id');
            $config = $central->prepare('INSERT INTO `empresa_migracao_config` (`codempresa`,`codigo_legado`,`banco_legado`,`banco_legado_seguranca`,`banco_novo`,`banco_novo_seguranca`,`status_migracao`,`sincronismo_ativo`) VALUES (:empresa,:legado,:banco_legado,:banco_legado_seguranca,:banco_novo,:banco_novo_seguranca,:status,0) ON DUPLICATE KEY UPDATE `codigo_legado`=VALUES(`codigo_legado`),`banco_legado`=VALUES(`banco_legado`),`banco_legado_seguranca`=VALUES(`banco_legado_seguranca`),`atualizado_em`=CURRENT_TIMESTAMP');
            $created = 0; $updated = 0;
            foreach ($rows as $row) {
                $legacyDb = trim((string)($row['BANCO_DE_DADOS'] ?? ''));
                if ($legacyDb === '') continue;
                $newDb = $novo($legacyDb);
                $params = [
                    'id'=>(int)$row['CODEMPRESA'],'legado'=>(int)$row['CODEMPRESA'],'codigo'=>$normalizar($row['multempresa']??null,$row['DESCRICAO']??'EMPRESA'),'nome'=>(string)($row['DESCRICAO']??''),'banco'=>$newDb,
                    'status'=>strtoupper((string)($row['bloqueada']??'N'))==='S'?'INATIVA':'ATIVA','endereco'=>$row['ENDERECO']??null,'bairro'=>$row['BAIRRO']??null,'cidade'=>$row['CIDADE']??null,'cep'=>$row['CEP']??null,'email'=>$row['EMAIL']??null,'cnpj'=>$row['CNPJ']??null,'contatos'=>$row['CONTATOS']??null,'telefones'=>$row['TELEFONES']??null,'observacoes'=>$row['OBSERVACOES']??null,'ged'=>$row['GED']??null,'ips'=>$row['ips']??null,'multempresa'=>$row['multempresa']??null,'logotipo'=>$row['logotipo']??null,'bloqueada'=>$row['bloqueada']??null,
                ];
                $find->execute(['legado'=>$params['legado'],'legado_banco'=>$legacyDb,'novo_banco'=>$newDb]); $existing=$find->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $params['id']=(int)$existing['codempresa'];
                    $update->execute(array_intersect_key($params, array_flip(['legado','codigo','nome','banco','status','endereco','bairro','cidade','cep','email','cnpj','contatos','telefones','observacoes','ged','ips','multempresa','logotipo','bloqueada','id'])));
                    $updated++;
                } else { $insert->execute($params); $created++; }
                $config->execute(['empresa'=>$params['id'],'legado'=>$params['legado'],'banco_legado'=>$legacyDb,'banco_legado_seguranca'=>$legacyDb.'_seguranca','banco_novo'=>$newDb,'banco_novo_seguranca'=>$novoSeg($newDb),'status'=>'PENDENTE_VALIDACAO']);
            }
            $central->commit();
            $result = ['registros'=>count($rows),'criados'=>$created,'atualizados'=>$updated];
            $result['auditoria'] = $this->registrarAuditoria($result);
            return $result;
        } catch (Throwable $e) { if ($central->inTransaction()) $central->rollBack(); throw $e; }
    }

    /**
     * Administração › Migração (migração 305): grupo "Migração" com Migração geral (ADMINISTRACAO_EMPRESAS_MIGRACAO),
     * Sincronismo (ADMINISTRACAO_SINCRONISMO) e Menus de migração (ADMINISTRACAO_MIGRACAO_MENUS).
     * Antes "Empresas e migração" e "Menus de migração" ficavam soltos em Administração; a migração 266 usa este método
     * e não pode desfazer o grupo.
     */
    public function garantirMenu(PDO $central): void
    {
        $um = static function (string $sql, array $p = []) use ($central): ?array { $s = $central->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor(); return $r; };
        $raiz = $um("SELECT codmenu, nivel FROM menus WHERE codigo='ADMINISTRACAO' LIMIT 1");
        if (!$raiz) throw new RuntimeException('Menu ADMINISTRACAO nao encontrado.');
        $cols = array_flip(array_map('strtolower', array_column($central->query("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='menus'")->fetchAll(PDO::FETCH_ASSOC), 'c')));
        $nivel = (int)$raiz['nivel'] + 1;
        $proximo = static fn(): int => (int)$central->query('SELECT COALESCE(MAX(codmenu),0)+1 FROM menus')->fetchColumn();
        $gravar = static function (string $codigo, array $dados) use ($central, $um, $cols, $proximo): int {
            $dados = array_intersect_key($dados, $cols);
            $atual = $um('SELECT codmenu FROM menus WHERE codigo=? LIMIT 1', [$codigo]);
            if ($atual) {
                $central->prepare('UPDATE menus SET ' . implode(',', array_map(static fn($c) => "`$c`=?", array_keys($dados))) . ' WHERE codmenu=?')->execute([...array_values($dados), (int)$atual['codmenu']]);
                return (int)$atual['codmenu'];
            }
            $d = ['codmenu' => $proximo(), 'codigo' => $codigo] + $dados;
            $central->prepare('INSERT INTO menus (`' . implode('`,`', array_keys($d)) . '`) VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            return (int)$d['codmenu'];
        };
        $grupoAtual = $um("SELECT ordem FROM menus WHERE codigo='ADMINISTRACAO_MIGRACAO' LIMIT 1") ?? $um("SELECT ordem FROM menus WHERE codigo='ADMINISTRACAO_EMPRESAS_MIGRACAO' LIMIT 1");
        $ordem = $grupoAtual ? (int)$grupoAtual['ordem'] : (int)$central->query('SELECT COALESCE(MAX(ordem),0)+1 FROM menus WHERE codmenu_pai=' . (int)$raiz['codmenu'])->fetchColumn();
        $grupo = $gravar('ADMINISTRACAO_MIGRACAO', ['codmenu_pai' => (int)$raiz['codmenu'], 'modulo' => 'ADMINISTRACAO', 'label' => 'Migração', 'label_pt' => 'Migração', 'label_en' => 'Migration', 'label_es' => 'Migración',
            'url' => null, 'nivel' => $nivel, 'ordem' => $ordem, 'icone' => 'bi-database-gear', 'ativo' => 1]);
        $itens = [
            'ADMINISTRACAO_EMPRESAS_MIGRACAO' => [10, 'Migração geral', 'General migration', 'Migración general', '/principal/administracao_migracao_empresas.php', 'bi-database-add'],
            'ADMINISTRACAO_SINCRONISMO' => [20, 'Sincronismo', 'Synchronization', 'Sincronización', '/principal/administracao_sincronismo.php', 'bi-arrow-repeat'],
            'ADMINISTRACAO_MIGRACAO_MENUS' => [30, 'Menus de migração', 'Migration menus', 'Menús de migración', '/principal/administracao_migracao_menus.php', 'bi-diagram-3'],
        ];
        foreach ($itens as $codigo => [$o, $pt, $en, $es, $url, $icone])
            $gravar($codigo, ['codmenu_pai' => $grupo, 'modulo' => 'ADMINISTRACAO', 'label' => $pt, 'label_pt' => $pt, 'label_en' => $en, 'label_es' => $es, 'url' => $url, 'nivel' => $nivel + 1, 'ordem' => $o, 'icone' => $icone, 'ativo' => 1]);
    }

    private function registrarAuditoria(array $result): string
    {
        try {
            $pdo = GreenPortalDatabase::connect($this->env, 'gmodelo_dbp', 'GPT');
            $stmt = $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES ('GREEN_EMPRESAS_CENTRAL_266','green_seguranca.empresas','guniversal_dbp.empresa;empresa_migracao_config','DADOS','1.0.0','CONCLUIDA',:quantidade,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:detalhes) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
            $stmt->execute(['quantidade'=>(int)$result['registros'],'detalhes'=>json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            return 'CONCLUIDA';
        } catch (Throwable) {
            return 'NAO_REGISTRADA';
        }
    }
}
