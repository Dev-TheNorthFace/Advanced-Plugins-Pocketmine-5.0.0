<?php

declare(strict_types=1);

namespace North\TopLuck\Main;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

class Main extends PluginBase implements Listener {

    private Config $playerData;
    private array $trackedBlocks = [];
    private array $fakeOres = [];
    private array $sessionStartTime = [];
    private float $serverAverage = 0.0;
    private int $totalServerOres = 0;
    private int $totalServerBlocks = 0;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->playerData = new Config($this->getDataFolder() . "player_data.yml", Config::YAML, []);
        $this->saveDefaultConfig();

        $this->initTrackedBlocks();
        $this->loadServerStats();
    }

    private function initTrackedBlocks(): void {
        $this->trackedBlocks = [
            BlockTypeIds::DIAMOND_ORE => ["name" => "diamond", "rarity" => 10],
            BlockTypeIds::EMERALD_ORE => ["name" => "emerald", "rarity" => 15],
            BlockTypeIds::GOLD_ORE => ["name" => "gold", "rarity" => 7],
            BlockTypeIds::REDSTONE_ORE => ["name" => "redstone", "rarity" => 5],
            BlockTypeIds::LAPIS_LAZULI_ORE => ["name" => "lapis", "rarity" => 5],
            BlockTypeIds::ANCIENT_DEBRIS => ["name" => "netherite", "rarity" => 20],
        ];
    }

    private function loadServerStats(): void {
        $data = $this->playerData->getAll();
        foreach ($data as $playerData) {
            $this->totalServerOres += $playerData["ores_found"] ?? 0;
            $this->totalServerBlocks += $playerData["blocks_broken"] ?? 0;
        }

        if ($this->totalServerBlocks > 0) {
            $this->serverAverage = ($this->totalServerOres / $this->totalServerBlocks) * 100;
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $blockId = $block->getTypeId();
        $playerName = $player->getName();

        $playerData = $this->getPlayerData($playerName);

        $playerData["blocks_broken"] = ($playerData["blocks_broken"] ?? 0) + 1;

        if (isset($this->trackedBlocks[$blockId])) {
            $oreType = $this->trackedBlocks[$blockId]["name"];
            $playerData["ores_found"] = ($playerData["ores_found"] ?? 0) + 1;
            $playerData[$oreType . "_ore"] = ($playerData[$oreType . "_ore"] ?? 0) + 1;

            $this->checkFakeOre($player, $block->getPosition());
        }

        $this->updatePlayerData($playerName, $playerData);
        $this->checkPlayerLuck($player, $playerData);
    }

    private function checkFakeOre(Player $player, Position $position): void {
        foreach ($this->fakeOres as $key => $fakeOre) {
            if ($fakeOre["position"]->equals($position)) {
                $playerData = $this->getPlayerData($player->getName());
                $playerData["fake_ore_mined"] = ($playerData["fake_ore_mined"] ?? 0) + 1;
                $this->updatePlayerData($player->getName(), $playerData);

                $this->getServer()->broadcastMessage(TextFormat::RED . "[TopLuck] " . $player->getName() . " a miné un faux minerai! Preuve de X-Ray!");
                unset($this->fakeOres[$key]);
                break;
            }
        }
    }

    private function checkPlayerLuck(Player $player, array $playerData): void {
        if (($playerData["blocks_broken"] ?? 0) < 50) return;

        $luckScore = $this->calculateLuckScore($playerData);
        $playerData["luck_score"] = $luckScore;
        $this->updatePlayerData($player->getName(), $playerData);

        if ($luckScore > 90) {
            $this->getServer()->broadcastMessage(TextFormat::RED . "[TopLuck] " . $player->getName() . " a été kick pour suspicion de X-Ray (Score: " . $luckScore . ")");
            $player->kick(TextFormat::RED . "Suspicion de triche (X-Ray)");
        } elseif ($luckScore > 75) {
            $player->sendMessage(TextFormat::RED . "Attention! Votre taux de minerais est anormalement élevé!");
            $this->getServer()->getLogger()->warning("[TopLuck] " . $player->getName() . " est suspect (Score: " . $luckScore . ")");
        } elseif ($luckScore > 60) {
            $this->getServer()->getLogger()->info("[TopLuck] " . $player->getName() . " a un score élevé (Score: " . $luckScore . ")");
        }
    }

    private function calculateLuckScore(array $playerData): float {
        $score = 0.0;
        $totalOres = $playerData["ores_found"] ?? 0;
        $totalBlocks = $playerData["blocks_broken"] ?? 1;

        $basicRatio = ($totalOres / $totalBlocks) * 1000;
        $score += $basicRatio;

        foreach ($this->trackedBlocks as $blockId => $data) {
            $oreType = $data["name"];
            $oreCount = $playerData[$oreType . "_ore"] ?? 0;
            $oreRatio = ($oreCount / $totalBlocks) * 100;

            if ($oreRatio > 3) {
                $score += $data["rarity"] * ($oreRatio - 3);
            }
        }

        if (isset($playerData["fake_ore_mined"])) {
            $score += 1000;
        }

        return min(100, $score);
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->sessionStartTime[$player->getName()] = time();

        $this->spawnFakeOreNearPlayer($player);
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        if (isset($this->sessionStartTime[$playerName])) {
            $sessionTime = time() - $this->sessionStartTime[$playerName];
            $playerData = $this->getPlayerData($playerName);
            $playerData["time_online"] = ($playerData["time_online"] ?? 0) + $sessionTime;
            $this->updatePlayerData($playerName, $playerData);
            unset($this->sessionStartTime[$playerName]);
        }
    }

    private function spawnFakeOreNearPlayer(Player $player): void {
        $position = $player->getPosition();
        $world = $player->getWorld();

        $fakePosition = $position->add(mt_rand(-10, 10), mt_rand(-5, 5), mt_rand(-10, 10));

        $fakeBlock = VanillaBlocks::DIAMOND_ORE();
        $world->setBlock($fakePosition, $fakeBlock);

        $this->fakeOres[] = [
            "position" => $fakePosition,
            "block" => $fakeBlock,
            "player" => $player->getName()
        ];
    }

    private function getPlayerData(string $playerName): array {
        return $this->playerData->get($playerName, []);
    }

    private function updatePlayerData(string $playerName, array $data): void {
        $this->playerData->set($playerName, $data);
        $this->playerData->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch ($command->getName()) {
            case "topluck":
                $this->showTopLuck($sender);
                return true;

            case "luckstats":
                $target = $args[0] ?? $sender->getName();
                $this->showLuckStats($sender, $target);
                return true;

            case "luckreset":
                if (!$sender->hasPermission("topluck.reset")) {
                    $sender->sendMessage(TextFormat::RED . "Permission denied.");
                    return false;
                }
                $target = $args[0] ?? null;
                if ($target === null) {
                    $sender->sendMessage(TextFormat::RED . "Usage: /luckreset <player>");
                    return false;
                }
                $this->resetPlayerStats($target);
                $sender->sendMessage(TextFormat::GREEN . "Stats reset for " . $target);
                return true;

            case "luckflaglist":
                $this->showFlagList($sender);
                return true;
        }
        return false;
    }

    private function showTopLuck(CommandSender $sender): void {
        $allData = $this->playerData->getAll();
        $sortedPlayers = [];

        foreach ($allData as $playerName => $data) {
            if (($data["blocks_broken"] ?? 0) < 100) continue;

            $luckScore = $this->calculateLuckScore($data);
            $sortedPlayers[$playerName] = $luckScore;
        }

        arsort($sortedPlayers);
        $topPlayers = array_slice($sortedPlayers, 0, 10, true);

        $sender->sendMessage(TextFormat::GOLD . "=== TopLuck Ranking ===");
        $rank = 1;
        foreach ($topPlayers as $playerName => $score) {
            $playerData = $this->getPlayerData($playerName);
            $diamonds = $playerData["diamond_ore"] ?? 0;
            $stones = $playerData["blocks_broken"] ?? 1;
            $ratio = ($diamonds / $stones) * 100;

            $medal = match($rank) {
                1 => "🥇",
                2 => "🥈",
                3 => "🥉",
                default => "🔹"
            };

            $sender->sendMessage(TextFormat::WHITE . $medal . " " . $playerName . " - " . $diamonds . "💎 / " . $stones . "🧱 - " . number_format($ratio, 2) . "% - Score: " . number_format($score, 1));
            $rank++;
        }
    }

    private function showLuckStats(CommandSender $sender, string $target): void {
        $playerData = $this->getPlayerData($target);

        if (empty($playerData)) {
            $sender->sendMessage(TextFormat::RED . "No data found for " . $target);
            return;
        }

        $luckScore = $this->calculateLuckScore($playerData);
        $status = match(true) {
            $luckScore > 90 => TextFormat::RED . "X-Ray avéré 🚨",
            $luckScore > 70 => TextFormat::RED . "Flag XRay ❗",
            $luckScore > 30 => TextFormat::YELLOW . "À surveiller ⚠️",
            default => TextFormat::GREEN . "Clean ✅"
        };

        $sender->sendMessage(TextFormat::GOLD . "=== LuckStats for " . $target . " ===");
        $sender->sendMessage(TextFormat::WHITE . "Score: " . number_format($luckScore, 1) . " - " . $status);
        $sender->sendMessage(TextFormat::WHITE . "Blocs minés: " . ($playerData["blocks_broken"] ?? 0));
        $sender->sendMessage(TextFormat::WHITE . "Minerais trouvés: " . ($playerData["ores_found"] ?? 0));

        foreach ($this->trackedBlocks as $blockId => $data) {
            $oreType = $data["name"];
            $count = $playerData[$oreType . "_ore"] ?? 0;
            if ($count > 0) {
                $sender->sendMessage(TextFormat::WHITE . ucfirst($oreType) . ": " . $count);
            }
        }

        if (isset($playerData["fake_ore_mined"])) {
            $sender->sendMessage(TextFormat::RED . "FAUX MINERAI MINÉ: " . $playerData["fake_ore_mined"] . " fois!");
        }
    }

    private function resetPlayerStats(string $playerName): void {
        $this->playerData->remove($playerName);
        $this->playerData->save();
    }

    private function showFlagList(CommandSender $sender): void {
        $allData = $this->playerData->getAll();
        $flaggedPlayers = [];

        foreach ($allData as $playerName => $data) {
            $luckScore = $this->calculateLuckScore($data);
            if ($luckScore > 60) {
                $flaggedPlayers[$playerName] = $luckScore;
            }
        }

        arsort($flaggedPlayers);

        $sender->sendMessage(TextFormat::GOLD . "=== Flagged Players ===");
        if (empty($flaggedPlayers)) {
            $sender->sendMessage(TextFormat::GREEN . "No flagged players!");
            return;
        }

        foreach ($flaggedPlayers as $playerName => $score) {
            $status = match(true) {
                $score > 90 => TextFormat::DARK_RED . "BAN",
                $score > 75 => TextFormat::RED . "FREEZE",
                default => TextFormat::YELLOW . "WARN"
            };

            $sender->sendMessage(TextFormat::WHITE . $playerName . " - Score: " . number_format($score, 1) . " - Action: " . $status);
        }
    }
}