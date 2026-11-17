<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';

/**
 * Mensagens de erro de gravação para o usuário, no idioma dele.
 * Os textos ficam em Administração › Textos e idiomas (comum.erro.*, comum.acao.*); os daqui são o padrão.
 * $acao: chave curta (concluir, salvar, excluir, associar, salvar_menu) ou a frase antiga em português.
 */
final class MensagemAplicacao
{
    private const ACOES = [
        'concluir' => ['pt' => 'concluir a operação', 'en' => 'complete the operation', 'es' => 'completar la operación'],
        'salvar' => ['pt' => 'salvar o registro', 'en' => 'save the record', 'es' => 'guardar el registro'],
        'excluir' => ['pt' => 'excluir o registro', 'en' => 'delete the record', 'es' => 'eliminar el registro'],
        'associar' => ['pt' => 'gravar as associações', 'en' => 'save the associations', 'es' => 'guardar las asociaciones'],
        'salvar_menu' => ['pt' => 'salvar o menu', 'en' => 'save the menu', 'es' => 'guardar el menú'],
    ];
    /** Frases usadas antes desta mudança, mantidas para quem ainda chama erro($e, 'salvar o menu'). */
    private const FRASES = ['concluir a operação' => 'concluir', 'salvar o menu' => 'salvar_menu', 'salvar o registro' => 'salvar', 'excluir o registro' => 'excluir'];

    public static function erro(Throwable $exception, string $acao = 'concluir'): string
    {
        $chaveAcao = self::FRASES[mb_strtolower(trim($acao), 'UTF-8')] ?? $acao;
        $textoAcao = isset(self::ACOES[$chaveAcao]) ? IdiomaService::t('comum.acao.' . $chaveAcao, self::ACOES[$chaveAcao]) : $acao;
        $codigo = (string)$exception->getCode();
        $detalhe = strtolower($exception->getMessage());
        $valores = ['acao' => $textoAcao];
        if ($codigo === '23000' && (str_contains($detalhe, 'duplicate') || str_contains($detalhe, 'uk_'))) {
            return IdiomaService::f('comum.erro.duplicado', ['pt' => 'Não foi possível {acao} porque já existe um cadastro com o mesmo código ou identificação. Informe um valor diferente e tente novamente.', 'en' => 'We could not {acao} because a record with the same code or identifier already exists. Enter a different value and try again.', 'es' => 'No fue posible {acao} porque ya existe un registro con el mismo código o identificación. Informe otro valor e inténtelo nuevamente.'], $valores);
        }
        // Campo obrigatório antes de vínculo: a mensagem do MySQL para NULL também contém "constraint".
        if ($codigo === '23000' && (str_contains($detalhe, 'cannot be null') || str_contains($detalhe, '1048'))) {
            return IdiomaService::f('comum.erro.obrigatorio', ['pt' => 'Não foi possível {acao} porque há campos obrigatórios sem preenchimento. Revise o formulário e tente novamente.', 'en' => 'We could not {acao} because required fields are empty. Review the form and try again.', 'es' => 'No fue posible {acao} porque hay campos obligatorios vacíos. Revise el formulario e inténtelo nuevamente.'], $valores);
        }
        if ($codigo === '23000' && (str_contains($detalhe, 'foreign key') || str_contains($detalhe, '1451') || str_contains($detalhe, '1452') || str_contains($detalhe, 'constraint'))) {
            return IdiomaService::f('comum.erro.vinculo', ['pt' => 'Não foi possível {acao} porque este registro está relacionado a outras informações. Revise os vínculos ou remova as dependências antes de continuar.', 'en' => 'We could not {acao} because this record is related to other information. Review the links or remove the dependencies before continuing.', 'es' => 'No fue posible {acao} porque este registro está relacionado con otra información. Revise los vínculos o elimine las dependencias antes de continuar.'], $valores);
        }
        return IdiomaService::f('comum.erro.generico', ['pt' => 'Não foi possível {acao}. Revise os dados informados e tente novamente. Se o problema continuar, solicite apoio ao administrador do sistema.', 'en' => 'We could not {acao}. Review the information and try again. If the problem persists, contact the system administrator.', 'es' => 'No fue posible {acao}. Revise la información e inténtelo nuevamente. Si el problema continúa, solicite ayuda al administrador del sistema.'], $valores);
    }
}
