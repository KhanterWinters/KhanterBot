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
 * - Discord → Telegram works for all messages in mapped channels.
 * - Telegram → Discord works for all messages in mapped Telegram chats.
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
    private string $storage     = __DIR__ . '/../storage/bridges.json';
    private string $aliasesFile = __DIR__ . '/../storage/aliases.json';
    private string $offsetFile  = __DIR__ . '/../storage/telegram_offset.txt';

    // Poller status
    private bool $pollerStarted = false;

    // Cached aliases
    private array $aliases = [];

    /**
     * Constructor
     */
    public function __construct(Discord $discord)
    {
        $this->discord = $discord;

        // Fetch Telegram token from environment
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
        if (!$token) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not defined.');
        }
        $this->telegram = new TelegramClient($token);

        // Load aliases from JSON file
        $this->aliases = $this->loadAliases();

        // Log bridges at startup
        echo "Bridge map loaded at startup: " . json_encode($this->loadMap()) . PHP_EOL;
    }

    /**
     * Initialize the module
     */
    public function init(): void
    {
        echo "[Telegram] init() called\n";
        $this->startTelegramPoller();
    }

    /* ---------------- Discord → Telegram ---------------- */
    private function handleDiscord(Message $msg): void
    {
        // Reload bridge map each time (reflect recent changes)
        $map = $this->loadMap();

        // Resolve alias if any
        $channelId = $this->resolveAlias($msg->channel_id);

        if (!isset($map[$channelId])) return;

        $chatId = $map[$channelId];
        $prefix = "**{$msg->author->username}** (Discord): ";

        if (!empty($msg->attachments)) {
            // Send media attachments
            foreach ($msg->attachments as $att) {
                $caption = $prefix . $msg->content;
                if ($att->isImage()) {
                    $this->telegram->sendPhoto([
                        'chat_id' => $chatId,
                        'photo' => $att->url,
                        'caption' => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                } elseif (str_contains($att->content_type,'video')) {
                    $this->telegram->sendVideo([
                        'chat_id' => $chatId,
                        'video' => $att->url,
                        'caption' => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                } elseif (str_contains($att->content_type,'audio') || str_contains($att->content_type,'voice')) {
                    $this->telegram->sendAudio([
                        'chat_id' => $chatId,
                        'audio' => $att->url,
                        'caption' => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                } else {
                    $this->telegram->sendDocument([
                        'chat_id' => $chatId,
                        'document' => $att->url,
                        'caption' => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                }
            }
        } else {
            // Send plain text message
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $prefix . $msg->content,
                'parse_mode' => 'Markdown',
            ]);
        }
    }

    /* ---------------- Alias System ---------------- */
    private function loadAliases(): array
    {
        return is_file($this->aliasesFile)
            ? json_decode(file_get_contents($this->aliasesFile), true) ?: []
            : [];
    }

    private function saveAliases(): void
    {
        file_put_contents($this->aliasesFile, json_encode($this->aliases, JSON_PRETTY_PRINT));
    }

    /**
     * Resolve a Discord ID using alias
     */
    private function resolveAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
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

                    $tgChat = $msg['chat']['id'] ?? null;
                    if (!$tgChat) continue;

                    $map = $this->loadMap();
                    $dcCh = array_search($tgChat, $map, true);
                    if (!$dcCh) continue;

                    $user = $msg['from']['username'] ?? $msg['from']['first_name'];
                    $prefix = "**$user** (Telegram): ";

                    $dcChannel = $this->discord->getChannel($dcCh);
                    if (!$dcChannel) continue;

                    if (isset($msg['photo'])) $dcChannel->sendMessage($prefix."[Photo received]");
                    elseif (isset($msg['video'])) $dcChannel->sendMessage($prefix."[Video received]");
                    elseif (isset($msg['audio']) || isset($msg['voice'])) $dcChannel->sendMessage($prefix."[Audio received]");
                    elseif (isset($msg['text'])) $dcChannel->sendMessage($prefix.$msg['text']);

                    $last = $upd['update_id'];
                    $this->setLastOffset($last);
                }
            } catch (\Throwable $e) {
                echo "Telegram poller error: " . $e->getMessage() . PHP_EOL;
            }
        });
    }

    /* ---------------- Command Handler ---------------- */
    public function handle(Message $message): void
    {
        $text = trim($message->content);
        $pieces = explode(' ', $text);

        switch ($pieces[0]) {
            case '!bridge':
                $this->handleBridgeCommand($message, $pieces);
                break;

            case '!set':
                if (count($pieces) < 3) {
                    $message->channel->sendMessage("Usage: `!set <alias> <channelId>`");
                    return;
                }
                $alias = $pieces[1];
                $channel = $pieces[2];

                // Save alias persistently
                $this->aliases[$alias] = $channel;
                $this->saveAliases();

                $message->channel->sendMessage("✅ Alias '$alias' set for channel $channel");
                break;

            default:
                $this->handleDiscord($message);
        }
    }

    private function handleBridgeCommand(Message $message, array $pieces): void
    {
        if (count($pieces) < 2) {
            $message->channel->sendMessage('Sub-commands: `add`, `remove`, `status`, `list`, `fixoffset`.');
            return;
        }

        switch ($pieces[1]) {
            case 'add':
                if (count($pieces) !== 4) {
                    $message->channel->sendMessage('Use: `!bridge add <discordId|alias> <telegramId>`');
                    return;
                }
                [$cmd, $sub, $dcId, $tgId] = $pieces;
                $dcId = $this->resolveAlias($dcId);
                $this->addBridge($dcId, (int)$tgId);
                $message->channel->sendMessage("✅ Bridge Added: Discord {$dcId} ↔ Telegram {$tgId}");
                break;

            case 'remove':
                if (count($pieces) !== 3) {
                    $message->channel->sendMessage('Use: `!bridge remove <discordId|alias>`');
                    return;
                }
                [$cmd, $sub, $dcId] = $pieces;
                $dcId = $this->resolveAlias($dcId);
                $this->removeBridge($dcId);
                $message->channel->sendMessage("❌ Bridge removed: Discord {$dcId}");
                break;

            case 'status':
                $map = $this->listBridges();
                $message->channel->sendMessage(
                    empty($map)
                        ? 'No active bridges.'
                        : "Active Bridges:\n" . json_encode($map, JSON_PRETTY_PRINT)
                );
                break;

            case 'list':
                $map = $this->listBridges();
                if (empty($map)) {
                    $message->channel->sendMessage('No active bridges.');
                    return;
                }
                $lines = array_map(fn($dc,$tg) => "Discord **{$dc}** ↔ Telegram **{$tg}**", array_keys($map), $map);
                $message->channel->sendMessage("Active Bridges:\n" . implode("\n", $lines));
                break;

            case 'fixoffset':
                $this->setLastOffset(0);
                $message->channel->sendMessage("✅ Telegram offset reset to 0.");
                break;
        }
    }

    /* ---------------- Bridge Management ---------------- */
    private function loadMap(): array
    {
        return is_file($this->storage) ? json_decode(file_get_contents($this->storage), true) ?? [] : [];
    }

    private function saveMap(array $map): void
    {
        file_put_contents($this->storage, json_encode($map, JSON_PRETTY_PRINT));
    }

    private function addBridge(string $discordId, int $telegramId): void
    {
        $map = $this->loadMap();
        $map[$discordId] = $telegramId;
        $this->saveMap($map);
    }

    private function removeBridge(string $discordId): void
    {
        $map = $this->loadMap();
        unset($map[$discordId]);
        $this->saveMap($map);
    }

    private function listBridges(): array
    {
        return $this->loadMap();
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
}
