<?php
namespace Modules;

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Telegram\Bot\Api as TelegramClient;

class Telegram
{
    private Discord        $discord;
    private TelegramClient $telegram;
    private string         $storage      = __DIR__ . '/../storage/bridges.json';
    private string         $offsetFile   = __DIR__ . '/../storage/telegram_offset.txt';
    private bool           $pollerStarted = false;

    public function __construct(Discord $discord)
    {
        $this->discord = $discord;

        $token = getenv('TELEGRAM_BOT_TOKEN') ?: ($_SERVER['TELEGRAM_BOT_TOKEN'] ?? null);
        if (!$token) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN no está definido en Render.');
        }
        $this->telegram = new \Telegram\Bot\Api($token);

        // Log al arranque
        $map = $this->loadMap();
        echo "Puente cargado al arranque: " . json_encode($map) . PHP_EOL;
    }

    /* ---------- Discord → Telegram ---------- */
    private function handleDiscord(Message $msg): void
    {
        $map = $this->loadMap();
        if (!isset($map[$msg->channel_id])) return;

        $text = sprintf(
            "**%s** (Discord): %s",
            $msg->author->username,
            $msg->content
        );
        $this->telegram->sendMessage([
            'chat_id'    => $map[$msg->channel_id],
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /* ---------- Telegram → Discord (con offset persistente) ---------- */
    public function startTelegramPoller(): void
    {
        if ($this->pollerStarted) return;
        $this->pollerStarted = true;

        $last = $this->getLastOffset();
        $this->discord->getLoop()->addPeriodicTimer(5, function () use (&$last) {
            try {
                $updates = $this->telegram->getUpdates([
                    'offset'  => $last + 1,
                    'timeout' => 30,
                ]);
                foreach ($updates as $upd) {
                    if (!isset($upd['message']['text'])) continue;

                    $tgChat = $upd['message']['chat']['id'];
                    $map    = $this->loadMap();
                    $dcCh   = array_search($tgChat, $map, true);

                    if (!$dcCh) continue;

                    $user = $upd['message']['from']['username'] ?? $upd['message']['from']['first_name'];
                    $text = "**$user** (Telegram): {$upd['message']['text']}";

                    $dcChannel = $this->discord->getChannel($dcCh);
                    $dcChannel?->sendMessage($text);

                    $last = $upd['update_id'];
                    $this->setLastOffset($last);
                }
            } catch (\Throwable $e) {
                // Silenciar o log
            }
        });
    }

    /* ---------- Commands ---------- */
    public function handle(Message $message): void
    {
        $text   = $message->content;
        $pieces = explode(' ', $text);

        if ($pieces[0] !== '!bridge') {
            $this->handleDiscord($message);
            return;
        }

        if (count($pieces) < 2) {
            $message->channel->sendMessage('Sub-commands: `add`, `remove`, `list`, `status`, `fixoffset`.');
            return;
        }

        switch ($pieces[1]) {
            case 'add':
                if (count($pieces) !== 4) {
                    $message->channel->sendMessage('Use: `!bridge add <discordId> <telegramId>`');
                    return;
                }
                [$cmd, $sub, $dcId, $tgId] = $pieces;
                $this->addBridge($dcId, (int)$tgId);
                $message->channel->sendMessage("✅ Bridge Added: Discord {$dcId} ↔ Telegram {$tgId}");
                break;

            case 'remove':
                if (count($pieces) !== 3) {
                    $message->channel->sendMessage('Use: `!bridge remove <discordId>`');
                    return;
                }
                [$cmd, $sub, $dcId] = $pieces;
                $this->removeBridge($dcId);
                $message->channel->sendMessage("❌ Bridge removed: Discord {$dcId}");
                break;

            case 'list':
                $map = $this->listBridges();
                if (empty($map)) {
                    $message->channel->sendMessage('There is non active bridges.');
                    return;
                }
                $lines = array_map(
                    fn($dc, $tg) => "Discord **{$dc}** ↔ Telegram **{$tg}**",
                    array_keys($map),
                    $map
                );
                $message->channel->sendMessage("Active Bridges:\n" . implode("\n", $lines));
                break;

            case 'status':
                $map = $this->listBridges();
                $message->channel->sendMessage(
                    empty($map)
                        ? 'There is non active bridges.'
                        : "Active Bridges: " . json_encode($map, JSON_PRETTY_PRINT)
                );
                break;

            case 'fixoffset':
                try {
                    $token = $this->telegram->token; // Obtén el token directamente
                    $response = json_decode(
                        file_get_contents(
                            "https://api.telegram.org/bot{$token}/getUpdates?limit=1&offset=-1"
                        ),
                        true
                    );
                    if (!empty($response['result'])) {
                        $last = $response['result'][0]['update_id'];
                        $this->setLastOffset($last);
                        $message->channel->sendMessage("✅ Offset updated to: $last");
                    } else {
                        $this->setLastOffset(0);
                        $message->channel->sendMessage("⚠️ Without messages; offset restarted to 0");
                    }
                } catch (\Throwable $e) {
                    $message->channel->sendMessage("❌ Error while updating offset: " . $e->getMessage());
                }
                break;

            default:
                $message->channel->sendMessage('Sub-command not recognized: `add`, `remove`, `list`, `status`, `fixoffset`.');
        }
    }

    /* ---------- Utils ---------- */
    private function loadMap(): array
    {
        return json_decode(file_get_contents($this->storage), true) ?: [];
    }

    private function saveMap(array $map): void
    {
        file_put_contents($this->storage, json_encode($map, JSON_PRETTY_PRINT));
    }

    private function addBridge(string $dcId, int $tgId): void
    {
        $map         = $this->loadMap();
        $map[$dcId]  = $tgId;
        $this->saveMap($map);
    }

    private function removeBridge(string $dcId): void
    {
        $map = $this->loadMap();
        unset($map[$dcId]);
        $this->saveMap($map);
    }

    private function listBridges(): array
    {
        return $this->loadMap();
    }

    /* ---------- Offset helpers ---------- */
    private function getLastOffset(): int
    {
        return is_file($this->offsetFile)
            ? (int)file_get_contents($this->offsetFile)
            : 0;
    }

    private function setLastOffset(int $offset): void
    {
        file_put_contents($this->offsetFile, (string)$offset);
    }
}
