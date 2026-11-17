<?php
declare(strict_types=1);

// Não Conformidades › Cadastros › Unidades do cliente (menu NAO_CONFORMIDADES_CAD_UNIDADES_CLIENTE, tabela nc_unidadesdocliente; migração 327).
// Registros do sistema anterior só para consulta até a virada; os novos ficam na faixa do greenportal (classes/NaoConformidadeCadastros.php).
require_once dirname(__DIR__) . "/classes/NaoConformidadeCadastros.php";
NaoConformidadeCadastros::tela("unidades_cliente");
