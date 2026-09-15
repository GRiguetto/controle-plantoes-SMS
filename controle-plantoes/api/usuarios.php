<?php
/**
 * usuarios.php
 * Painel administrativo: busca, alteração de senha e desativação (soft delete).
 *
 *   GET  /api/usuarios.php?acao=buscar&termo=...
 *   POST /api/usuarios.php?acao=alterar_senha
 *   POST /api/usuarios.php?acao=desativar
 *   POST /api/usuarios.php?acao=alterar_meus_dados   (qualquer perfil, "Meu Perfil")
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$acao = $_GET['acao'] ?? '';

switch ($acao) {
    case 'buscar':
        acaoBuscar();
        break;

    case 'criar':
        acaoCriar();
        break;

    case 'alterar_senha':
        acaoAlterarSenha();
        break;

    case 'desativar':
        acaoDesativar();
        break;

    case 'alterar_meus_dados':
        acaoAlterarMeusDados();
        break;

    case 'trocar_minha_senha':
        acaoTrocarMinhaSenha();
        break;

    case 'listar_funcionarios':
        acaoListarFuncionarios();
        break;

    default:
        responder(false, null, 'Ação inválida.', 400);
}

/**
 * Lista funcionários para popular filtros de histórico.
 * - gerente: apenas funcionários vinculados às unidades do próprio gerente
 * - administrador: todos os funcionários ativos (ou de uma unidade específica, se informado)
 */
function acaoListarFuncionarios(): void
{
    $usuario = exigirPerfil(['gerente', 'administrador']);
    $pdo = conexaoBanco();

    if ($usuario['perfil'] === 'gerente') {
        $unidades = $usuario['unidades'] ?: [0];
        $placeholders = implode(',', array_fill(0, count($unidades), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT u.id_usuario, u.nome_completo, u.matricula
             FROM usuarios u
             JOIN usuario_unidade uu ON uu.id_usuario = u.id_usuario
             WHERE u.ativo = 1 AND uu.id_unidade IN ($placeholders)
             ORDER BY u.nome_completo ASC"
        );
        $stmt->execute(array_values($unidades));
    } else {
        $idUnidade = (int)($_GET['id_unidade'] ?? 0);

        if ($idUnidade > 0) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT u.id_usuario, u.nome_completo, u.matricula
                 FROM usuarios u
                 JOIN usuario_unidade uu ON uu.id_usuario = u.id_usuario
                 WHERE u.ativo = 1 AND uu.id_unidade = :id_unidade
                 ORDER BY u.nome_completo ASC"
            );
            $stmt->execute(['id_unidade' => $idUnidade]);
        } else {
            $stmt = $pdo->query(
                'SELECT id_usuario, nome_completo, matricula FROM usuarios
                 WHERE ativo = 1 ORDER BY nome_completo ASC'
            );
        }
    }

    responder(true, $stmt->fetchAll());
}

/**
 * Usuário autenticado troca a própria senha, exigindo confirmação
 * da senha atual (requisito 4.4 da especificação).
 */
function acaoTrocarMinhaSenha(): void
{
    $usuario = exigirAutenticacao();
    $dados   = corpoRequisicaoJson();

    $senhaAtual = (string)($dados['senha_atual'] ?? '');
    $novaSenha  = (string)($dados['nova_senha'] ?? '');

    if ($senhaAtual === '' || strlen($novaSenha) < 6) {
        responder(false, null, 'Informe a senha atual e uma nova senha com no mínimo 6 caracteres.', 422);
    }

    $pdo = conexaoBanco();

    $stmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id_usuario = :id');
    $stmt->execute(['id' => $usuario['id_usuario']]);
    $registro = $stmt->fetch();

    if (!$registro || !password_verify($senhaAtual, $registro['senha_hash'])) {
        responder(false, null, 'Senha atual incorreta.', 401);
    }

    $ok = $pdo->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id_usuario = :id')
        ->execute(['hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'id' => $usuario['id_usuario']]);

    responder((bool)$ok, null, $ok ? 'Senha alterada com sucesso.' : 'Erro ao alterar senha.');
}

/** Administrador busca usuários ativos por nome, matrícula, unidade ou perfil. */
function acaoBuscar(): void
{
    exigirPerfil(['administrador']);

    $termo = trim((string)($_GET['termo'] ?? ''));
    $pdo = conexaoBanco();

    $stmt = $pdo->prepare(
        "SELECT u.id_usuario, u.nome_completo, u.matricula, u.email, u.perfil, u.ativo
         FROM usuarios u
         WHERE u.ativo = 1
           AND (u.nome_completo LIKE :termo1 OR u.matricula LIKE :termo2 OR u.perfil LIKE :termo3)
         ORDER BY u.nome_completo ASC
         LIMIT 50"
    );
    $termoBusca = "%{$termo}%";
    $stmt->execute(['termo1' => $termoBusca, 'termo2' => $termoBusca, 'termo3' => $termoBusca]);

    responder(true, $stmt->fetchAll());
}

/**
 * Administrador cria um usuário diretamente (sem passar pelo cadastro público),
 * com uma senha provisória gerada pelo sistema.
 *
 * Observação: o envio da senha provisória por e-mail não está implementado —
 * por ora ela retorna na própria resposta para o administrador repassar
 * manualmente ao usuário.
 */
function acaoCriar(): void
{
    exigirPerfil(['administrador']);
    $dados = corpoRequisicaoJson();

    $nome        = trim((string)($dados['nome_completo'] ?? ''));
    $matricula   = trim((string)($dados['matricula'] ?? ''));
    $perfil      = (string)($dados['perfil'] ?? '');
    $email       = trim((string)($dados['email'] ?? ''));
    $idsUnidades = $dados['id_unidades'] ?? [];

    if ($nome === '' || $matricula === '' || $email === '') {
        responder(false, null, 'Preencha nome, matrícula e e-mail.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        responder(false, null, 'E-mail inválido.', 422);
    }

    if (!in_array($perfil, ['funcionario', 'gerente', 'administrador'], true)) {
        responder(false, null, 'Perfil inválido.', 422);
    }

    if (!is_array($idsUnidades) || count($idsUnidades) === 0) {
        responder(false, null, 'Selecione ao menos uma unidade.', 422);
    }

    // Regra de negócio (ver comentário em sql/schema.sql): gerente fica
    // restrito a uma única unidade.
    if ($perfil === 'gerente' && count($idsUnidades) > 1) {
        responder(false, null, 'Um gerente pode estar vinculado a apenas uma unidade.', 422);
    }

    $senhaProvisoria = gerarSenhaProvisoria();

    $pdo = conexaoBanco();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome_completo, matricula, email, senha_hash, perfil)
             VALUES (:nome, :matricula, :email, :senha_hash, :perfil)'
        );
        $stmt->execute([
            'nome'       => $nome,
            'matricula'  => $matricula,
            'email'      => $email,
            'senha_hash' => password_hash($senhaProvisoria, PASSWORD_DEFAULT),
            'perfil'     => $perfil,
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

        if ($e->getCode() === '23000') {
            responder(false, null, 'Matrícula ou e-mail já cadastrados.', 409);
        }

        throw $e; // deixa o handler global formatar a resposta JSON
    }

    responder(true, [
        'id_usuario'       => $idUsuario,
        'senha_provisoria' => $senhaProvisoria,
    ], 'Usuário criado com sucesso.', 201);
}

/** Gera uma senha provisória legível (ex: "TB47-KXQ2"), fácil de repassar manualmente. */
function gerarSenhaProvisoria(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem O/0/I/1 p/ evitar confusão
    $bloco = function () use ($alfabeto) {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $s;
    };
    return $bloco() . '-' . $bloco();
}

/** Administrador altera a senha de qualquer usuário. */
function acaoAlterarSenha(): void
{
    exigirPerfil(['administrador']);
    $dados = corpoRequisicaoJson();

    $idUsuario  = (int)($dados['id_usuario'] ?? 0);
    $novaSenha  = (string)($dados['nova_senha'] ?? '');

    if ($idUsuario === 0 || strlen($novaSenha) < 6) {
        responder(false, null, 'Informe um usuário válido e uma senha com no mínimo 6 caracteres.', 422);
    }

    $pdo = conexaoBanco();
    $ok = $pdo->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id_usuario = :id')
        ->execute(['hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'id' => $idUsuario]);

    responder((bool)$ok, null, $ok ? 'Senha alterada com sucesso.' : 'Erro ao alterar senha.');
}

/** Administrador desativa um funcionário (soft delete via flag `ativo`). */
function acaoDesativar(): void
{
    exigirPerfil(['administrador']);
    $dados = corpoRequisicaoJson();

    $idUsuario = (int)($dados['id_usuario'] ?? 0);

    if ($idUsuario === 0) {
        responder(false, null, 'Usuário inválido.', 422);
    }

    $pdo = conexaoBanco();
    $ok = $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id_usuario = :id')
        ->execute(['id' => $idUsuario]);

    responder((bool)$ok, null, $ok ? 'Usuário desativado.' : 'Erro ao desativar usuário.');
}

/** Qualquer perfil autenticado edita seus próprios dados (exceto matrícula). */
function acaoAlterarMeusDados(): void
{
    $usuario = exigirAutenticacao();
    $dados   = corpoRequisicaoJson();

    $nome  = trim((string)($dados['nome_completo'] ?? ''));
    $email = trim((string)($dados['email'] ?? ''));

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        responder(false, null, 'Dados inválidos.', 422);
    }

    $pdo = conexaoBanco();
    $ok = $pdo->prepare('UPDATE usuarios SET nome_completo = :nome, email = :email WHERE id_usuario = :id')
        ->execute(['nome' => $nome, 'email' => $email, 'id' => $usuario['id_usuario']]);

    responder((bool)$ok, null, $ok ? 'Dados atualizados.' : 'Erro ao atualizar dados.');
}