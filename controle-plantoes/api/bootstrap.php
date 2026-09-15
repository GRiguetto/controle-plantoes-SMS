<?php
/**
 * bootstrap.php
 * Inicialização comum a todos os endpoints:
 * - sessão
 * - headers de resposta (JSON)
 * - conexão com banco (db.php)
 * - helpers de autenticação/autorização (RBAC)
 * - handler global de erros: garante que QUALQUER falha (erro fatal,
 *   exceção não tratada, warning) sempre responde em JSON válido,
 *   nunca em HTML/texto — o que quebraria o fetch() do front-end
 *   silenciosamente com "Resposta inválida do servidor".
 */

declare(strict_types=1);

// ============================================================
// MODO DEBUG: true = mostra mensagem real do erro no JSON de resposta
// (útil em ambiente local/WAMP). Trocar para false em produção.
// ============================================================
const APP_DEBUG = true;

session_start();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca exibir HTML de erro do PHP direto no output
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';

/**
 * Encerra a requisição com uma resposta JSON padronizada.
 */
function responder(bool $sucesso, $dados = null, string $mensagem = '', int $httpStatus = 200): void
{
    if (!headers_sent()) {
        http_response_code($httpStatus);
    }
    echo json_encode([
        'sucesso'  => $sucesso,
        'mensagem' => $mensagem,
        'dados'    => $dados,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Responde um erro interno padronizado (usado pelos handlers globais abaixo).
 */
function responderErroInterno(string $detalheTecnico): void
{
    responder(
        false,
        APP_DEBUG ? ['detalhe_tecnico' => $detalheTecnico] : null,
        APP_DEBUG ? "Erro interno: {$detalheTecnico}" : 'Erro interno no servidor.',
        500
    );
}

// ------------------------------------------------------------
// Handler global de exceções não capturadas (ex.: PDOException
// que escapou de um try/catch, TypeError de strict_types, etc.)
// ------------------------------------------------------------
set_exception_handler(function (Throwable $e): void {
    error_log('[EXCEÇÃO NÃO TRATADA] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    responderErroInterno($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
});

// ------------------------------------------------------------
// Handler global de erros do PHP (warnings, notices, etc.)
// Converte em exceção para cair no handler acima.
// ------------------------------------------------------------
set_error_handler(function (int $severidade, string $mensagem, string $arquivo, int $linha): bool {
    if (!(error_reporting() & $severidade)) {
        return false; // erro suprimido com @, ignora
    }
    throw new ErrorException($mensagem, 0, $severidade, $arquivo, $linha);
});

// ------------------------------------------------------------
// Handler de erro FATAL (ex.: Fatal Error que nem o set_error_handler
// consegue capturar). Roda ao final da execução do script.
// ------------------------------------------------------------
register_shutdown_function(function (): void {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[ERRO FATAL] ' . $erro['message'] . ' em ' . $erro['file'] . ':' . $erro['line']);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => APP_DEBUG
                ? 'Erro fatal: ' . $erro['message'] . ' (' . basename($erro['file']) . ':' . $erro['line'] . ')'
                : 'Erro interno no servidor.',
            'dados'    => null,
        ], JSON_UNESCAPED_UNICODE);
    }
});

/**
 * Garante que existe um usuário autenticado na sessão.
 * Retorna os dados do usuário logado.
 */
function exigirAutenticacao(): array
{
    if (empty($_SESSION['id_usuario'])) {
        responder(false, null, 'Usuário não autenticado.', 401);
    }

    return [
        'id_usuario' => (int) $_SESSION['id_usuario'],
        'perfil'     => (string) $_SESSION['perfil'],
        'unidades'   => $_SESSION['id_unidades'] ?? [],
    ];
}

/**
 * Garante que o usuário autenticado possui um dos perfis informados.
 * Uso: exigirPerfil(['gerente', 'administrador']);
 */
function exigirPerfil(array $perfisPermitidos): array
{
    $usuario = exigirAutenticacao();

    if (!in_array($usuario['perfil'], $perfisPermitidos, true)) {
        responder(false, null, 'Acesso negado para o perfil atual.', 403);
    }

    return $usuario;
}

/**
 * Lê e decodifica o corpo JSON da requisição.
 */
function corpoRequisicaoJson(): array
{
    $raw = file_get_contents('php://input');
    $dados = json_decode($raw, true);

    return is_array($dados) ? $dados : [];
}