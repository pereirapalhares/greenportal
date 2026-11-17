<?php
declare(strict_types=1);

// Não Conformidades › Cadastros › Tipos de causa (menu NAO_CONFORMIDADES_CAD_TIPOS_CAUSA, tabela nc_tipos_causas; migração 332, Administração de NC — fase 1).
// Registros do sistema anterior só para consulta até a virada (menos a coluna Ativo); os novos ficam na faixa do greenportal (classes/NaoConformidadeCadastros.php).
require_once dirname(__DIR__) . "/classes/NaoConformidadeCadastros.php";
NaoConformidadeCadastros::tela("tipos_causa");
