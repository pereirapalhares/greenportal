<?php
declare(strict_types=1);

// Não Conformidades › Cadastros › Tipos de documento (menu NAO_CONFORMIDADES_CAD_TIPOS_DOCUMENTO, tabela nc_tipodedocumento; migração 327).
// Registros do sistema anterior só para consulta até a virada; os novos ficam na faixa do greenportal (classes/NaoConformidadeCadastros.php).
require_once dirname(__DIR__) . "/classes/NaoConformidadeCadastros.php";
NaoConformidadeCadastros::tela("tipos_documento");
