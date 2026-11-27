<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/GedStorageService.php';

/*
 * Indexação da Pesquisa de documentos (migração 264), chamada pelo serviço scripts/busca-worker.php a cada 10 minutos.
 *  1. Cadastros: CALL sp_busca_indexar_registros() — grava só o que mudou, remove o que sumiu.
 *     Migração 422: só quando as tabelas de origem mudaram (ou a cada BUSCA_INDEXAR_FORCAR_HORAS); estado em
 *     storage/runtime/busca-estado-<banco>.json (apagar o arquivo força a rodada completa).
 *  2. Arquivos: lê as pastas do GED de cada tipo (as duas raízes: GGED_STORAGE_LOCAL_ROOT e GGED_LEGACY_ROOT,
 *     <raiz>\green_<empresa>\<pasta>\<código>) e envia a lista para CALL sp_busca_sincronizar_arquivos(tipo, json).
 *     Só nome, tamanho e data do arquivo (Fase 1: o conteúdo não é lido).
 * Banco: só procedures.
 */
final class BuscaIndexadorService
{
    /** tipo => pasta lógica do GED */
    public const PASTAS = [
        'licencas' => 'licencas',
        'controles' => 'documentos',
        'treinamentos' => 'treinamentos',
        'infraestrutura' => 'infraestrutura',
        'fornecedorcriterios' => 'fo_fornecedorxcriterios',
        'funcionariocriterios' => 'fo_funcionarioxtiposxcriterios',
        'funcionariotreinamentos' => 'fo_funcionarioxtiposxtreinamentos',
    ];
    private const IGNORAR = ['thumbs.db', 'desktop.ini', '.ds_store'];

    public function __construct(private readonly PDO $pdo, private readonly array $env, private readonly string $banco) {}

    /** @return array{registros: array, arquivos: array, total: int} */
    public function executar(): array
    {
        // Migração 422 (performance): a procedure de cadastros relê a view inteira de todas as fontes (10–20 s por
        // empresa). Só roda quando alguma tabela por trás da view mudou, ou quando passou o prazo de rodada completa
        // (.env BUSCA_INDEXAR_FORCAR_HORAS, padrão 6; 0 = sempre roda, como antes). Os arquivos: só envia a lista
        // à procedure quando ela mudou desde a última vez (mesmo prazo).
        $t0 = microtime(true); $tempos = [];
        $estado = $this->lerEstado();
        $horas = (float)($this->env['BUSCA_INDEXAR_FORCAR_HORAS'] ?? 6);
        $forcar = $horas <= 0 || (time() - (int)($estado['completa'] ?? 0)) >= $horas * 3600;
        $assinatura = $this->assinaturaFontes();
        $tempos['verificar_fontes'] = round(microtime(true) - $t0, 2); $t = microtime(true);
        if ($forcar || $assinatura === null || $assinatura !== ($estado['fontes'] ?? '')) {
            $registros = $this->chamar('CALL sp_busca_indexar_registros()')[0] ?? [];
            $estado['fontes'] = $assinatura ?? '';
        } else {
            $registros = ['pulado' => 'sem alteração nas fontes'];
        }
        $tempos['cadastros'] = round(microtime(true) - $t, 2);
        $arquivos = [];
        $alterados = (int)($registros['incluidos_ou_alterados'] ?? 0) + (int)($registros['removidos'] ?? 0);
        foreach (self::PASTAS as $tipo => $pasta) {
            $t = microtime(true);
            $lista = $this->listarArquivos($pasta, $forcar ? null : $tipo);
            $tempos['listar_' . $tipo] = round(microtime(true) - $t, 2);
            $json = json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $hash = sha1((string)$json);
            if (!$forcar && ($estado['arquivos'][$tipo] ?? '') === $hash) {
                $arquivos[$tipo] = ['nas_pastas' => count($lista), 'pulado' => 'lista igual'];
                continue;
            }
            $r = $this->chamar('CALL sp_busca_sincronizar_arquivos(?, ?)', [$tipo, $json])[0] ?? [];
            $estado['arquivos'][$tipo] = $hash;
            $arquivos[$tipo] = ['nas_pastas' => count($lista)] + $r;
            $alterados += (int)($r['incluidos_ou_alterados'] ?? 0) + (int)($r['removidos'] ?? 0);
        }
        if ($forcar) $estado['completa'] = time();
        $this->gravarEstado($estado);
        $tempos['total'] = round(microtime(true) - $t0, 2);
        return ['registros' => $registros, 'arquivos' => $arquivos, 'total' => $alterados, 'rodada_completa' => $forcar, 'tempos_seg' => $tempos];
    }

    /**
     * Assinatura das tabelas por trás de vw_busca_fonte_registros (views aninhadas resolvidas): nome, linhas estimadas,
     * data da última gravação e tamanho. null quando não dá para saber (aí a procedure roda).
     */
    private function assinaturaFontes(): ?string
    {
        try {
            $this->pdo->exec('SET SESSION information_schema_stats_expiry = 0');
            $uso = $this->pdo->query("SELECT LOWER(VIEW_NAME) v, LOWER(TABLE_NAME) t FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA = DATABASE() AND TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            $filhos = [];
            foreach ($uso as $u) $filhos[$u['v']][] = $u['t'];
            if (empty($filhos['vw_busca_fonte_registros'])) return null;
            $tabelas = []; $pilha = ['vw_busca_fonte_registros']; $visto = [];
            while ($pilha) {
                $x = array_pop($pilha);
                if (isset($visto[$x])) continue;
                $visto[$x] = true;
                if (isset($filhos[$x])) { foreach ($filhos[$x] as $f) $pilha[] = $f; } else { $tabelas[$x] = true; }
            }
            // só as tabelas de origem: com stats_expiry = 0 o MySQL recalcula as estatísticas de cada tabela consultada
            $nomes = array_keys($tabelas);
            $s = $this->pdo->prepare("SELECT LOWER(TABLE_NAME) n, TABLE_ROWS r, UPDATE_TIME u, DATA_LENGTH d FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME IN (" . implode(',', array_fill(0, count($nomes), '?')) . ") ORDER BY 1");
            $s->execute($nomes);
            $partes = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) $partes[] = implode('|', [$t['n'], $t['r'], $t['u'], $t['d']]);
            $this->pdo->exec('SET SESSION information_schema_stats_expiry = DEFAULT');
            return $partes ? sha1(implode("\n", $partes)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function arquivoEstado(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'busca-estado-' . preg_replace('/[^a-z0-9_]/i', '', $this->banco) . '.json';
    }

    private function lerEstado(): array
    {
        $a = $this->arquivoEstado();
        $d = is_file($a) ? json_decode((string)@file_get_contents($a), true) : null;
        return is_array($d) ? $d : [];
    }

    private function gravarEstado(array $estado): void
    {
        $a = $this->arquivoEstado();
        if (!is_dir(dirname($a))) @mkdir(dirname($a), 0775, true);
        @file_put_contents($a, json_encode($estado, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** Arquivos de todas as pastas numéricas do tipo, nas duas raízes do GED: [{p, a, t, m}] */
    public function listarArquivos(string $pasta, ?string $cacheTipo = null): array
    {
        // Migração 422: com $cacheTipo, a subpasta (código do registro) cuja data de modificação não mudou desde a
        // última leitura reaproveita a lista guardada (incluir, apagar ou renomear arquivo muda a data da pasta).
        // Arquivo regravado com o mesmo nome só é visto na rodada completa (BUSCA_INDEXAR_FORCAR_HORAS).
        $arqCache = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'busca-pastas-' . preg_replace('/[^a-z0-9_]/i', '', $this->banco) . '-' . preg_replace('/[^a-z0-9_]/i', '', $pasta) . '.json';
        $cache = [];
        if ($cacheTipo !== null && is_file($arqCache)) { $c = json_decode((string)@file_get_contents($arqCache), true); if (is_array($c)) $cache = $c; }
        $novoCache = [];
        $storage = new GedStorageService($this->pdo, $this->env, ['tenant' => ['banco_dados' => $this->banco]]);
        $raizes = array_unique([$storage->root(), $storage->legacyRoot()]);
        $vistos = [];
        $lista = [];
        foreach ($raizes as $raiz) {
            $dir = $raiz . DIRECTORY_SEPARATOR . $storage->legacyDatabase() . DIRECTORY_SEPARATOR . $pasta;
            if (!is_dir($dir)) continue;
            foreach (new DirectoryIterator($dir) as $sub) {
                if ($sub->isDot() || !$sub->isDir() || !ctype_digit($sub->getFilename())) continue;
                $id = (int)$sub->getFilename();
                $chave = $raiz . '|' . $id;
                $mt = $sub->getMTime();
                $doDir = [];
                if (isset($cache[$chave]) && (int)$cache[$chave]['m'] === $mt) {
                    $doDir = $cache[$chave]['f'];
                } else {
                    foreach (new DirectoryIterator($sub->getPathname()) as $f) {
                        if ($f->isDot() || !$f->isFile()) continue;
                        $doDir[] = [$f->getFilename(), $f->getSize(), date('Y-m-d H:i:s', $f->getMTime())];
                    }
                }
                $novoCache[$chave] = ['m' => $mt, 'f' => $doDir];
                foreach ($doDir as [$nome, $tam, $data]) {
                    $min = mb_strtolower($nome);
                    if (in_array($min, self::IGNORAR, true) || str_starts_with($nome, '~$')) continue;
                    $k = $id . '|' . $min;
                    if (isset($vistos[$k])) continue;   // mesmo nome nas duas raízes: vale a primeira (como na janela de arquivos)
                    $vistos[$k] = true;
                    $lista[] = ['p' => $id, 'a' => $nome, 't' => $tam, 'm' => $data];
                }
            }
        }
        if ($novoCache) {
            if (!is_dir(dirname($arqCache))) @mkdir(dirname($arqCache), 0775, true);
            @file_put_contents($arqCache, json_encode($novoCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        }
        return $lista;
    }

    private function chamar(string $sql, array $p = []): array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $s->closeCursor();
        while ($s->nextRowset()) { /* descarta resultados extras da procedure */ }
        return $rows;
    }
}
