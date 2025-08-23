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
        $text = $message->content;

        switch ($text) {
            case '!ping':
                $latency = round($this->bot->ping);
                $message->channel->sendMessage("🏓 Pong {$latency} ms");
                break;

            case '!uptime':
                $uptime = time() - $_SERVER['REQUEST_TIME_FLOAT'];
                $message->channel->sendMessage(
                    "My up time is: " . gmdate("H:i:s", (int)$uptime)
                );
                break;
        }
    }
}
