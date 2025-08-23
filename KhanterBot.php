<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use Discord\Discord;
use Discord\WebSockets\Intents;
use core\ModuleManager;

$discord = new Discord([
    'token' => $_ENV['DISCORD_TOKEN'] ?? getenv('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
]);

$manager = new ModuleManager($discord);

$discord->on('ready', function () use ($manager) {
    echo "Bot ready\n";
    // Cargar módulos de arranque
    $manager->load('Basics'); //Ping Module and Basics tools
    $manager->load('DadJoke'); //Typical Joke of "I am"
    $manager->load('LanguageTranslate'); // Find and fit free translate API
    $manager->load('Kingdoms'); // API for Travian Kingdoms
    $manager->load('Telegram'); // API for Telegram Chat
    $manager->load('Quotes'); // API for some Inspiring Quotes
});

$discord->on('message', function ($message) use ($manager) {
    if ($message->author->bot) return;

    // Comandos de gestión
    $cmd = explode(' ', $message->content);
    switch ($cmd[0]) {
        case '!load':
            if (count($cmd) !== 2) return;
            $manager->load($cmd[1]);
            $message->channel->sendMessage("✅ Módulo {$cmd[1]} cargado.");
            break;
        case '!unload':
            if (count($cmd) !== 2) return;
            $manager->unload($cmd[1]);
            $message->channel->sendMessage("❌ Módulo {$cmd[1]} descargado.");
            break;
        default:
            // Delegar al ModuleManager
            $manager->handleMessage($message);
    }
});

$discord->run();
