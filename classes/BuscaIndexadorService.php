<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/GedStorageService.php';

/*
 * Indexação da Pesquisa de documentos (migração 264), chamada pelo serviço scripts/busca-worker.php a cada 10 minutos.
 *  1. Cadastros: CALL sp_busca_indexar_registros() — grava só o que mudou, remove o que sumiu.
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
        $registros = $this->chamar('CALL sp_busca_indexar_registros()')[0] ?? [];
        $arquivos = [];
        $alterados = (int)($registros['incluidos_ou_alterados'] ?? 0) + (int)($registros['removidos'] ?? 0);
        foreach (self::PASTAS as $tipo => $pasta) {
            $lista = $this->listarArquivos($pasta);
            $r = $this->chamar('CALL sp_busca_sincronizar_arquivos(?, ?)', [$tipo, json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)])[0] ?? [];
            $arquivos[$tipo] = ['nas_pastas' => count($lista)] + $r;
            $alterados += (int)($r['incluidos_ou_alterados'] ?? 0) + (int)($r['removidos'] ?? 0);
        }
        return ['registros' => $registros, 'arquivos' => $arquivos, 'total' => $alterados];
    }

    /** Arquivos de todas as pastas numéricas do tipo, nas duas raízes do GED: [{p, a, t, m}] */
    public function listarArquivos(string $pasta): array
    {
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
                foreach (new DirectoryIterator($sub->getPathname()) as $f) {
                    if ($f->isDot() || !$f->isFile()) continue;
                    $nome = $f->getFilename();
                    $min = mb_strtolower($nome);
                    if (in_array($min, self::IGNORAR, true) || str_starts_with($nome, '~$')) continue;
                    $k = $id . '|' . $min;
                    if (isset($vistos[$k])) continue;   // mesmo nome nas duas raízes: vale a primeira (como na janela de arquivos)
                    $vistos[$k] = true;
                    $lista[] = ['p' => $id, 'a' => $nome, 't' => $f->getSize(), 'm' => date('Y-m-d H:i:s', $f->getMTime())];
                }
            }
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
