<?php
namespace Modules;

use Discord\Discord;
use Discord\Parts\Channel\Message;

class basics
{
    private Discord $bot;

    public function __construct(Discord $bot)
    {
        $this->bot = $bot;
    }

    public function handle(Message $message): void
    {
        if ($message->content === '!ping') {
        // Opción 1: latencia real (DiscordPHP ≥ 7.0)
        $latency = round($this->bot->ping);
        $message->channel->sendMessage("🏓 Pong {$latency} ms");

        // Opción 2: texto plano (si la anterior falla)
        // $message->channel->sendMessage('🏓 Pong!');
        }
    }
}
