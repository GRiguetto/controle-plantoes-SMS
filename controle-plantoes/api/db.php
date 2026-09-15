<?php
/**
 * db.php
 * Conexão única (singleton) com o banco de dados via PDO.
 * Ajuste as credenciais conforme o ambiente (local/produção).
 *
 * Observação: qualquer PDOException lançada aqui é capturada pelo
 * handler global definido em bootstrap.php (set_exception_handler),
 * que sempre responde em JSON válido — por isso não há try/catch local.
 */

declare(strict_types=1);

function conexaoBanco(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = 'localhost';
    $porta   = '3306';
    $banco   = 'controle_plantoes';
    $usuario = 'root';
    $senha   = '';

    $dsn = "mysql:host={$host};port={$porta};dbname={$banco};charset=utf8mb4";

    $pdo = new PDO($dsn, $usuario, $senha, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}