<?php
/**
 * pedidos.php
 * Registro de plantões (funcionário) e avaliação de pedidos (gerente).
 *
 * Roteamento via ?acao=
 *   POST /api/pedidos.php?acao=registrar     (funcionario)
 *   GET  /api/pedidos.php?acao=pendentes     (gerente)
 *   POST /api/pedidos.php?acao=aprovar       (gerente)
 *   POST /api/pedidos.php?acao=negar         (gerente)
 *   GET  /api/pedidos.php?acao=historico     (funcionario|gerente|administrador)
 *        Query params aceitos em 'historico':
 *          ano          (obrigatório, padrão: ano atual)
 *          mes          (opcional — se omitido, traz o ano inteiro)
 *          pagina       (opcional, padrão 1)
 *          id_unidade   (opcional — só tem efeito para administrador)
 *          id_usuario[] (opcional — array; gerente só pode filtrar dentro da própria unidade,
 *                        administrador pode filtrar qualquer funcionário)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// ------------------------------------------------------------
// Corte diurno/noturno (regra de negócio). Ajustar aqui se a
// secretaria adotar outro horário de corte.
// ------------------------------------------------------------
const HORA_INICIO_DIURNO   = 7;  // 07:00
const HORA_INICIO_NOTURNO  = 19; // 19:00

$acao = $_GET['acao'] ?? '';

switch ($acao) {
    case 'registrar':
        acaoRegistrarPlantao();
        break;

    case 'pendentes':
        acaoListarPendentes();
        break;

    case 'aprovar':
        acaoAprovar();
        break;

    case 'negar':
        acaoNegar();
        break;

    case 'historico':
        acaoHistorico();
        break;

    case 'kpis':
        acaoKpis();
        break;

    case 'cancelar':
        acaoCancelar();
        break;

    default:
        responder(false, null, 'Ação inválida.', 400);
}

/** Funcionário registra um novo plantão (com pausas). */
function acaoRegistrarPlantao(): void
{
    $usuario = exigirPerfil(['funcionario', 'gerente', 'administrador']);
    $dados   = corpoRequisicaoJson();

    $idUnidade = (int)($dados['id_unidade'] ?? 0);
    $setor     = trim((string)($dados['setor'] ?? ''));
    $tipo      = (string)($dados['tipo_plantao'] ?? ''); // 6h|12h|24h
    $entrada   = (string)($dados['entrada'] ?? '');
    $saida     = $dados['saida'] ?? null;
    $pausas    = $dados['pausas'] ?? []; // [{inicio, fim}, ...]

    if (!in_array($tipo, ['6h', '12h', '24h'], true) || $idUnidade === 0 || $entrada === '') {
        responder(false, null, 'Dados obrigatórios ausentes ou inválidos.', 422);
    }

    $pdo = conexaoBanco();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO plantoes (id_usuario, id_unidade, setor, tipo_plantao, data_plantao, entrada, saida, status)
             VALUES (:id_usuario, :id_unidade, :setor, :tipo, DATE(:data_ref), :entrada, :saida, "pendente")'
        );
        $stmt->execute([
            'id_usuario' => $usuario['id_usuario'],
            'id_unidade' => $idUnidade,
            'setor'      => $setor !== '' ? $setor : null,
            'tipo'       => $tipo,
            'data_ref'   => $entrada,
            'entrada'    => $entrada,
            'saida'      => $saida,
        ]);

        $idPlantao = (int) $pdo->lastInsertId();

        $stmtPausa = $pdo->prepare(
            'INSERT INTO pausas (id_plantao, inicio_pausa, fim_pausa) VALUES (:id_plantao, :inicio, :fim)'
        );
        $pausasValidas = [];
        foreach ($pausas as $pausa) {
            $inicio = $pausa['inicio'] ?? null;
            if (!$inicio) {
                continue; // ignora linhas de pausa vazias enviadas pelo formulário
            }
            $fim = $pausa['fim'] ?? null;
            $stmtPausa->execute([
                'id_plantao' => $idPlantao,
                'inicio'     => $inicio,
                'fim'        => $fim,
            ]);
            $pausasValidas[] = ['inicio' => $inicio, 'fim' => $fim];
        }

        // Só é possível calcular horas diurnas/noturnas quando a saída já
        // foi informada no ato do registro. Quando saída ficar em aberto,
        // o cálculo pode ser plugado depois (ex: ao registrar a saída ou
        // ao aprovar o plantão).
        if ($saida) {
            [$horasDiurnas, $horasNoturnas] = calcularHorasDiaNoite($entrada, $saida, $pausasValidas);

            $pdo->prepare(
                'UPDATE plantoes SET horas_diurnas = :diurnas, horas_noturnas = :noturnas WHERE id_plantao = :id'
            )->execute([
                'diurnas'  => $horasDiurnas,
                'noturnas' => $horasNoturnas,
                'id'       => $idPlantao,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e; // deixa o handler global (bootstrap.php) formatar a resposta JSON
    }

    responder(true, ['id_plantao' => $idPlantao], 'Plantão registrado. Aguardando avaliação.', 201);
}

/**
 * Calcula quantas horas do intervalo entrada→saída caem no período diurno
 * e quantas caem no período noturno (conforme HORA_INICIO_DIURNO /
 * HORA_INICIO_NOTURNO), descontando os intervalos de pausa informados.
 *
 * Retorna [horas_diurnas, horas_noturnas] arredondadas em 2 casas decimais.
 */
function calcularHorasDiaNoite(string $entrada, string $saida, array $pausas): array
{
    try {
        $inicio = new DateTime($entrada);
        $fim    = new DateTime($saida);
    } catch (Exception $e) {
        return [null, null];
    }

    if ($fim <= $inicio) {
        return [null, null]; // dados inconsistentes; não classifica
    }

    [$diurnoMin, $noturnoMin] = contarMinutosPorTurno($inicio, $fim);

    // Desconta as pausas (proporcionalmente ao turno em que cada minuto de pausa ocorreu)
    foreach ($pausas as $pausa) {
        if (empty($pausa['inicio']) || empty($pausa['fim'])) {
            continue; // pausa sem os dois horários não entra no desconto
        }
        try {
            $pInicio = new DateTime($pausa['inicio']);
            $pFim    = new DateTime($pausa['fim']);
        } catch (Exception $e) {
            continue;
        }
        if ($pFim <= $pInicio) {
            continue;
        }
        [$pDiurno, $pNoturno] = contarMinutosPorTurno($pInicio, $pFim);
        $diurnoMin  = max(0, $diurnoMin - $pDiurno);
        $noturnoMin = max(0, $noturnoMin - $pNoturno);
    }

    return [
        round($diurnoMin / 60, 2),
        round($noturnoMin / 60, 2),
    ];
}

/**
 * Conta, minuto a minuto, quantos minutos de um intervalo caem no turno
 * diurno e quantos caem no turno noturno.
 */
function contarMinutosPorTurno(DateTime $inicio, DateTime $fim): array
{
    $diurno  = 0;
    $noturno = 0;

    $cursor = clone $inicio;
    while ($cursor < $fim) {
        $hora = (int) $cursor->format('H');
        if ($hora >= HORA_INICIO_DIURNO && $hora < HORA_INICIO_NOTURNO) {
            $diurno++;
        } else {
            $noturno++;
        }
        $cursor->modify('+1 minute');
    }

    return [$diurno, $noturno];
}

/** Gerente lista pedidos pendentes da sua unidade. */
function acaoListarPendentes(): void
{
    $usuario  = exigirPerfil(['gerente']);
    $unidades = $usuario['unidades'];

    if (empty($unidades)) {
        responder(true, [], 'Nenhuma unidade vinculada ao seu usuário.');
    }

    $pdo = conexaoBanco();
    $placeholders = implode(',', array_fill(0, count($unidades), '?'));

    $stmt = $pdo->prepare(
        "SELECT p.id_plantao, p.tipo_plantao, p.setor, p.data_plantao, p.entrada, p.saida,
                u.nome_completo, u.matricula
         FROM plantoes p
         JOIN usuarios u ON u.id_usuario = p.id_usuario
         WHERE p.status = 'pendente' AND p.id_unidade IN ($placeholders)
         ORDER BY p.data_plantao ASC"
    );
    $stmt->execute(array_values($unidades));

    responder(true, $stmt->fetchAll());
}

/** Gerente aprova um pedido — exige campo de produção. */
function acaoAprovar(): void
{
    $usuario = exigirPerfil(['gerente']);
    $dados   = corpoRequisicaoJson();

    $idPlantao = (int)($dados['id_plantao'] ?? 0);
    $producao  = $dados['producao'] ?? null;

    if ($idPlantao === 0 || $producao === null || (int)$producao < 0) {
        responder(false, null, 'Informe a produção (quantidade de atendimentos).', 422);
    }

    $pdo = conexaoBanco();

    // Garante que o plantão pertence a uma unidade do gerente autenticado.
    if (!plantaoPertenceAsUnidades($pdo, $idPlantao, $usuario['unidades'])) {
        responder(false, null, 'Plantão não encontrado na(s) sua(s) unidade(s).', 404);
    }

    $ok = $pdo->prepare(
        'UPDATE plantoes SET status = "aceito", producao = :producao, avaliado_por = :avaliador, avaliado_em = NOW()
         WHERE id_plantao = :id'
    )->execute([
        'producao'  => (int)$producao,
        'avaliador' => $usuario['id_usuario'],
        'id'        => $idPlantao,
    ]);

    responder((bool)$ok, null, $ok ? 'Plantão aprovado.' : 'Erro ao aprovar plantão.');
}

/** Gerente nega um pedido — exige justificativa textual. */
function acaoNegar(): void
{
    $usuario = exigirPerfil(['gerente']);
    $dados   = corpoRequisicaoJson();

    $idPlantao     = (int)($dados['id_plantao'] ?? 0);
    $justificativa = trim((string)($dados['justificativa'] ?? ''));

    if ($idPlantao === 0 || $justificativa === '') {
        responder(false, null, 'Informe a justificativa da negação.', 422);
    }

    $pdo = conexaoBanco();

    if (!plantaoPertenceAsUnidades($pdo, $idPlantao, $usuario['unidades'])) {
        responder(false, null, 'Plantão não encontrado na(s) sua(s) unidade(s).', 404);
    }

    $ok = $pdo->prepare(
        'UPDATE plantoes SET status = "negado", justificativa_negacao = :just, avaliado_por = :avaliador, avaliado_em = NOW()
         WHERE id_plantao = :id'
    )->execute([
        'just'      => $justificativa,
        'avaliador' => $usuario['id_usuario'],
        'id'        => $idPlantao,
    ]);

    responder((bool)$ok, null, $ok ? 'Plantão negado.' : 'Erro ao negar plantão.');
}

/** Confere se um plantão pertence a alguma das unidades informadas (checagem de segurança). */
function plantaoPertenceAsUnidades(PDO $pdo, int $idPlantao, array $unidades): bool
{
    if (empty($unidades)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($unidades), '?'));
    $stmt = $pdo->prepare(
        "SELECT 1 FROM plantoes WHERE id_plantao = ? AND id_unidade IN ($placeholders) LIMIT 1"
    );
    $stmt->execute(array_merge([$idPlantao], array_values($unidades)));

    return (bool) $stmt->fetchColumn();
}

/**
 * Histórico paginado por competência (mês/ano), com filtros por perfil:
 *   - funcionario:   somente os próprios plantões
 *   - gerente:       plantões das suas unidades; pode filtrar por funcionário(s) específico(s)
 *   - administrador: visão global; pode filtrar por unidade e/ou funcionário(s)
 *
 * Query params: ano, mes (opcional), pagina, id_unidade (admin), id_usuario[] (gerente/admin)
 */
function acaoHistorico(): void
{
    $usuario = exigirAutenticacao();

    $ano       = (int)($_GET['ano'] ?? date('Y'));
    $mes       = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;
    $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
    $porPagina = 20;
    $offset    = ($pagina - 1) * $porPagina;

    $pdo = conexaoBanco();

    $condicoes = ['YEAR(p.data_plantao) = :ano'];
    $params    = ['ano' => $ano];

    if ($mes !== null) {
        $condicoes[] = 'MONTH(p.data_plantao) = :mes';
        $params['mes'] = $mes;
    }

    if ($usuario['perfil'] === 'funcionario') {
        // Funcionário só vê o próprio histórico — nenhum filtro adicional é aceito.
        $condicoes[] = 'p.id_usuario = :id_usuario';
        $params['id_usuario'] = $usuario['id_usuario'];
    } else {
        // Gerente: restrito às próprias unidades.
        if ($usuario['perfil'] === 'gerente') {
            $unidades = $usuario['unidades'] ?: [0];
            $condicoes[] = montarClausulaIn('p.id_unidade', 'unid', $unidades, $params);
        }

        // Administrador: pode filtrar por uma unidade específica (opcional).
        if ($usuario['perfil'] === 'administrador' && !empty($_GET['id_unidade'])) {
            $condicoes[] = 'p.id_unidade = :id_unidade';
            $params['id_unidade'] = (int) $_GET['id_unidade'];
        }

        // Gerente e administrador podem filtrar por um ou mais funcionários.
        $idsFuncionarios = $_GET['id_usuario'] ?? [];
        if (!is_array($idsFuncionarios)) {
            $idsFuncionarios = [$idsFuncionarios];
        }
        $idsFuncionarios = array_filter(array_map('intval', $idsFuncionarios));

        if (!empty($idsFuncionarios)) {
            $condicoes[] = montarClausulaIn('p.id_usuario', 'func', $idsFuncionarios, $params);
        }
    }

    $where = implode(' AND ', $condicoes);

    // Total de registros (para a paginação no front-end)
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM plantoes p WHERE {$where}");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT p.id_plantao, p.tipo_plantao, p.setor, p.data_plantao, p.entrada, p.saida, p.status,
                p.producao, p.justificativa_negacao, u.nome_completo, u.matricula, un.nome AS unidade
         FROM plantoes p
         JOIN usuarios u ON u.id_usuario = p.id_usuario
         JOIN unidades un ON un.id_unidade = p.id_unidade
         WHERE {$where}
         ORDER BY p.data_plantao DESC
         LIMIT {$porPagina} OFFSET {$offset}"
    );
    $stmt->execute($params);

    responder(true, [
        'pagina'         => $pagina,
        'por_pagina'     => $porPagina,
        'total'          => $total,
        'total_paginas'  => (int) ceil($total / $porPagina),
        'registros'      => $stmt->fetchAll(),
    ]);
}

/**
 * KPIs de horas do mês corrente para o funcionário logado:
 * aceitas (total), diurnas, noturnas (ambas apenas de plantões aceitos),
 * negadas e pendentes (total de horas nesses status).
 *
 * Observação: plantões sem "saída" preenchida ainda não têm horas
 * calculadas (horas_diurnas/horas_noturnas ficam NULL) — por isso entram
 * como 0h no KPI até que a saída seja registrada.
 */
function acaoKpis(): void
{
    $usuario = exigirAutenticacao();

    $ano = (int) date('Y');
    $mes = (int) date('m');

    $pdo = conexaoBanco();
    $stmt = $pdo->prepare(
        "SELECT status,
                COALESCE(SUM(horas_diurnas), 0)  AS soma_diurnas,
                COALESCE(SUM(horas_noturnas), 0) AS soma_noturnas
         FROM plantoes
         WHERE id_usuario = :id_usuario
           AND YEAR(data_plantao) = :ano
           AND MONTH(data_plantao) = :mes
         GROUP BY status"
    );
    $stmt->execute([
        'id_usuario' => $usuario['id_usuario'],
        'ano'        => $ano,
        'mes'        => $mes,
    ]);

    $porStatus = [
        'aceito'   => ['soma_diurnas' => 0, 'soma_noturnas' => 0],
        'negado'   => ['soma_diurnas' => 0, 'soma_noturnas' => 0],
        'pendente' => ['soma_diurnas' => 0, 'soma_noturnas' => 0],
    ];
    foreach ($stmt->fetchAll() as $linha) {
        $porStatus[$linha['status']] = $linha;
    }

    $diurnasAceitas  = (float) $porStatus['aceito']['soma_diurnas'];
    $noturnasAceitas = (float) $porStatus['aceito']['soma_noturnas'];

    responder(true, [
        'horas_aceitas'   => round($diurnasAceitas + $noturnasAceitas, 2),
        'horas_diurnas'   => round($diurnasAceitas, 2),
        'horas_noturnas'  => round($noturnasAceitas, 2),
        'horas_negadas'   => round((float) $porStatus['negado']['soma_diurnas'] + (float) $porStatus['negado']['soma_noturnas'], 2),
        'horas_pendentes' => round((float) $porStatus['pendente']['soma_diurnas'] + (float) $porStatus['pendente']['soma_noturnas'], 2),
    ]);
}

/**
 * Funcionário cancela um pedido próprio, desde que ainda esteja pendente.
 */
function acaoCancelar(): void
{
    $usuario = exigirAutenticacao();
    $dados   = corpoRequisicaoJson();

    $idPlantao = (int) ($dados['id_plantao'] ?? 0);
    if ($idPlantao === 0) {
        responder(false, null, 'Plantão inválido.', 422);
    }

    $pdo = conexaoBanco();

    $stmt = $pdo->prepare('SELECT id_usuario, status FROM plantoes WHERE id_plantao = :id');
    $stmt->execute(['id' => $idPlantao]);
    $plantao = $stmt->fetch();

    if (!$plantao || (int) $plantao['id_usuario'] !== $usuario['id_usuario']) {
        responder(false, null, 'Plantão não encontrado.', 404);
    }

    if ($plantao['status'] !== 'pendente') {
        responder(false, null, 'Só é possível cancelar pedidos que ainda estão pendentes.', 422);
    }

    $ok = $pdo->prepare('DELETE FROM plantoes WHERE id_plantao = :id')->execute(['id' => $idPlantao]);

    responder((bool) $ok, null, $ok ? 'Pedido cancelado.' : 'Erro ao cancelar pedido.');
}

/**
 * Monta uma cláusula "coluna IN (:prefixo0, :prefixo1, ...)" e já injeta
 * os parâmetros correspondentes no array $params (passado por referência).
 */
function montarClausulaIn(string $coluna, string $prefixo, array $valores, array &$params): string
{
    $valores = array_values($valores);
    $nomes = [];
    foreach ($valores as $i => $valor) {
        $nome = "{$prefixo}{$i}";
        $nomes[] = ":{$nome}";
        $params[$nome] = $valor;
    }
    return "{$coluna} IN (" . implode(',', $nomes) . ')';
}