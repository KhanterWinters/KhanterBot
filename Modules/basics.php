<?php
/**
 * Telegram Module for KhanterBot
 *
 * License: GNU Affero General Public License v3.0
 * 
 * DISCLAIMER:
 * This software is provided "as-is" and may be freely used, modified, or studied.
 * Commercial use under the author's name or branding is NOT permitted until trademark
 * and notice are officially registered. Redistribution must retain this notice and the AGPL 3.0 license.
 *
 * Responsibilities:
 * - Synchronizes messages between Discord channels and Telegram groups.
 * - Discord → Telegram works for all messages in mapped channels.
 * - Telegram → Discord works for all messages in mapped Telegram chats.
 * - Poller runs automatically upon module initialization.
 */

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
