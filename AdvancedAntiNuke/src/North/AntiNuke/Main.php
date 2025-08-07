<?php

declare(strict_types=1);

namespace North\AntiNuke\Main;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;

class Main extends PluginBase implements Listener {

    private array $breakData = [];
    private array $frozenPlayers = [];
    private Config $config;
    private array $violations = [];
    private array $lastPositions = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        @mkdir($this->getDataFolder() . "logs");
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->cleanOldData();
        }), 20);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->breakData[$player->getName()] = [
            'blocks' => [],
            'last_break' => 0,
            'total_breaks' => 0,
            'violation_level' => 0
        ];
        $this->lastPositions[$player->getName()] = $player->getPosition();
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $playerName = $event->getPlayer()->getName();
        unset($this->breakData[$playerName]);
        unset($this->frozenPlayers[$playerName]);
        unset($this->violations[$playerName]);
        unset($this->lastPositions[$playerName]);
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        if (isset($this->frozenPlayers[$playerName])) {
            $event->cancel();
            $player->sendTip(TextFormat::RED . "🛑 Vous êtes gelé pour minage suspect");
            return;
        }

        $now = microtime(true);
        $blockPos = $event->getBlock()->getPosition();
        $lastPosition = $this->lastPositions[$playerName] ?? $player->getPosition();
        $this->breakData[$playerName]['blocks'][] = [
            'time' => $now,
            'position' => $blockPos,
            'distance' => $this->calculateDistance($lastPosition, $blockPos)
        ];
        $this->breakData[$playerName]['last_break'] = $now;
        $this->breakData[$playerName]['total_breaks']++;
        $this->lastPositions[$playerName] = $player->getPosition();
        $this->analyzeMiningPattern($player);
    }

    private function calculateDistance(Position $pos1, Position $pos2): float {
        return sqrt(
            ($pos1->x - $pos2->x) ** 2 +
            ($pos1->y - $pos2->y) ** 2 +
            ($pos1->z - $pos2->z) ** 2
        );
    }

    private function analyzeMiningPattern(Player $player): void {
        $playerName = $player->getName();
        $now = microtime(true);
        $data = &$this->breakData[$playerName];
        foreach ($data['blocks'] as $key => $break) {
            if ($now - $break['time'] > 1.0) {
                unset($data['blocks'][$key]);
            }
        }
        $data['blocks'] = array_values($data['blocks']);
        $blocksPerSecond = count($data['blocks']);
        $maxBPS = $this->config->getNested('antiNuke.max_blocks_per_sec', 15);

        if ($blocksPerSecond > $maxBPS) {
            $data['violation_level'] += ($blocksPerSecond - $maxBPS);
            $this->violations[$playerName] = ($this->violations[$playerName] ?? 0) + 1;
            $this->flagPlayer($player, "Minage trop rapide ($blocksPerSecond blocs/sec)");
        }

        if (count($data['blocks']) >= 2) {
            $minX = $maxX = $data['blocks'][0]['position']->x;
            $minZ = $maxZ = $data['blocks'][0]['position']->z;

            foreach ($data['blocks'] as $break) {
                $pos = $break['position'];
                $minX = min($minX, $pos->x);
                $maxX = max($maxX, $pos->x);
                $minZ = min($minZ, $pos->z);
                $maxZ = max($maxZ, $pos->z);
            }

            $areaWidth = $maxX - $minX + 1;
            $areaLength = $maxZ - $minZ + 1;
            $maxArea = $this->config->getNested('antiNuke.max_area_per_tick', 4);

            if ($areaWidth > $maxArea || $areaLength > $maxArea) {
                $data['violation_level'] += 5;
                $this->violations[$playerName] = ($this->violations[$playerName] ?? 0) + 1;
                $this->flagPlayer($player, "Zone de minage trop large ($areaWidth x $areaLength)");
            }
        }

        $totalBreaks = $data['total_breaks'];
        $timeSinceFirstBreak = $now - ($data['blocks'][0]['time'] ?? $now);

        if ($timeSinceFirstBreak > 10 && $totalBreaks / $timeSinceFirstBreak > $maxBPS * 0.8) {
            $this->flagPlayer($player, "Minage constant suspect ($totalBreaks blocs en $timeSinceFirstBreak s)");
        }

        if ($data['violation_level'] >= $this->config->getNested('antiNuke.auto_ban_above', 30)) {
            $this->punishPlayer($player, "ban");
        } elseif ($data['violation_level'] >= $this->config->getNested('antiNuke.auto_flag_above', 20)) {
            $this->punishPlayer($player, "freeze");
        }
    }

    private function flagPlayer(Player $player, string $reason): void {
        $playerName = $player->getName();
        $logMessage = "[" . date("H:i:s") . "] $playerName - $reason\n";
        file_put_contents($this->getDataFolder() . "logs/mining.log", $logMessage, FILE_APPEND);
        if ($this->config->getNested('antiNuke.notify_mods', true)) {
            $alert = TextFormat::RED . "[ANTI-NUKE] " . TextFormat::YELLOW . $playerName .
                TextFormat::RED . " suspect: " . TextFormat::WHITE . $reason;

            foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
                if ($onlinePlayer->hasPermission("antinuke.alerts")) {
                    $onlinePlayer->sendMessage($alert);
                }
            }
            $this->getLogger()->warning($alert);
        }
    }

    private function punishPlayer(Player $player, string $action): void {
        $playerName = $player->getName();

        switch ($action) {
            case "freeze":
                $this->frozenPlayers[$playerName] = time() + $this->config->getNested('antiNuke.freeze_duration', 10);
                $player->sendTitle(TextFormat::RED . "COMPTE RENDU", "Analyse anti-triche en cours...", 20, 100, 20);
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($playerName): void {
                    if (isset($this->frozenPlayers[$playerName])) {
                        unset($this->frozenPlayers[$playerName]);
                        $player = $this->getServer()->getPlayerExact($playerName);
                        if ($player !== null) {
                            $player->sendTitle(TextFormat::GREEN . "ANALYSE TERMINÉE", "Vous pouvez continuer", 20, 60, 20);
                        }
                    }
                }), $this->config->getNested('antiNuke.freeze_duration', 10) * 20);
                break;

            case "ban":
                $player->kick(TextFormat::RED . "Détection de triche (minage anormal)");
                break;
        }

        if (isset($this->breakData[$playerName])) {
            $this->breakData[$playerName]['violation_level'] = 0;
        }
    }

    private function cleanOldData(): void {
        $now = time();
        foreach ($this->frozenPlayers as $playerName => $unfreezeTime) {
            if ($now >= $unfreezeTime) {
                unset($this->frozenPlayers[$playerName]);
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch (strtolower($command->getName())) {
            case "nukestats":
                if (count($args) > 0 && $sender->hasPermission("antinuke.admin")) {
                    return $this->showPlayerStats($sender, $args[0]);
                }
                return $this->showSelfStats($sender);

            case "antinuke":
                return $this->showSystemInfo($sender);
        }
        return false;
    }

    private function showPlayerStats(CommandSender $sender, string $playerName): bool {
        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player === null) {
            $sender->sendMessage(TextFormat::RED . "Joueur non trouvé ou hors ligne.");
            return false;
        }

        $name = $player->getName();
        $data = $this->breakData[$name] ?? null;

        if ($data === null) {
            $sender->sendMessage(TextFormat::RED . "Aucune donnée disponible pour ce joueur.");
            return false;
        }

        $blocksLastSec = count(array_filter($data['blocks'], fn($b) => microtime(true) - $b['time'] <= 1.0));
        $isFrozen = isset($this->frozenPlayers[$name]) ? TextFormat::RED . "OUI" : TextFormat::GREEN . "NON";
        $violations = $this->violations[$name] ?? 0;

        $sender->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "----- Stats Minage de $name -----");
        $sender->sendMessage(TextFormat::GREEN . "Blocs/min (1s): " . TextFormat::WHITE . $blocksLastSec);
        $sender->sendMessage(TextFormat::GREEN . "Violations: " . TextFormat::WHITE . $violations);
        $sender->sendMessage(TextFormat::GREEN . "Freeze: " . $isFrozen);

        return true;
    }

    private function showSelfStats(CommandSender $sender): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande ne peut être utilisée que par un joueur.");
            return false;
        }

        return $this->showPlayerStats($sender, $sender->getName());
    }

    private function showSystemInfo(CommandSender $sender): bool {
        $sender->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "----- AntiNukeCore Info -----");
        $sender->sendMessage(TextFormat::GREEN . "Blocs/sec max: " . TextFormat::WHITE . $this->config->getNested('antiNuke.max_blocks_per_sec', 15));
        $sender->sendMessage(TextFormat::GREEN . "Zone max/tick: " . TextFormat::WHITE . $this->config->getNested('antiNuke.max_area_per_tick', 4) . "x" . $this->config->getNested('antiNuke.max_area_per_tick', 4));
        $sender->sendMessage(TextFormat::GREEN . "Joueurs surveillés: " . TextFormat::WHITE . count($this->breakData));
        $sender->sendMessage(TextFormat::GREEN . "Joueurs gelés: " . TextFormat::WHITE . count($this->frozenPlayers));

        return true;
    }
}