<?php
/**
 * unidades.php
 * Listagem de unidades e setores — usado em telas de cadastro,
 * registro de plantão e filtros de histórico.
 *
 *   GET /api/unidades.php?acao=listar
 *   GET /api/unidades.php?acao=setores&id_unidade=1
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$acao = $_GET['acao'] ?? '';

switch ($acao) {
    case 'listar':
        acaoListarUnidades();
        break;

    case 'setores':
        acaoListarSetores();
        break;

    default:
        responder(false, null, 'Ação inválida.', 400);
}

function acaoListarUnidades(): void
{
    $pdo = conexaoBanco();
    $stmt = $pdo->query('SELECT id_unidade, nome, sigla FROM unidades WHERE ativo = 1 ORDER BY nome ASC');
    responder(true, $stmt->fetchAll());
}

function acaoListarSetores(): void
{
    $idUnidade = (int)($_GET['id_unidade'] ?? 0);

    if ($idUnidade === 0) {
        responder(false, null, 'Informe a unidade.', 422);
    }

    $pdo = conexaoBanco();
    $stmt = $pdo->prepare('SELECT id_setor, nome FROM setores WHERE id_unidade = :id AND ativo = 1 ORDER BY nome ASC');
    $stmt->execute(['id' => $idUnidade]);

    responder(true, $stmt->fetchAll());
}
