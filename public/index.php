<?php
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/../vendor/autoload.php';

$loop   = React\EventLoop\Loop::get();
$socket = new React\Socket\Server('0.0.0.0:' . ($_ENV['PORT'] ?? 4000), $loop);
$http   = new React\Http\HttpServer(
    function (ServerRequestInterface $request) {
        return new Response(
            200,
            ['Content-Type' => 'text/plain'],
            "Bot alive\n"
        );
    }
);

$http->listen($socket);
echo "HTTP keep-alive listening on {$socket->getAddress()}\n";

// Verificación rápida
if (empty($_ENV['TELEGRAM_BOT_TOKEN'])) {
    var_dump($_ENV);          // para ver TODO lo que llega
    echo "[ERROR] TELEGRAM_BOT_TOKEN vacío\n";
    exit(1);
}

// Arrancar también el bot
require __DIR__ . '/../KhanterBot.php';
