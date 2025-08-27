<?php
/**
 * Telegram Module for KhanterBot
 *
 * License: GNU Affero General Public License v3.0
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
    private Discord $discord;
    private TelegramClient $telegram;
    private string $storage       = __DIR__ . '/../storage/bridges.json';
    private string $aliasesFile   = __DIR__ . '/../storage/aliases.json';
    private string $offsetFile    = __DIR__ . '/../storage/telegram_offset.txt';
    private bool $pollerStarted   = false;

    private array $aliases = []; // cache for aliases

    public function __construct(Discord $discord)
    {
        $this->discord = $discord;

        // Fetch Telegram token safely from environment
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
        if (!$token) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not defined in environment.');
        }
        $this->telegram = new TelegramClient($token);

        // Load aliases
        $this->aliases = $this->loadAliases();

        // Log bridge map at startup
        $map = $this->loadMap();
        echo "Bridge map loaded at startup: " . json_encode($map) . PHP_EOL;
    }

    public function init(): void
    {
        echo "[Telegram] init() called\n";
        $this->startTelegramPoller();
    }

    /* ---------- Discord → Telegram ---------- */
    private function handleDiscord(Message $msg): void
    {
        $map = $this->loadMap();
        if (!isset($map[$msg->channel_id])) return;

        $chatId = $map[$msg->channel_id];

        // Handle media attachments
        if (!empty($msg->attachments)) {
            foreach ($msg->attachments as $attachment) {
                $caption = sprintf("**%s** (Discord): %s", $msg->author->username, $msg->content);

                if ($attachment->isImage()) {
                    $this->telegram->sendPhoto([
                        'chat_id'    => $chatId,
                        'photo'      => $attachment->url,
                        'caption'    => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                } elseif (str_contains($attachment->content_type, 'video')) {
                    $this->telegram->sendVideo([
                        'chat_id'    => $chatId,
                        'video'      => $attachment->url,
                        'caption'    => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                } elseif (str_contains($attachment->content_type, 'audio') || str_contains($attachment->content_type, 'voice')) {
                    $this->telegram->sendAudio([
                        'chat_id'    => $chatId,
                        'audio'      => $attachment->url,
                        'caption'    => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                } else {
                    $this->telegram->sendDocument([
                        'chat_id'    => $chatId,
                        'document'   => $attachment->url,
                        'caption'    => $caption,
                        'parse_mode' => 'Markdown',
                    ]);
                }
            }
        } else {
            // Normal text message
            $text = sprintf("**%s** (Discord): %s", $msg->author->username, $msg->content);
            $this->telegram->sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        }
    }

    /* ---------- Alias System ---------- */
    private function loadAliases(): array
    {
        return is_file($this->aliasesFile)
            ? (json_decode(file_get_contents($this->aliasesFile), true) ?: [])
            : [];
    }

    private function saveAliases(): void
    {
        file_put_contents($this->aliasesFile, json_encode($this->aliases, JSON_PRETTY_PRINT));
    }

    private function resolveAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }

    /* ---------- Telegram → Discord ---------- */
    public function startTelegramPoller(): void
    {
        if ($this->pollerStarted) return;
        $this->pollerStarted = true;

        $last = $this->getLastOffset();

        $this->discord->getLoop()->addPeriodicTimer(5, function () use (&$last) {
            try {
                $updates = $this->telegram->getUpdates([
                    'offset'  => $last + 1,
                    'timeout' => 0,
                ]);

                foreach ($updates as $upd) {
                    $tgChat = $upd['message']['chat']['id'] ?? null;
                    if (!$tgChat) continue;

                    $map  = $this->loadMap();
                    $dcCh = array_search($tgChat, $map, true);
                    if (!$dcCh) continue;

                    $user = $upd['message']['from']['username'] ?? $upd['message']['from']['first_name'];
                    $prefix = "**$user** (Telegram): ";

                    $dcChannel = $this->discord->getChannel($dcCh);
                    if (!$dcChannel) continue;

                    // Handle Telegram → Discord media
                    if (isset($upd['message']['photo'])) {
                        $dcChannel->sendMessage($prefix . "[Photo received]");
                    } elseif (isset($upd['message']['video'])) {
                        $dcChannel->sendMessage($prefix . "[Video received]");
                    } elseif (isset($upd['message']['audio']) || isset($upd['message']['voice'])) {
                        $dcChannel->sendMessage($prefix . "[Audio received]");
                    } elseif (isset($upd['message']['text'])) {
                        $dcChannel->sendMessage($prefix . $upd['message']['text']);
                    }

                    $last = $upd['update_id'];
                    $this->setLastOffset($last);
                }
            } catch (\Throwable $e) {
                echo "Telegram poller error: " . $e->getMessage() . PHP_EOL;
            }
        });
    }

    /* ---------- Command handler ---------- */
    public function handle(Message $message): void
    {
        $text   = trim($message->content);
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
                $alias   = $pieces[1];
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
                        ? 'There are no active bridges.'
                        : "Active Bridges: " . json_encode($map, JSON_PRETTY_PRINT)
                );
                break;
            
            case 'list':
                $map = $this->listBridges();
                if (empty($map)) {
                    $message->channel->sendMessage('There are no active bridges.');
                    return;
                }
                $lines = array_map(
                    fn($dc, $tg) => "Discord **{$dc}** ↔ Telegram **{$tg}**",
                    array_keys($map),
                    $map
                );
                $message->channel->sendMessage("Active Bridges:\n" . implode("\n", $lines));
                break;

            case 'fixoffset':
                $this->setLastOffset(0);
                $message->channel->sendMessage("✅ Telegram offset has been reset to 0.");
                break;
        }
    }

    /* ---------- Bridge Management Methods ---------- */
    
    private function loadMap(): array
    {
        return is_file($this->storage)
            ? (json_decode(file_get_contents($this->storage), true) ?: [])
            : [];
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

    private function getLastOffset(): int
    {
        return is_file($this->offsetFile)
            ? (int)file_get_contents($this->offsetFile)
            : 0;
    }

    private function setLastOffset(int $offset): void
    {
        file_put_contents($this->offsetFile, $offset);
    }
}
