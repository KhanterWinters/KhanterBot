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
            $ping = round($this->bot->getLoop()->getTimerCount() * 1000); // aproximado
            $message->channel->sendMessage("🏓 Pong {$ping} ms");
        }
    }
}
