<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';

final class UsuarioContaService
{
    public function __construct(private PDO $pdo, private int $codusuario)
    {
    }

    private function call(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }

    public function usuario(): array
    {
        return $this->call('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'usuarios', 'filtros' => json_encode(['codusuario' => $this->codusuario], JSON_UNESCAPED_UNICODE)])[0] ?? [];
    }

    public function alterarSenha(string $senhaAtual, string $novaSenha): void
    {
        if (strlen($novaSenha) < 4) throw new InvalidArgumentException(IdiomaService::t('portal.conta.senha_curta', ['pt' => 'A nova senha deve possuir pelo menos 4 caracteres.', 'en' => 'The new password must have at least 4 characters.', 'es' => 'La nueva contraseña debe tener al menos 4 caracteres.']));
        $usuario = $this->usuario();
        $senhaValida = !empty($usuario['senha_usa_hash']) ? password_verify($senhaAtual, (string)($usuario['senha'] ?? '')) : hash_equals((string)($usuario['senha'] ?? ''), $senhaAtual);
        if (!$senhaValida) throw new InvalidArgumentException(IdiomaService::t('portal.conta.senha_atual_invalida', ['pt' => 'A senha atual informada não confere.', 'en' => 'The current password does not match.', 'es' => 'La contraseña actual no coincide.']));
        $politica = $this->call('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'vw_setup_empresa_grid', 'filtros' => json_encode(['chave' => 'politica_senha_usa_hash', 'idioma' => 'pt'], JSON_UNESCAPED_UNICODE)]);
        $usarHash = (int)($politica[0]['valor'] ?? ($usuario['senha_usa_hash'] ?? 0)) === 1;
        $senha = $usarHash ? password_hash($novaSenha, PASSWORD_DEFAULT) : $novaSenha;
        $this->call('CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'usuarios', 'chave' => json_encode(['codusuario' => $this->codusuario]), 'dados' => json_encode(['senha' => $senha, 'senha_usa_hash' => $usarHash, 'trocar_senha' => 0], JSON_UNESCAPED_UNICODE)]);
    }

    public function salvarTema(string $tema): string
    {
        if (!in_array($tema, ['verde', 'azul', 'cinza'], true)) $tema = 'verde';
        $current = $this->call('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'preferencias_usuario', 'filtros' => json_encode(['codusuario' => $this->codusuario, 'chave' => 'layout_tema'], JSON_UNESCAPED_UNICODE)]);
        $data = ['codusuario' => $this->codusuario, 'chave' => 'layout_tema', 'valor' => $tema];
        if ($current) $this->call('CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'preferencias_usuario', 'chave' => json_encode(['codpreferenciausuario' => (int)$current[0]['codpreferenciausuario']]), 'dados' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        else $this->call('CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'preferencias_usuario', 'dados' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return $tema;
    }

    /**
     * Profile (principal/meu_perfil.php): dados básicos que o próprio usuário pode alterar. Só entram as colunas que
     * existem na tabela usuarios da empresa (information_schema); login, perfil, grupo, superior e situação ficam com o
     * administrador (Segurança › Usuários).
     */
    public const CAMPOS_PERFIL = [
        'nome' => ['text', ['pt' => 'Nome', 'en' => 'Name', 'es' => 'Nombre'], 'name'],
        'email' => ['email', ['pt' => 'E-mail', 'en' => 'E-mail', 'es' => 'Correo electrónico'], 'email'],
        'telefone' => ['tel', ['pt' => 'Telefone', 'en' => 'Phone', 'es' => 'Teléfono'], 'tel'],
        'celular' => ['tel', ['pt' => 'Celular', 'en' => 'Mobile', 'es' => 'Celular'], 'tel'],
        'ramal' => ['text', ['pt' => 'Ramal', 'en' => 'Extension', 'es' => 'Extensión'], 'off'],
        'cargo' => ['text', ['pt' => 'Cargo', 'en' => 'Job title', 'es' => 'Cargo'], 'organization-title'],
        'funcao' => ['text', ['pt' => 'Função', 'en' => 'Role', 'es' => 'Función'], 'off'],
        'departamento' => ['text', ['pt' => 'Departamento', 'en' => 'Department', 'es' => 'Departamento'], 'off'],
        'setor' => ['text', ['pt' => 'Setor', 'en' => 'Sector', 'es' => 'Sector'], 'off'],
        'area' => ['text', ['pt' => 'Área', 'en' => 'Area', 'es' => 'Área'], 'off'],
        'matricula' => ['text', ['pt' => 'Matrícula', 'en' => 'Employee ID', 'es' => 'Matrícula'], 'off'],
        'data_nascimento' => ['date', ['pt' => 'Data de nascimento', 'en' => 'Date of birth', 'es' => 'Fecha de nacimiento'], 'bday'],
    ];

    /** @return array<string, array{coluna:string,tipo:string,rotulo:string,auto:string,max:int,valor:string}> */
    public function camposPerfil(): array
    {
        $colunas = [];
        foreach ($this->call('SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', ['t' => 'usuarios']) as $c)
            $colunas[strtolower((string)$c['COLUMN_NAME'])] = [(string)$c['COLUMN_NAME'], (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?? 0)];
        $usuario = array_change_key_case($this->usuario(), CASE_LOWER);
        $idioma = IdiomaService::idioma();
        $campos = [];
        foreach (self::CAMPOS_PERFIL as $chave => [$tipo, $rotulos, $auto]) {
            if (!isset($colunas[$chave])) continue;
            $valor = (string)($usuario[$chave] ?? '');
            if ($tipo === 'date') $valor = preg_match('/^(\d{4}-\d{2}-\d{2})/', $valor, $m) && $m[1] !== '0000-00-00' ? $m[1] : '';
            $campos[$chave] = ['coluna' => $colunas[$chave][0], 'tipo' => $tipo, 'rotulo' => $rotulos[$idioma] ?? $rotulos['pt'], 'auto' => $auto, 'max' => $colunas[$chave][1], 'valor' => $valor];
        }
        return $campos;
    }

    /** Grava o Profile; devolve os valores gravados (chave => valor). E-mail também em "emails" quando a coluna existe. */
    public function salvarPerfil(array $entrada): array
    {
        $campos = $this->camposPerfil();
        $idioma = IdiomaService::idioma();
        $t = static fn(string $pt, string $en, string $es): string => ['pt' => $pt, 'en' => $en, 'es' => $es][$idioma] ?? $pt;
        $dados = []; $gravados = [];
        foreach ($campos as $chave => $c) {
            if (!array_key_exists($chave, $entrada)) continue;
            $valor = trim((string)$entrada[$chave]);
            if ($chave === 'nome' && $valor === '') throw new InvalidArgumentException($t('Informe o seu nome.', 'Enter your name.', 'Informe su nombre.'));
            if ($chave === 'email' && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException($t('Informe um e-mail válido.', 'Enter a valid e-mail.', 'Informe un correo válido.'));
            if ($c['tipo'] === 'date' && $valor !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) throw new InvalidArgumentException($t('Data inválida.', 'Invalid date.', 'Fecha inválida.'));
            if ($c['max'] > 0 && mb_strlen($valor) > $c['max']) throw new InvalidArgumentException(sprintf($t('O campo %s aceita até %d caracteres.', 'Field %s accepts up to %d characters.', 'El campo %s acepta hasta %d caracteres.'), $c['rotulo'], $c['max']));
            $dados[$c['coluna']] = $valor === '' && $chave !== 'nome' ? null : $valor;
            $gravados[$chave] = $valor;
        }
        if (!$dados) return [];
        if (isset($gravados['email'])) {
            foreach ($this->call('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND LOWER(COLUMN_NAME) = :c', ['t' => 'usuarios', 'c' => 'emails']) as $c)
                $dados[(string)$c['COLUMN_NAME']] = $gravados['email'] === '' ? null : $gravados['email'];
        }
        $this->call('CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'usuarios', 'chave' => json_encode(['codusuario' => $this->codusuario]), 'dados' => json_encode($dados, JSON_UNESCAPED_UNICODE)]);
        return $gravados;
    }

    public function salvarTransacaoPadrao(string $transacao): string
    {
        $transacao = preg_replace('/[^a-zA-Z0-9_-]/', '', $transacao) ?: 'menu-dashboard';
        $current = $this->call('CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', ['tabela' => 'preferencias_usuario', 'filtros' => json_encode(['codusuario' => $this->codusuario, 'chave' => 'default_transacao'], JSON_UNESCAPED_UNICODE)]);
        $data = ['codusuario' => $this->codusuario, 'chave' => 'default_transacao', 'valor' => $transacao];
        if ($current) $this->call('CALL sp_crud_update(:tabela, :chave, :dados)', ['tabela' => 'preferencias_usuario', 'chave' => json_encode(['codpreferenciausuario' => (int)$current[0]['codpreferenciausuario']]), 'dados' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        else $this->call('CALL sp_crud_insert(:tabela, :dados)', ['tabela' => 'preferencias_usuario', 'dados' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return $transacao;
    }
}
