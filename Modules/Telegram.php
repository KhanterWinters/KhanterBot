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
 * Check the Internal NOTICE.md of this Git (https://github.com/KhanterWinters/KhanterBot/blob/main/NOTICE.md)
 * For more references.
 *
 * Responsibilities:
 * - Synchronizes messages between Discord channels and Telegram groups.
 * - Telegram → Discord supports multiple channels via aliases.
 * - Discord → Telegram forwards messages from a single Discord channel to the main Telegram group.
 * - Poller runs automatically upon module initialization.
 */

namespace Modules;

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Telegram\Bot\Api as TelegramClient;

class Telegram
{
    // Discord client
    private Discord $discord;

    // Telegram bot client
    private TelegramClient $telegram;

    // JSON storage files
    private string $aliasesFile = __DIR__ . '/../storage/aliases.json';
    private string $bridgeFile  = __DIR__ . '/../storage/bridge.json';
    private string $offsetFile  = __DIR__ . '/../storage/telegram_offset.txt';

    // Poller status
    private bool $pollerStarted = false;

    // Cached data
    private array $aliases = [];
    private int $discordToTelegramChannel = 0; // Discord channel ID to forward to Telegram

    /**
     * Constructor
     */
    public function __construct(Discord $discord, int $discordChannelToTelegram)
    {
        $this->discord = $discord;
        $this->discordToTelegramChannel = $discordChannelToTelegram;

        // Fetch Telegram token from environment
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
        if (!$token) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not defined.');
        }
        $this->telegram = new TelegramClient($token);

        // Load aliases
        $this->aliases = $this->loadAliases();

        echo "Telegram module initialized. Aliases loaded: " . json_encode($this->aliases) . PHP_EOL;
    }

    /**
     * Initialize the module
     */
    public function init(): void
    {
        echo "[Telegram] init() called\n";
        $this->startTelegramPoller();
    }

    /* ---------------- Telegram → Discord ---------------- */
    public function startTelegramPoller(): void
    {
        if ($this->pollerStarted) return;
        $this->pollerStarted = true;

        $last = $this->getLastOffset();

        $this->discord->getLoop()->addPeriodicTimer(5, function () use (&$last) {
            try {
                $updates = $this->telegram->getUpdates(['offset' => $last + 1, 'timeout' => 0]);
                foreach ($updates as $upd) {
                    $msg = $upd['message'] ?? null;
                    if (!$msg) continue;

                    $text = $msg['text'] ?? '';
                    if (!$text) continue;

                    // Detect alias (format: #Alias - message)
                    if (preg_match('/^#([^\s]+)\s*-\s*(.+)$/', $text, $matches)) {
                        $alias = $matches[1];
                        $messageContent = $matches[2];

                        $map = $this->loadAliases();
                        if (!isset($map[$alias])) continue;

                        $discordChannelId = $map[$alias];
                        $dcChannel = $this->discord->getChannel($discordChannelId);
                        if (!$dcChannel) continue;

                        $user = $msg['from']['username'] ?? $msg['from']['first_name'];
                        $dcChannel->sendMessage("**$user** (Telegram): $messageContent");
                    }

                    $last = $upd['update_id'];
                    $this->setLastOffset($last);
                }
            } catch (\Throwable $e) {
                echo "Telegram poller error: " . $e->getMessage() . PHP_EOL;
            }
        });
    }

    /* ---------------- Discord → Telegram ---------------- */
    private function handleDiscord(Message $msg): void
    {
        // Only forward messages from the designated Discord channel
        if ($msg->channel_id != $this->discordToTelegramChannel) return;

        $text = "**{$msg->author->username}** (Discord): {$msg->content}";
        $this->telegram->sendMessage([
            'chat_id' => $this->getTelegramGroupId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /* ---------------- Command Handler ---------------- */
    public function handle(Message $message): void
    {
        $text = trim($message->content);
        $pieces = explode(' ', $text);

        switch ($pieces[0]) {
            case '!set':
                if (count($pieces) < 3) {
                    $message->channel->sendMessage("Usage: `!set <alias> <discordChannelId>`");
                    return;
                }
                $alias = $pieces[1];
                $channelId = $pieces[2];

                $this->aliases[$alias] = $channelId;
                $this->saveAliases();

                $message->channel->sendMessage("✅ Alias '$alias' saved for Discord channel $channelId");
                break;

            case '!bridge':
                $this->handleBridgeCommand($message, $pieces);
                break;

            default:
                $this->handleDiscord($message);
        }
    }

    private function handleBridgeCommand(Message $message, array $pieces): void
    {
        if (count($pieces) < 2) {
            $message->channel->sendMessage('Sub-commands: `status`, `list`.');
            return;
        }

        switch ($pieces[1]) {
            case 'status':
                $message->channel->sendMessage(
                    empty($this->aliases)
                        ? 'No aliases configured.'
                        : "Current aliases:\n" . json_encode($this->aliases, JSON_PRETTY_PRINT)
                );
                break;

            case 'list':
                $lines = [];
                foreach ($this->aliases as $alias => $channel) {
                    $lines[] = "#$alias → Discord Channel: $channel";
                }
                $message->channel->sendMessage(
                    empty($lines) ? 'No aliases configured.' : implode("\n", $lines)
                );
                break;
        }
    }

    /* ---------------- Alias Management ---------------- */
    private function loadAliases(): array
    {
        return is_file($this->aliasesFile) ? json_decode(file_get_contents($this->aliasesFile), true) ?? [] : [];
    }

    private function saveAliases(): void
    {
        file_put_contents($this->aliasesFile, json_encode($this->aliases, JSON_PRETTY_PRINT));
    }

    /* ---------------- Telegram Offset ---------------- */
    private function getLastOffset(): int
    {
        return is_file($this->offsetFile) ? (int)file_get_contents($this->offsetFile) : 0;
    }

    private function setLastOffset(int $offset): void
    {
        file_put_contents($this->offsetFile, $offset);
    }

    /* ---------------- Telegram Group ID ---------------- */
    private function getTelegramGroupId(): int
    {
        // Hardcoded or configurable Telegram group ID
        return (int)getenv('TELEGRAM_GROUP_ID');
    }
}
