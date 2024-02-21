#!/usr/bin/env php
<?php

declare(strict_types=1);
require 'vendor/autoload.php';
$http = new Swoole\Http\Server("0.0.0.0", 9501);
class CustomExceptionHandler
{

    public static function handleErrors($errno, $errstr, $errfile, $errline)
    {
        $msg = "Erro: $errstr em $errfile na linha $errline";
        error_log($msg);
        echo $msg;
    }

    public static function handleExceptions($exception)
    {
        $msg = "Exceção: " . $exception->getMessage() . " em " . $exception->getFile() . " na linha " . $exception->getLine();
        error_log($msg);
        echo $msg;
    }
}
set_error_handler(['CustomExceptionHandler', 'handleErrors']);
set_exception_handler(['CustomExceptionHandler', 'handleExceptions']);
try {
    $dbHost = getenv('DB_HOST'); // Geralmente "localhost"
    $dbName = getenv('DB_NAME');
    $dbUser = getenv('DB_USER');
    $dbPassword = getenv('DB_PASSWORD');
    $pdo = new PDO("pgsql:host=$dbHost;dbname=$dbName", $dbUser, $dbPassword);
    // Configurar o PDO para lançar exceções em caso de erros
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
function randomDate($sStartDate, $sEndDate, $sFormat = 'Y-m-d H:i:s')
{
    $fMin = strtotime($sStartDate);
    $fMax = strtotime($sEndDate);
    $fVal = mt_rand($fMin, $fMax);
    return date($sFormat, $fVal);
}

$getCliente = function ($cliente_id) use ($pdo) {
    $cliente = "SELECT * FROM clientes c WHERE c.id = ?";
    $statement = $pdo->prepare($cliente);
    $statement->execute([$cliente_id]);
    $cliente = $statement->fetch(PDO::FETCH_OBJ);
    return $cliente;
};

$getSaldo = function ($cliente) use ($pdo, $getCliente) {
    $total_atual =  $cliente->limite;
    $query_c = "SELECT COALESCE(SUM(t.valor), 0) AS saldo FROM transacoes t INNER JOIN clientes c ON c.id = t.cliente_id WHERE t.tipo = 'c' AND t.cliente_id = ? GROUP BY c.id";
    $statement = $pdo->prepare($query_c);
    $statement->execute([$cliente->id]);
    $credito = $statement->fetch(PDO::FETCH_OBJ);
    if ($credito) {
        $total_atual += $credito->saldo;
    }
    $query_d = "SELECT COALESCE(SUM(t.valor), 0) AS saldo FROM transacoes t INNER JOIN clientes c ON c.id = t.cliente_id WHERE t.tipo = 'd' AND t.cliente_id = ? GROUP BY c.id";
    $statement = $pdo->prepare($query_d);
    $statement->execute([$cliente->id]);
    $debito = $statement->fetch(PDO::FETCH_OBJ);
    if ($debito) {
        $total_atual -= $debito->saldo;
    }
    return $total_atual;
};
$http->on(
    "request",
    function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($pdo, $getCliente, $getSaldo) {
        $_SERVER['REQUEST_METHOD'] = $request->server['request_method'];
        $_SERVER['REQUEST_URI'] = $request->server['request_uri'];
        $_SERVER['REMOTE_ADDR'] = $request->server['remote_addr'];
        $_SERVER['REMOTE_PORT'] = $request->server['remote_port'];
        $_SERVER['REQUEST_TIME'] = $request->server['request_time'];
        $_SERVER['PATH_INFO'] = $request->server['path_info'];
        $_SERVER['QUERY_STRING'] = $request->get;
        $_GET = (array) $request->get;
        $_POST = (array) $request->post;
        $_COOKIES = $request->cookie;
        $_FILES = $request->files;
        $_REQUEST = array_merge($_GET, $_POST);
        $router = new Albreis\Router($request->getMethod(), $request->server['request_uri']);
        $router->get(
            '^/clientes$',
            function () use ($request, $response, $pdo) {
                $pdo->beginTransaction();
                try {
                    $query = "SELECT * FROM clientes";
                    $statement = $pdo->prepare($query);
                    $statement->execute();
                    $clientes = $statement->fetchAll(PDO::FETCH_OBJ);
                    $output = json_encode($clientes, JSON_PRETTY_PRINT);
                    $pdo->commit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    return $response->end($e->getMessage());
                }
                $response->status(200);
                $response->header('Content-Type', 'application/json');
                $response->end($output);
            },
            true
        );
        $router->post(
            '^/clientes/([0-9]+)/transacoes$',
            function ($cliente_id) use ($request, $response, $pdo, $getCliente, $getSaldo) {
                $cliente = $getCliente($cliente_id);
                if (!$cliente) {
                    $response->status(404);
                    return $response->end('cliente não  encontrado');
                }
                $total_atual =  $cliente->saldo_atual;
                $data = json_decode($request->rawContent());
                if (!in_array($data->tipo, ['c', 'd'])) {
                    $response->status(503);
                    return $response->end('O tipo de transação precisa ser c ou d');
                }
                if ($data->tipo === 'd') {
                    $total_apos = $total_atual - ((int) $data->valor);
                    if ($total_apos < (0 - $cliente->limite)) {
                        $response->status(422);
                        return $response->end('Você não possui limite para efetuar essa transção');
                    }
                }
                $pdo->beginTransaction();
                try {
                    $query = "INSERT INTO transacoes (cliente_id, valor, tipo, descricao, realizada_em) VALUES (?, ?, ?, ?, ?)";
                    $statement = $pdo->prepare($query);
                    $statement->execute(
                        [
                            $cliente_id,
                            $data->valor,
                            $data->tipo,
                            $data->descricao,
                            (new DateTime)->format('Y-m-d\TH:i:s.u\Z')
                        ]
                    );
                    $pdo->commit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    return $response->end($e->getMessage());
                }
                $total_atual =  $getSaldo($cliente);
                $pdo->beginTransaction();
                try {
                    $query = "UPDATE clientes SET saldo_atual = ? WHERE id = ?";
                    $statement = $pdo->prepare($query);
                    $statement->execute(
                        [
                            $total_atual,
                            $cliente_id,
                        ]
                    );
                    $pdo->commit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    return $response->end($e->getMessage());
                }
                $output = json_encode(
                    [
                        'limite' => $cliente->limite,
                        'saldo' => $total_atual,
                    ]
                );
                $response->header('Content-Type', 'application/json');
                $response->end($output);
            },
            true
        );
        $router->get(
            '^/clientes/([0-9]+)/transacoes$',
            function ($cliente_id) use ($request, $response, $pdo, $getCliente) {
                $cliente = $getCliente($cliente_id);
                if (!$cliente) {
                    $response->status(404);
                    return $response->end('cliente não  encontrado');
                }
                $pdo->beginTransaction();
                try {
                    $query = "SELECT * FROM transacoes t WHERE t.cliente_id = ? ORDER BY realizada_em DESC";
                    $statement = $pdo->prepare($query);
                    $statement->execute([$cliente_id]);
                    $transacoes = $statement->fetchAll(PDO::FETCH_OBJ);
                    $output = json_encode($transacoes, JSON_PRETTY_PRINT);
                    $pdo->commit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    return $response->end($e->getMessage());
                }
                $response->header('Content-Type', 'application/json');
                $response->end($output);
            },
            true
        );
        $router->get(
            '^/clientes/([0-9]+)/extrato$',
            function ($cliente_id) use ($request, $response, $pdo, $getCliente, $getSaldo) {
                $cliente = $getCliente($cliente_id);
                if (!$cliente) {
                    $response->status(404);
                    return $response->end('cliente não  encontrado');
                }
                $pdo->beginTransaction();
                try {
                    $query = "SELECT * FROM transacoes t WHERE t.cliente_id = ? ORDER BY realizada_em DESC LIMIT 10";
                    $statement = $pdo->prepare($query);
                    $statement->execute([$cliente_id]);
                    $transacoes = $statement->fetchAll(PDO::FETCH_OBJ);
                    $output = json_encode(
                        [
                            'saldo' => [
                                'total' => $cliente->saldo_atual,
                                'data_extrato' => (new DateTime)->format('Y-m-d\TH:i:s.u\Z'),
                                'limite' => $cliente->limite,
                            ],
                            'ultimas_transacoes' => $transacoes
                        ],
                        JSON_PRETTY_PRINT
                    );
                    $pdo->commit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    return $response->end($e->getMessage());
                }
                $response->header('Content-Type', 'application/json');
                $response->end($output);
            },
            true
        );
        $router->get(
            '.*',
            function () use ($request, $response) {
                $response->status(404);
                $response->end('');
            },
            true
        );
    }
);
$http->start();
