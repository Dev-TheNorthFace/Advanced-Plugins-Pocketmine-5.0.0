<?php

declare(strict_types=1);

namespace North\FortuneWheel\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\utils\Config;
use pocketmine\Server;

class Main extends PluginBase implements Listener {

    private $spins = [];
    private $wheelLevels = [];
    private $badLuck = [];
    private $stats = [];
    private $economy;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->loadData();
        $this->economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
    }

    private function loadData(): void {
        if(!file_exists($this->getDataFolder() . "data.yml")) {
            $this->saveResource("data.yml");
        }
        $data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
        $this->spins = $data->get("spins", []);
        $this->wheelLevels = $data->get("wheelLevels", []);
        $this->badLuck = $data->get("badLuck", []);
        $this->stats = $data->get("stats", []);
    }

    private function saveData(): void {
        $data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
        $data->set("spins", $this->spins);
        $data->set("wheelLevels", $this->wheelLevels);
        $data->set("badLuck", $this->badLuck);
        $data->set("stats", $this->stats);
        $data->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande ne peut être utilisée que dans le jeu.");
            return true;
        }

        $playerName = $sender->getName();

        if(!isset($this->wheelLevels[$playerName])) {
            $this->wheelLevels[$playerName] = 1;
        }

        if(!isset($this->badLuck[$playerName])) {
            $this->badLuck[$playerName] = 0;
        }

        if(!isset($this->stats[$playerName])) {
            $this->stats[$playerName] = [
                "totalSpins" => 0,
                "common" => 0,
                "uncommon" => 0,
                "medium" => 0,
                "rare" => 0,
                "legendary" => 0,
                "nothing" => 0,
                "trap" => 0
            ];
        }

        if(empty($args)) {
            $this->showWheelMenu($sender);
            return true;
        }

        switch(strtolower($args[0])) {
            case "buy":
                $this->buySpin($sender);
                break;
            case "spin":
                $this->spinWheel($sender);
                break;
            case "stats":
                $this->showStats($sender);
                break;
            default:
                $sender->sendMessage(TextFormat::RED . "Usage: /wheelspin [buy|spin|stats]");
        }

        return true;
    }

    private function showWheelMenu(Player $player): void {
        $player->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "=== Roue de la Fortune ===");
        $player->sendMessage(TextFormat::GREEN . "/wheelspin buy - Acheter un spin");
        $player->sendMessage(TextFormat::GREEN . "/wheelspin spin - Tourner la roue");
        $player->sendMessage(TextFormat::GREEN . "/wheelspin stats - Voir vos statistiques");
        $player->sendMessage(TextFormat::GOLD . "Niveau de votre roue: " . $this->wheelLevels[$player->getName()]);
    }

    private function buySpin(Player $player): void {
        $playerName = $player->getName();
        $wheelLevel = $this->wheelLevels[$playerName];
        $cost = $this->getSpinCost($wheelLevel);

        if($this->economy === null) {
            $player->sendMessage(TextFormat::RED . "Le système économique n'est pas disponible.");
            return;
        }

        $money = $this->economy->myMoney($playerName);

        if($money < $cost) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez pas assez d'argent. Il vous faut $" . ($cost - $money) . " de plus.");
            return;
        }

        $this->economy->reduceMoney($playerName, $cost);
        $this->spins[$playerName] = ($this->spins[$playerName] ?? 0) + 1;
        $this->saveData();

        $player->sendMessage(TextFormat::GREEN . "Vous avez acheté un spin pour $" . $cost . ". Utilisez /wheelspin spin pour tourner la roue.");
    }

    private function getSpinCost(int $wheelLevel): int {
        return match($wheelLevel) {
            1 => 2500,
            2 => 10000,
            3 => 25000,
            default => 2500,
        };
    }

    private function spinWheel(Player $player): void {
        $playerName = $player->getName();

        if(($this->spins[$playerName] ?? 0) < 1) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez pas de spins disponibles. Achetez-en avec /wheelspin buy.");
            return;
        }

        $this->spins[$playerName]--;
        $this->stats[$playerName]["totalSpins"]++;
        $wheelLevel = $this->wheelLevels[$playerName];
        $badLuckFactor = $this->badLuck[$playerName];

        $result = $this->determinePrize($wheelLevel, $badLuckFactor);
        $this->givePrize($player, $result);

        $this->updateWheelLevel($playerName);
        $this->saveData();

        $this->showSpinAnimation($player, $result);
    }

    private function determinePrize(int $wheelLevel, int $badLuck): array {
        $baseChances = $this->getBaseChances($wheelLevel);
        $boostedChances = $this->applyBadLuckBoost($baseChances, $badLuck);

        $total = array_sum($boostedChances);
        $rand = mt_rand(1, $total);
        $current = 0;

        foreach($boostedChances as $rarity => $chance) {
            $current += $chance;
            if($rand <= $current) {
                $prize = $this->getPrizeForRarity($rarity, $wheelLevel);
                return ["rarity" => $rarity, "prize" => $prize];
            }
        }

        return ["rarity" => "common", "prize" => ["money" => 100, "message" => "§aVous avez gagné §2100$§a!"]];
    }

    private function getBaseChances(int $wheelLevel): array {
        return match($wheelLevel) {
            1 => ["common" => 40, "uncommon" => 30, "medium" => 15, "rare" => 10, "legendary" => 4, "nothing" => 1, "trap" => 0],
            2 => ["common" => 30, "uncommon" => 25, "medium" => 20, "rare" => 15, "legendary" => 8, "nothing" => 1, "trap" => 1],
            3 => ["common" => 20, "uncommon" => 20, "medium" => 20, "rare" => 20, "legendary" => 15, "nothing" => 2, "trap" => 3],
            default => ["common" => 40, "uncommon" => 30, "medium" => 15, "rare" => 10, "legendary" => 4, "nothing" => 1, "trap" => 0],
        };
    }

    private function applyBadLuckBoost(array $chances, int $badLuck): array {
        $boost = min($badLuck * 2, 50);
        if($boost <= 0) return $chances;

        $boosted = $chances;
        $boosted["legendary"] += $boost * 0.4;
        $boosted["rare"] += $boost * 0.3;
        $boosted["medium"] += $boost * 0.2;
        $boosted["uncommon"] += $boost * 0.1;

        return $boosted;
    }

    private function getPrizeForRarity(string $rarity, int $wheelLevel): array {
        $prizes = [
            "common" => [
                ["money" => 100, "message" => "§aVous avez gagné §2100$§a!"],
                ["money" => 150, "message" => "§aVous avez gagné §2150$§a!"]
            ],
            "uncommon" => [
                ["money" => 500, "message" => "§6Vous avez gagné §2500$§6!"]
            ],
            "medium" => [
                ["money" => 1000, "message" => "§eVous avez gagné §21000$§e!"]
            ],
            "rare" => [
                ["money" => 5000, "message" => "§9Vous avez gagné §25000$§9!"]
            ],
            "legendary" => [
                ["money" => 15000, "message" => "§d§lVous avez gagné §215000$§d§l!"]
            ],
            "nothing" => [
                ["money" => 0, "message" => "§cDommage! Vous n'avez rien gagné cette fois."]
            ],
            "trap" => [
                ["money" => -25000, "message" => "§4§lPiège! Vous perdez §225000$§4§l!"]
            ]
        ];

        $levelMultiplier = 1 + ($wheelLevel * 0.2);
        $selected = $prizes[$rarity][array_rand($prizes[$rarity])];

        if($selected["money"] > 0) {
            $selected["money"] = (int)($selected["money"] * $levelMultiplier);
        }

        return $selected;
    }

    private function givePrize(Player $player, array $result): void {
        $playerName = $player->getName();
        $money = $result["prize"]["money"];
        $message = $result["prize"]["message"];

        if($this->economy !== null) {
            if($money > 0) {
                $this->economy->addMoney($playerName, $money);
            } elseif($money < 0) {
                $this->economy->reduceMoney($playerName, abs($money));
            }
        }

        $player->sendMessage($message);
        $this->stats[$playerName][$result["rarity"]]++;

        if($result["rarity"] === "legendary") {
            $this->getServer()->broadcastMessage("§6 " . $playerName . " a gagné un prix légendaire avec la roue de la fortune !");
        }

        if($result["rarity"] === "nothing" || $result["rarity"] === "trap") {
            $this->badLuck[$playerName]++;
        } else {
            $this->badLuck[$playerName] = max(0, $this->badLuck[$playerName] - 1);
        }
    }

    private function updateWheelLevel(string $playerName): void {
        $spins = $this->stats[$playerName]["totalSpins"];
        $currentLevel = $this->wheelLevels[$playerName];

        if($currentLevel >= 3) return;

        $requiredSpins = match($currentLevel) {
            1 => 50,
            2 => 150,
            default => PHP_INT_MAX
        };

        if($spins >= $requiredSpins) {
            $this->wheelLevels[$playerName]++;
            $player = $this->getServer()->getPlayerExact($playerName);
            if($player !== null) {
                $player->sendMessage(TextFormat::GOLD . TextFormat::BOLD . "Félicitations! Votre roue est maintenant niveau " . $this->wheelLevels[$playerName] . "!");
            }
        }
    }

    private function showStats(Player $player): void {
        $playerName = $player->getName();
        $stats = $this->stats[$playerName] ?? [
            "totalSpins" => 0,
            "common" => 0,
            "uncommon" => 0,
            "medium" => 0,
            "rare" => 0,
            "legendary" => 0,
            "nothing" => 0,
            "trap" => 0
        ];

        $player->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "=== Vos Statistiques ===");
        $player->sendMessage(TextFormat::WHITE . "Spins totaux: " . $stats["totalSpins"]);
        $player->sendMessage(TextFormat::GREEN . "Commun: " . $stats["common"]);
        $player->sendMessage(TextFormat::YELLOW . "Peu commun: " . $stats["uncommon"]);
        $player->sendMessage(TextFormat::GOLD . "Moyen: " . $stats["medium"]);
        $player->sendMessage(TextFormat::BLUE . "Rare: " . $stats["rare"]);
        $player->sendMessage(TextFormat::LIGHT_PURPLE . "Légendaire: " . $stats["legendary"]);
        $player->sendMessage(TextFormat::RED . "Rien: " . $stats["nothing"]);
        $player->sendMessage(TextFormat::DARK_RED . "Pièges: " . $stats["trap"]);
        $player->sendMessage(TextFormat::GOLD . "Niveau de roue: " . ($this->wheelLevels[$playerName] ?? 1));
        $player->sendMessage(TextFormat::AQUA . "Facteur de malchance: " . ($this->badLuck[$playerName] ?? 0));
    }

    private function showSpinAnimation(Player $player, array $result): void {
        $rarityColors = [
            "common" => TextFormat::GREEN,
            "uncommon" => TextFormat::YELLOW,
            "medium" => TextFormat::GOLD,
            "rare" => TextFormat::BLUE,
            "legendary" => TextFormat::LIGHT_PURPLE,
            "nothing" => TextFormat::RED,
            "trap" => TextFormat::DARK_RED
        ];

        $color = $rarityColors[$result["rarity"]] ?? TextFormat::WHITE;
        $symbols = ["🎡", "🎠", "🎢", "⚡", "✨", "🌟", "💫"];

        for($i = 0; $i < 5; $i++) {
            $player->sendTitle($color . $symbols[array_rand($symbols)], "La roue tourne...", 0, 20, 0);
            usleep(300000);
        }

        $player->sendTitle($color . "Résultat!", $result["prize"]["message"], 10, 70, 20);
    }

    public function onDisable(): void {
        $this->saveData();
    }
}