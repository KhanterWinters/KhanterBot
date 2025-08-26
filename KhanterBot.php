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
    $manager->load('basics'); //Ping Module and Basics tools
    $manager->load('DadJoke'); //Typical Joke of "I am"
    $manager->load('LanguageTranslate'); // Find and fit free translate API
    $manager->load('Kingdoms'); // API for Travian Kingdoms
    $manager->load('Youtube'); // Youtube Fetch
    $telegram = $manager->getLoaded()['Telegram'] ?? null;
        if ($telegram) {
            $telegram->startTelegramPoller();
        }
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
            $message->channel->sendMessage("✅ Module {$cmd[1]} loaded.");
            break;
        case '!unload':
            if (count($cmd) !== 2) return;

            if ($cmd[1] === 'all') {
                $manager->unloadAll();
                $message->channel->sendMessage('🧹 All modules unloaded.');
                } else {
                    $manager->unload($cmd[1]);
                    $message->channel->sendMessage("❌ Module {$cmd[1]} unloaded.");
                }
            break;
        case '!modules':
            $loadedModules = $manager->listLoaded();
            $allModules = [];
            $modulesFiles = scandir(__DIR__ . '/Modules');
            foreach ($modulesFiles as $file) {
                if (strpos($file, '.php') !== false) {
                    $moduleName = pathinfo($file, PATHINFO_FILENAME);
                    $allModules[] = $moduleName;
                }
            }

            $unloadedModules = array_diff($allModules, $loadedModules);

            $response = "Modules loaded:\n" . implode("\n", $loadedModules) . "\n\n";
            $response .= "Modules not loaded:\n" . implode("\n", $unloadedModules);
            $message->channel->sendMessage($response);
            break;
        default:
            // Delegar al ModuleManager
            $manager->handleMessage($message);
    }
});

$discord->run();
