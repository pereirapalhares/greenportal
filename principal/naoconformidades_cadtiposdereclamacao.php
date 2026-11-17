<?php
declare(strict_types=1);

// Não Conformidades › Cadastros › Tipos de reclamação (menu NAO_CONFORMIDADES_CAD_TIPOS_RECLAMACAO, tabela nc_tiporeclamacao; migração 327).
// Registros do sistema anterior só para consulta até a virada; os novos ficam na faixa do greenportal (classes/NaoConformidadeCadastros.php).
require_once dirname(__DIR__) . "/classes/NaoConformidadeCadastros.php";
NaoConformidadeCadastros::tela("tipos_reclamacao");
