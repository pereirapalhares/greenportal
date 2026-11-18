<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';

/**
 * Exportações (planilhas e PDFs da fila exportacoes_grid) no idioma de quem pediu (Onda 2 de idiomas, 18/11/2026).
 * O worker (classes/ExportacaoGridWorker.php) chama ExportacaoTextos::usarIdiomaDoPedido() no início; daí em diante
 * IdiomaService::t devolve o idioma do solicitante. Os rótulos das colunas já chegam traduzidos no pedido (montados pela tela).
 * Os alertas continuam gravados em português (AlertaTextos traduz na hora de mostrar, usando também tipo() abaixo).
 * Textos em Administração › Textos e idiomas, grupo "exportacao".
 */
final class ExportacaoTextos
{
    /** Nome da exportação (exportacoes_grid / config do worker) em português => [en, es]. */
    public const TIPOS = [
        'Aspectos relacionados' => ['Related aspects', 'Aspectos relacionados'],
        'Aspectos sociais relacionados' => ['Related social aspects', 'Aspectos sociales relacionados'],
        'Atividades ocupacionais relacionadas' => ['Related occupational activities', 'Actividades ocupacionales relacionadas'],
        'Atividades relacionadas' => ['Related activities', 'Actividades relacionadas'],
        'Cadastro de aspectos' => ['Aspects register', 'Registro de aspectos'],
        'Cadastro de assuntos' => ['Subjects register', 'Registro de asuntos'],
        'Cadastro de atividades' => ['Activities register', 'Registro de actividades'],
        'Cadastro de grupos' => ['Groups register', 'Registro de grupos'],
        'Cadastro de impactos' => ['Impacts register', 'Registro de impactos'],
        'Cadastro de legislação' => ['Legislation register', 'Registro de legislación'],
        'Cadastro de licenças' => ['Licenses register', 'Registro de licencias'],
        'Cadastro de listas' => ['Lists register', 'Registro de listas'],
        'Cadastro de menus - Setup / Menu' => ['Menus register - Setup / Menu', 'Registro de menús - Setup / Menú'],
        'Cadastro de perfis' => ['Profiles register', 'Registro de perfiles'],
        'Cadastro de perigos' => ['Hazards register', 'Registro de peligros'],
        'Cadastro de permissões' => ['Permissions register', 'Registro de permisos'],
        'Cadastro de riscos' => ['Risks register', 'Registro de riesgos'],
        'Cadastro de tipos de legislação' => ['Legislation types register', 'Registro de tipos de legislación'],
        'Cadastro de unidades' => ['Units register', 'Registro de unidades'],
        'Cadastro de usuários' => ['Users register', 'Registro de usuarios'],
        'Cadastro de áreas' => ['Areas register', 'Registro de áreas'],
        'Cadastro de âmbitos' => ['Scopes register', 'Registro de ámbitos'],
        'Configuração de campos de formulário' => ['Form field settings', 'Configuración de campos de formulario'],
        'Controle de documentos' => ['Document control', 'Control de documentos'],
        'Correlacionamento ambiental' => ['Environmental correlation', 'Correlación ambiental'],
        'Correlacionamento ocupacional' => ['Occupational correlation', 'Correlación ocupacional'],
        'Correlacionamento social' => ['Social correlation', 'Correlación social'],
        'Documentos do fornecedor' => ['Supplier documents', 'Documentos del proveedor'],
        'Documentos relacionados' => ['Related documents', 'Documentos relacionados'],
        'Impactos relacionados' => ['Related impacts', 'Impactos relacionados'],
        'Impactos sociais relacionados' => ['Related social impacts', 'Impactos sociales relacionados'],
        'Infraestrutura' => ['Infrastructure', 'Infraestructura'],
        'Legislação x Empresas' => ['Legislation x Companies', 'Legislación x Empresas'],
        'Legislação x Poder' => ['Legislation x Control level', 'Legislación x Nivel de control'],
        'Licencas relacionadas' => ['Related licenses', 'Licencias relacionadas'],
        'Meus controles' => ['My controls', 'Mis controles'],
        'Meus treinamentos' => ['My trainings', 'Mis capacitaciones'],
        'Minhas infraestruturas' => ['My infrastructure', 'Mis infraestructuras'],
        'Minhas licenças' => ['My licenses', 'Mis licencias'],
        'Painel de Homologação' => ['Supplier approval panel', 'Panel de Homologación'],
        'Perigos ocupacionais relacionados' => ['Related occupational hazards', 'Peligros ocupacionales relacionados'],
        'Pesquisa de documentos' => ['Document search', 'Búsqueda de documentos'],
        'Registros órfãos da migração' => ['Orphan migration records', 'Registros huérfanos de la migración'],
        'Relatório de Atendimento Legal' => ['Legal Compliance Report', 'Informe de Cumplimiento Legal'],
        'Relatórios de Homologação' => ['Approval reports', 'Informes de Homologación'],
        'Requisitos legais' => ['Legal requirements', 'Requisitos legales'],
        'Riscos ocupacionais relacionados' => ['Related occupational risks', 'Riesgos ocupacionales relacionados'],
        'Treinamentos' => ['Trainings', 'Capacitaciones'],
        'Lista geral de ocorrências' => ['General list of occurrences', 'Lista general de ocurrencias'],
        'Consumo de IA' => ['AI usage', 'Consumo de IA'],
    ];

    /** Textos fixos da planilha e da pesquisa de documentos: chave => [pt, en, es]. */
    private const TEXTOS = [
        'titulo' => ['Relatório profissional de dados do portal', 'Professional portal data report', 'Informe profesional de datos del portal'],
        'tipo_info' => ['Tipo de informação: {tipo}', 'Information type: {tipo}', 'Tipo de información: {tipo}'],
        'data_pedido' => ['Data da solicitação: {data}', 'Request date: {data}', 'Fecha de la solicitud: {data}'],
        'usuario' => ['Usuário solicitante: {nome}', 'Requested by: {nome}', 'Usuario solicitante: {nome}'],
        'informacao' => ['Informação', 'Information', 'Información'],
        'nenhum' => ['Nenhum registro encontrado.', 'No records found.', 'Ningún registro encontrado.'],
        'erro_planilha' => ['Não foi possível criar a planilha.', 'The spreadsheet could not be created.', 'No fue posible crear la planilla.'],
        'sim' => ['Sim', 'Yes', 'Sí'], 'nao' => ['Não', 'No', 'No'],
        // pesquisa de documentos (colunas e valores)
        'b_negocio' => ['Negócio', 'Business record', 'Negocio'], 'b_nome_negocio' => ['Nome do negócio', 'Record name', 'Nombre del negocio'],
        'b_detalhe' => ['Detalhe do negócio', 'Record details', 'Detalle del negocio'], 'b_tipo' => ['Tipo', 'Type', 'Tipo'], 'b_item' => ['Item', 'Item', 'Ítem'],
        'b_nome' => ['Nome', 'Name', 'Nombre'], 'b_titulo' => ['Título / descrição', 'Title / description', 'Título / descripción'], 'b_trecho' => ['Trecho encontrado', 'Matching excerpt', 'Fragmento encontrado'],
        'b_formato' => ['Formato', 'Format', 'Formato'], 'b_situacao' => ['Situação', 'Status', 'Situación'], 'b_unidade' => ['Unidade', 'Unit', 'Unidad'],
        'b_responsavel' => ['Responsável', 'Owner', 'Responsable'], 'b_validade' => ['Validade', 'Expiry', 'Validez'], 'b_modificado' => ['Modificado', 'Modified', 'Modificado'],
        'b_vinculos' => ['Vínculos', 'Links', 'Vínculos'], 'b_codigo' => ['Código', 'Code', 'Código'], 'b_link' => ['Link', 'Link', 'Enlace'],
        'b_arquivo' => ['Arquivo', 'File', 'Archivo'], 'b_registro' => ['Registro', 'Record', 'Registro'],
        'n_licencas' => ['Licença', 'License', 'Licencia'], 'n_controles' => ['Controle de documento', 'Document control', 'Control de documento'],
        'n_requisitos' => ['Requisito legal', 'Legal requirement', 'Requisito legal'], 'n_treinamentos' => ['Treinamento', 'Training', 'Capacitación'],
        'n_infraestrutura' => ['Infraestrutura', 'Infrastructure', 'Infraestructura'], 'n_fornecedorcriterios' => ['Documento de fornecedor', 'Supplier document', 'Documento de proveedor'],
        'n_funcionariocriterios' => ['Documento de funcionário', 'Employee document', 'Documento de empleado'], 'n_funcionariotreinamentos' => ['Treinamento de funcionário', 'Employee training', 'Capacitación de empleado'],
        'n_fornecedor' => ['Fornecedor', 'Supplier', 'Proveedor'], 'n_funcionario' => ['Funcionário', 'Employee', 'Empleado'],
    ];

    /** Rótulos das planilhas da Homologação (texto em português => tradução; chave exportacao.h_<md5>). */
    private const ROTULOS = [
        'Cor' => ['Color', 'Color'],
        'Informação' => ['Information', 'Información'],
        'Unidade' => ['Unit', 'Unidad'],
        'Fornecedor' => ['Supplier', 'Proveedor'],
        'CNPJ/CPF' => ['Tax ID (CNPJ/CPF)', 'CNPJ/CPF'],
        'Funcionário' => ['Employee', 'Empleado'],
        'Nº do registro' => ['Registration no.', 'N.º de registro'],
        'Tipo de funcionário' => ['Employee type', 'Tipo de empleado'],
        'Setor' => ['Department', 'Sector'],
        'Integração válida até' => ['Induction valid until', 'Integración válida hasta'],
        'Itens em dia' => ['Items up to date', 'Ítems al día'],
        'Data de início' => ['Start date', 'Fecha de inicio'],
        'Situação' => ['Status', 'Situación'],
        'Em dia' => ['Up to date', 'Al día'],
        'Pendências' => ['Pending items', 'Pendencias'],
        'Grupo' => ['Group', 'Grupo'],
        'Nome fantasia' => ['Trade name', 'Nombre comercial'],
        'Responsável pelo fornecedor (cadastro)' => ['Supplier owner (registration)', 'Responsable del proveedor (registro)'],
        'Responsável pelo critério' => ['Criterion owner', 'Responsable del criterio'],
        'Responsável do fornecedor' => ['Supplier\'s contact', 'Responsable del proveedor'],
        'Observação da empresa' => ['Company note', 'Observación de la empresa'],
        'Observação do fornecedor' => ['Supplier note', 'Observación del proveedor'],
        'Data de cobrança do protocolo' => ['Filing reminder date', 'Fecha de cobro del protocolo'],
        'Vermelho' => ['Red', 'Rojo'],
        'Amarelo' => ['Yellow', 'Amarillo'],
        'Verde' => ['Green', 'Verde'],
        'Cinza' => ['Gray', 'Gris'],
        'Branco' => ['White', 'Blanco'],
        'Nenhum documento encontrado para os filtros escolhidos.' => ['No documents found for the chosen filters.', 'Ningún documento encontrado para los filtros elegidos.'],
        'Nenhum funcionário liberado para os filtros escolhidos.' => ['No cleared employees for the chosen filters.', 'Ningún empleado liberado para los filtros elegidos.'],
        'Nenhum funcionário encontrado para os filtros escolhidos.' => ['No employees found for the chosen filters.', 'Ningún empleado encontrado para los filtros elegidos.'],
        'Nenhum item encontrado para o relatório e os filtros escolhidos.' => ['No items found for the report and the chosen filters.', 'Ningún ítem encontrado para el informe y los filtros elegidos.'],
        'Pedido sem fornecedor.' => ['Request without supplier.', 'Pedido sin proveedor.'],
        'Pedido sem usuário.' => ['Request without user.', 'Pedido sin usuario.'],
    ];

    public static function rotulo(string $pt): string
    {
        if (!isset(self::ROTULOS[$pt])) return $pt;
        [$en, $es] = self::ROTULOS[$pt];
        return IdiomaService::t('exportacao.h_' . substr(md5($pt), 0, 10), ['pt' => $pt, 'en' => $en, 'es' => $es]);
    }

    /** Itens para registrar no banco (scripts/aplicar-onda2-banco-idiomas.php). */
    public static function itensIdioma(): array
    {
        $itens = []; $o = 0;
        foreach (self::TEXTOS as $k => [$pt, $en, $es]) $itens[] = ['chave' => 'exportacao.' . $k, 'tipo' => 'TEXTO', 'ordem' => ++$o, 'descricao' => 'Exportação para Excel', 'pt' => $pt, 'en' => $en, 'es' => $es];
        foreach (self::ROTULOS as $pt => [$en, $es]) $itens[] = ['chave' => 'exportacao.h_' . substr(md5($pt), 0, 10), 'tipo' => 'TEXTO', 'ordem' => ++$o, 'descricao' => 'Exportação da Homologação', 'pt' => $pt, 'en' => $en, 'es' => $es];
        foreach (self::TIPOS as $pt => [$en, $es]) $itens[] = ['chave' => 'exportacao.tipo_' . substr(md5($pt), 0, 10), 'tipo' => 'TEXTO', 'ordem' => ++$o, 'descricao' => 'Nome da exportação', 'pt' => $pt, 'en' => $en, 'es' => $es];
        return $itens;
    }

    /** Idioma do pedido (filtros.idioma) ou do cadastro do solicitante; passa a valer para IdiomaService neste processo. */
    public static function usarIdiomaDoPedido(PDO $pdo, array $job): string
    {
        $filtros = json_decode((string)($job['filtros'] ?? '{}'), true);
        $idioma = is_array($filtros) ? strtolower((string)($filtros['idioma'] ?? ($filtros['pedido']['idioma'] ?? ''))) : '';
        if (!in_array($idioma, IdiomaService::IDIOMAS, true)) {
            $idioma = 'pt';
            try {
                require_once __DIR__ . '/UsuarioImagem.php';
                $idioma = UsuarioImagem::idioma($pdo, (int)($job['codusuario'] ?? 0)) ?? 'pt';
            } catch (Throwable) {}
        }
        if (!in_array($idioma, IdiomaService::IDIOMAS, true)) $idioma = 'pt';
        IdiomaService::usarIdioma($idioma);
        return $idioma;
    }

    public static function t(string $chave, array $valores = []): string
    {
        $d = self::TEXTOS[$chave] ?? [$chave, $chave, $chave];
        return IdiomaService::f('exportacao.' . $chave, ['pt' => $d[0], 'en' => $d[1], 'es' => $d[2]], $valores);
    }

    /** Nome da exportação no idioma pedido (ou no da requisição); desconhecido fica como está. */
    public static function tipo(string $pt, ?string $idioma = null): string
    {
        $idioma = $idioma ?? IdiomaService::idioma();
        if ($idioma === 'pt' || !isset(self::TIPOS[$pt])) return $pt;
        $chave = 'exportacao.tipo_' . substr(md5($pt), 0, 10);
        $t = IdiomaService::textos($chave, ['pt' => $pt, 'en' => self::TIPOS[$pt][0], 'es' => self::TIPOS[$pt][1]]);
        return (string)($t[$idioma] ?? $pt);
    }

    /** Data e hora no formato do idioma (en: m/d/Y). */
    public static function dataHora(?int $ts = null): string
    {
        return date(IdiomaService::idioma() === 'en' ? 'm/d/Y H:i:s' : 'd/m/Y H:i:s', $ts ?? time());
    }
}
