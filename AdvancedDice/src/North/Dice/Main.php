<?php

declare(strict_types=1);

namespace North\Dice\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\particle\FireworksParticle;
use pocketmine\world\sound\PopSound;

class Main extends PluginBase implements Listener {

    private $cooldowns = [];
    private $stats = [];
    private $config;

    protected function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->stats = (new Config($this->getDataFolder() . "stats.yml", Config::YAML))->getAll();
    }

    protected function onDisable(): void {
        (new Config($this->getDataFolder() . "stats.yml", Config::YAML))->setAll($this->stats);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "dice") {
            if (!$sender instanceof Player) {
                $sender->sendMessage(TextFormat::RED . "Cette commande ne peut être utilisée que dans le jeu.");
                return true;
            }

            $playerName = $sender->getName();

            if (isset($this->cooldowns[$playerName]) && time() - $this->cooldowns[$playerName] < $this->config->get("cooldown", 10)) {
                $sender->sendMessage(TextFormat::RED . "Attendez avant de relancer le dé.");
                return true;
            }

            $cost = $this->config->get("cost_per_roll", 0);
            if ($cost > 0) {
                if ($sender->getXpManager()->getXpLevel() < $cost) {
                    $sender->sendMessage(TextFormat::RED . "Vous n'avez pas assez d'XP (" . $cost . " niveaux requis).");
                    return true;
                }
                $sender->getXpManager()->subtractXpLevels($cost);
            }

            $this->cooldowns[$playerName] = time();

            $this->initPlayerStats($playerName);

            $sender->sendTitle(TextFormat::YELLOW . "", TextFormat::WHITE . "Lancement du dé...", 20, 60, 20);
            $sender->getWorld()->addSound($sender->getPosition(), new PopSound());

            $this->getScheduler()->scheduleDelayedTask(new class($this, $sender) extends \pocketmine\scheduler\Task {
                public function __construct(private Main $plugin, private Player $player) {}

                public function onRun(): void {
                    $this->plugin->rollDice($this->player);
                }
            }, 40);

            return true;
        } elseif ($command->getName() === "dicestats") {
            $target = $args[0] ?? $sender->getName();
            if (isset($this->stats[$target])) {
                $stats = $this->stats[$target];
                $sender->sendMessage(TextFormat::GOLD . " Stats de " . $target . ":");
                $sender->sendMessage(TextFormat::WHITE . "- Lancers: " . $stats["rolls"]);
                $sender->sendMessage(TextFormat::WHITE . "- Plus haut score: " . $stats["highest"]);
                $sender->sendMessage(TextFormat::WHITE . "- Jackpots: " . $stats["jackpots"]);
                $sender->sendMessage(TextFormat::WHITE . "- Total gagné: " . $stats["total_won"] . "$");
            } else {
                $sender->sendMessage(TextFormat::RED . "Aucune statistique trouvée pour " . $target);
            }
            return true;
        }
        return false;
    }

    private function initPlayerStats(string $playerName): void {
        if (!isset($this->stats[$playerName])) {
            $this->stats[$playerName] = [
                "rolls" => 0,
                "highest" => 0,
                "jackpots" => 0,
                "total_won" => 0
            ];
        }
    }

    private function rollDice(Player $player): void {
        $playerName = $player->getName();
        $roll = mt_rand(1, 100);

        $this->stats[$playerName]["rolls"]++;
        if ($roll > $this->stats[$playerName]["highest"]) {
            $this->stats[$playerName]["highest"] = $roll;
        }

        $reward = $this->getReward($roll);
        $rewardText = $this->processReward($player, $roll, $reward);

        $player->sendTitle(TextFormat::YELLOW . " " . $roll, $rewardText, 20, 60, 20);

        if ($roll === 100) {
            $this->stats[$playerName]["jackpots"]++;
            $this->getServer()->broadcastMessage(TextFormat::GOLD . " " . $playerName . " a tiré un 100 sur /dice et remporte le JACKPOT !");
            $player->getWorld()->addParticle($player->getPosition(), new FireworksParticle());
        } elseif ($roll >= $this->config->get("announce_threshold", 90) && $this->config->get("announce_on_high", true)) {
            $this->getServer()->broadcastMessage(TextFormat::GREEN . " " . $playerName . " a tiré un " . $roll . " sur /dice !");
        }
    }

    private function getReward(int $roll): array {
        $rewards = $this->config->get("reward_ranges", []);
        foreach ($rewards as $rewardData) {
            $range = $rewardData["range"];
            if (strpos($range, "-") !== false) {
                [$min, $max] = explode("-", $range);
                if ($roll >= (int)$min && $roll <= (int)$max) {
                    return $rewardData;
                }
            } elseif ((int)$range === $roll) {
                return $rewardData;
            }
        }
        return ["reward" => "none"];
    }

    private function processReward(Player $player, int $roll, array $rewardData): string {
        $reward = $rewardData["reward"] ?? "none";
        $playerName = $player->getName();

        if ($reward === "none") {
            return TextFormat::RED . "Pas de chance... Reessayez !";
        }

        $rewardParts = explode(":", $reward);
        $rewardType = $rewardParts[0];
        $rewardValue = $rewardParts[1] ?? 0;

        switch ($rewardType) {
            case "money":
                $this->stats[$playerName]["total_won"] += (int)$rewardValue;
                $player->getXpManager()->addXpLevels((int)$rewardValue);
                return TextFormat::GREEN . "Vous gagnez " . $rewardValue . "$ !";
            default:
                return TextFormat::YELLOW . "Récompense inconnue";
        }
    }
}