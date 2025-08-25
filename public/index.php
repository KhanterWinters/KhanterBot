<?php
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/../vendor/autoload.php';

// ---------- lock ----------
$lock = '/tmp/bot.lock';
if (file_exists($lock)) {
    echo "Bot already running\n";
    exit(1);
}
file_put_contents($lock, getmypid());
register_shutdown_function(fn() => @unlink($lock));

// ---------- HTTP ----------
$loop   = React\EventLoop\Loop::get();
$socket = new React\Socket\Server('0.0.0.0:' . ($_ENV['PORT'] ?? 4000), $loop);
$http   = new React\Http\HttpServer(function (ServerRequestInterface $req) {
    // /health no responde 200 hasta que el bot esté listo
    if ($req->getUri()->getPath() === '/health') {
        return new Response(
            file_exists('/tmp/bot-ready') ? 200 : 503,
            ['Content-Type' => 'text/plain'],
            file_exists('/tmp/bot-ready') ? 'OK' : 'Starting'
        );
    }

    if ($req->getUri()->getPath() === '/debug') {
        return new Response(
            200,
            ['Content-Type' => 'text/plain'],
            var_export($_ENV, true)
        );
    }

    return new Response(200, ['Content-Type' => 'text/plain'], "Bot alive\n");
});

$http->listen($socket);
echo "HTTP keep-alive listening on {$socket->getAddress()}\n";

// ----------  marcar “listo” cuando Discord esté ready ----------
$discord = require __DIR__ . '/../KhanterBot.php';
$discord->on('ready', function () {
    file_put_contents('/tmp/bot-ready', '1');
});
