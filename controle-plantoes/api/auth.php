<?php
/**
 * auth.php
 * Endpoints: login, cadastro, logout, recuperação de senha (stub).
 *
 * Roteamento simples via parâmetro ?acao=
 *   POST /api/auth.php?acao=login
 *   POST /api/auth.php?acao=cadastro
 *   POST /api/auth.php?acao=logout
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$acao = $_GET['acao'] ?? '';

switch ($acao) {
    case 'login':
        acaoLogin();
        break;

    case 'cadastro':
        acaoCadastro();
        break;

    case 'logout':
        acaoLogout();
        break;

    case 'me':
        acaoMe();
        break;

    default:
        responder(false, null, 'Ação inválida.', 400);
}

/** Retorna os dados do usuário autenticado (usado por index.html e meu-perfil.html). */
function acaoMe(): void
{
    $usuario = exigirAutenticacao();
    $pdo = conexaoBanco();

    $stmt = $pdo->prepare(
        'SELECT id_usuario, nome_completo, matricula, email, perfil
         FROM usuarios WHERE id_usuario = :id'
    );
    $stmt->execute(['id' => $usuario['id_usuario']]);
    $dados = $stmt->fetch();

    if (!$dados) {
        responder(false, null, 'Usuário não encontrado.', 404);
    }

    $stmtUnid = $pdo->prepare(
        'SELECT un.id_unidade, un.nome FROM usuario_unidade uu
         JOIN unidades un ON un.id_unidade = uu.id_unidade
         WHERE uu.id_usuario = :id'
    );
    $stmtUnid->execute(['id' => $usuario['id_usuario']]);
    $dados['unidades'] = $stmtUnid->fetchAll();

    responder(true, $dados);
}

function acaoLogin(): void
{
    $dados = corpoRequisicaoJson();
    $identificador = trim((string)($dados['identificador'] ?? '')); // matrícula ou e-mail
    $senha         = (string)($dados['senha'] ?? '');

    if ($identificador === '' || $senha === '') {
        responder(false, null, 'Informe matrícula/e-mail e senha.', 422);
    }

    $pdo = conexaoBanco();

    $stmt = $pdo->prepare(
        'SELECT id_usuario, nome_completo, matricula, senha_hash, perfil, ativo
         FROM usuarios
         WHERE (matricula = :ident1 OR email = :ident2)
         LIMIT 1'
    );
    $stmt->execute(['ident1' => $identificador, 'ident2' => $identificador]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        responder(false, null, 'Credenciais inválidas.', 401);
    }

    if ((int)$usuario['ativo'] !== 1) {
        responder(false, null, 'Usuário desativado. Contate o administrador.', 403);
    }

    // Unidades vinculadas ao usuário
    $stmtUnid = $pdo->prepare(
        'SELECT id_unidade FROM usuario_unidade WHERE id_usuario = :id'
    );
    $stmtUnid->execute(['id' => $usuario['id_usuario']]);
    $unidades = array_column($stmtUnid->fetchAll(), 'id_unidade');

    $_SESSION['id_usuario']  = $usuario['id_usuario'];
    $_SESSION['perfil']      = $usuario['perfil'];
    $_SESSION['id_unidades'] = $unidades;

    responder(true, [
        'id_usuario'    => $usuario['id_usuario'],
        'nome_completo' => $usuario['nome_completo'],
        'perfil'        => $usuario['perfil'],
        'unidades'      => $unidades,
    ], 'Login realizado com sucesso.');
}

function acaoCadastro(): void
{
    $dados = corpoRequisicaoJson();

    $nome         = trim((string)($dados['nome_completo'] ?? ''));
    $matricula    = trim((string)($dados['matricula'] ?? ''));
    $email        = trim((string)($dados['email'] ?? ''));
    $senha        = (string)($dados['senha'] ?? '');
    $confirmacao  = (string)($dados['confirmacao_senha'] ?? '');
    $idsUnidades  = $dados['id_unidades'] ?? []; // array, seleção múltipla

    if ($nome === '' || $matricula === '' || $email === '' || $senha === '') {
        responder(false, null, 'Preencha todos os campos obrigatórios.', 422);
    }

    if ($senha !== $confirmacao) {
        responder(false, null, 'A confirmação de senha não confere.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        responder(false, null, 'E-mail inválido.', 422);
    }

    if (!is_array($idsUnidades) || count($idsUnidades) === 0) {
        responder(false, null, 'Selecione ao menos uma unidade/hospital.', 422);
    }

    $pdo = conexaoBanco();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome_completo, matricula, email, senha_hash, perfil)
             VALUES (:nome, :matricula, :email, :senha_hash, "funcionario")'
        );
        $stmt->execute([
            'nome'       => $nome,
            'matricula'  => $matricula,
            'email'      => $email,
            'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
        ]);

        $idUsuario = (int) $pdo->lastInsertId();

        $stmtVinculo = $pdo->prepare(
            'INSERT INTO usuario_unidade (id_usuario, id_unidade) VALUES (:id_usuario, :id_unidade)'
        );
        foreach ($idsUnidades as $idUnidade) {
            $stmtVinculo->execute([
                'id_usuario' => $idUsuario,
                'id_unidade' => (int) $idUnidade,
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();

        // 23000 = violação de unicidade (matrícula/e-mail já existentes)
        if ($e->getCode() === '23000') {
            responder(false, null, 'Matrícula ou e-mail já cadastrados.', 409);
        }

        responder(false, null, 'Erro ao criar conta.', 500);
    }

    responder(true, ['id_usuario' => $idUsuario], 'Conta criada com sucesso.', 201);
}

function acaoLogout(): void
{
    $_SESSION = [];
    session_destroy();
    responder(true, null, 'Sessão encerrada.');
}