<?php
/**
 * relatorios.php
 * Geração de relatórios exportáveis (XLSX, CSV, PDF) com nomenclatura
 * dinâmica baseada nos filtros aplicados (unidade, período, funcionário).
 *
 *   GET /api/relatorios.php?formato=csv&ano=2026&mes=1&id_unidade=1
 *   GET /api/relatorios.php?formato=xlsx&...
 *   GET /api/relatorios.php?formato=pdf&...
 *
 * Observação: geração de XLSX/PDF requer bibliotecas externas
 * (ex.: PhpSpreadsheet para XLSX, DOMPDF/TCPDF para PDF), a serem
 * instaladas via Composer em uma etapa posterior de implementação.
 * Este arquivo já contém a lógica de consulta e nomenclatura de arquivo,
 * faltando plugar a biblioteca de exportação escolhida.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$usuario = exigirPerfil(['gerente', 'administrador']);

$formato    = $_GET['formato'] ?? 'csv';
$ano        = (int)($_GET['ano'] ?? date('Y'));
$mes        = $_GET['mes'] ?? null; // pode ser null (intervalo aberto)
$idUnidade  = $_GET['id_unidade'] ?? null; // apenas administrador pode variar
$idsFuncionarios = $_GET['id_usuario'] ?? []; // array opcional

if (!in_array($formato, ['xlsx', 'csv', 'pdf'], true)) {
    responder(false, null, 'Formato de exportação inválido.', 422);
}

$pdo = conexaoBanco();

$condicoes = ['YEAR(p.data_plantao) = :ano'];
$params = ['ano' => $ano];

if ($mes) {
    $condicoes[] = 'MONTH(p.data_plantao) = :mes';
    $params['mes'] = (int) $mes;
}

if ($usuario['perfil'] === 'gerente') {
    $unidades = $usuario['unidades'] ?: [0];
    $placeholders = implode(',', array_map(fn($i) => ":unid{$i}", array_keys($unidades)));
    $condicoes[] = "p.id_unidade IN ($placeholders)";
    foreach ($unidades as $i => $u) {
        $params["unid{$i}"] = $u;
    }
} elseif ($idUnidade) {
    $condicoes[] = 'p.id_unidade = :id_unidade';
    $params['id_unidade'] = (int) $idUnidade;
}

if (!empty($idsFuncionarios) && is_array($idsFuncionarios)) {
    $placeholders = implode(',', array_map(fn($i) => ":func{$i}", array_keys($idsFuncionarios)));
    $condicoes[] = "p.id_usuario IN ($placeholders)";
    foreach ($idsFuncionarios as $i => $idFunc) {
        $params["func{$i}"] = (int) $idFunc;
    }
}

$where = implode(' AND ', $condicoes);

$stmt = $pdo->prepare(
    "SELECT u.nome_completo, u.matricula, un.nome AS unidade, p.tipo_plantao,
            p.data_plantao, p.entrada, p.saida, p.status, p.producao
     FROM plantoes p
     JOIN usuarios u ON u.id_usuario = p.id_usuario
     JOIN unidades un ON un.id_unidade = p.id_unidade
     WHERE {$where}
     ORDER BY p.data_plantao ASC"
);
$stmt->execute($params);
$linhas = $stmt->fetchAll();

// --- Nomenclatura dinâmica do arquivo (requisito 5.3 / 6.3) ---
$partesNome = ['Relatorio'];

if (!empty($idUnidade)) {
    $partesNome[] = "Unidade-{$idUnidade}";
}
$partesNome[] = date('F-Y', mktime(0, 0, 0, (int)($mes ?: 1), 1, $ano));
if (!empty($idsFuncionarios)) {
    $partesNome[] = 'Funcionario-' . implode('-', array_map('intval', $idsFuncionarios));
}

$nomeArquivo = implode('_', $partesNome) . '.' . $formato;

switch ($formato) {
    case 'csv':
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$nomeArquivo}\"");

        $saida = fopen('php://output', 'w');
        fputcsv($saida, ['Nome', 'Matrícula', 'Unidade', 'Tipo', 'Data', 'Entrada', 'Saída', 'Status', 'Produção']);
        foreach ($linhas as $linha) {
            fputcsv($saida, $linha);
        }
        fclose($saida);
        exit;

    case 'xlsx':
    case 'pdf':
        // TODO: integrar PhpSpreadsheet (xlsx) / DOMPDF ou TCPDF (pdf) via Composer.
        responder(false, null, "Exportação em {$formato} pendente de integração com biblioteca externa.", 501);
}
