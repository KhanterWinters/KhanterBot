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
            $latency = $this->bot->getGateway()->getLatency() * 1000;
            $message->channel->sendMessage("🏓 Pong {$latency} ms");
        }
    }
}
