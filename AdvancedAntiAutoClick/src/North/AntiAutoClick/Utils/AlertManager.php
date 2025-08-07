<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Utils\AlertManager;

use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\AsyncTask;

class AlertManager {

    private Config $config;
    private string $prefix;
    private array $alertHistory = [];

    public function __construct(Config $config) {
        $this->config = $config;
        $this->prefix = $this->config->get("alert_prefix", "§8[§cAntiAC§8]§r");
    }

    public function handleViolation(Player $player, string $type, float $cps, int $humanScore): void {
        $reason = $this->getReasonMessage($type);
        $playerName = $player->getName();

        $this->sendInGameAlert($playerName, $reason, $cps, $humanScore);
        $this->sendConsoleAlert($playerName, $reason, $cps);
        $this->addToHistory($playerName, $reason);

        if($this->shouldFreeze($type)) {
            $this->sendFreezeMessage($player);
        }

        if($this->config->get("discord_webhook.enabled", false)) {
            $this->sendDiscordAlert($playerName, $reason, $cps, $humanScore);
        }
    }

    private function sendInGameAlert(string $player, string $reason, float $cps, int $humanScore): void {
        $message = sprintf(
            "%s §c%s §7- §f%s\n§7CPS: §f%.1f | Score: §f%d/100",
            $this->prefix,
            $player,
            $reason,
            $cps,
            $humanScore
        );

        foreach(Server::getInstance()->getOnlinePlayers() as $onlinePlayer) {
            if($onlinePlayer->hasPermission("anticheat.notify")) {
                $onlinePlayer->sendMessage($message);
            }
        }
    }

    private function sendConsoleAlert(string $player, string $reason, float $cps): void {
        $logMessage = sprintf(
            "[AntiAC] %s - %s (CPS: %.1f)",
            $player,
            strip_tags($reason),
            $cps
        );
        Server::getInstance()->getLogger()->warning($logMessage);
    }

    private function sendFreezeMessage(Player $player): void {
        $player->sendTitle(
            TextFormat::RED . "§lFREEZE",
            TextFormat::GRAY . "Suspicion d'auto-click",
            20, 100, 20
        );

        $messages = [
            "§cVous avez été freeze par l'anti-cheat!",
            "§7Raison: §fComportement de clic suspect",
            "§7Contactez un staff pour examen"
        ];

        foreach($messages as $message) {
            $player->sendMessage($message);
        }
    }

    private function sendDiscordAlert(string $player, string $reason, float $cps, int $humanScore): void {
        $webhookUrl = $this->config->get("discord_webhook.url", "");
        if(empty($webhookUrl)) return;

        $data = [
            "embeds" => [
                [
                    "title" => "🚨 Anti-Cheat Alert",
                    "description" => "**Player:** $player\n**Reason:** $reason",
                    "color" => 16711680,
                    "fields" => [
                        ["name" => "CPS", "value" => round($cps, 1), "inline" => true],
                        ["name" => "Human Score", "value" => "$humanScore/100", "inline" => true]
                    ],
                    "timestamp" => date("c")
                ]
            ]
        ];

        Server::getInstance()->getAsyncPool()->submitTask(new class($webhookUrl, $data) extends AsyncTask {
            private string $url;
            private string $data;

            public function __construct(string $url, array $data) {
                $this->url = $url;
                $this->data = json_encode($data);
            }

            public function onRun(): void {
                $options = [
                    'http' => [
                        'header' => "Content-type: application/json\r\n",
                        'method' => 'POST',
                        'content' => $this->data,
                        'timeout' => 5
                    ]
                ];
                @file_get_contents($this->url, false, stream_context_create($options));
            }
        });
    }

    private function getReasonMessage(string $type): string {
        $reasons = [
            "high_cps" => "CPS trop élevé",
            "pattern" => "Pattern de clic suspect",
            "delay" => "Intervalle de clic trop régulier"
        ];
        return $reasons[$type] ?? "Comportement suspect";
    }

    private function shouldFreeze(string $type): bool {
        return $this->config->get("autopunish.$type.freeze", true);
    }

    private function addToHistory(string $player, string $reason): void {
        $this->alertHistory[] = [
            "player" => $player,
            "reason" => $reason,
            "time" => time()
        ];

        if(count($this->alertHistory) > 100) {
            array_shift($this->alertHistory);
        }
    }

    public function getAlertHistory(): array {
        return $this->alertHistory;
    }
}