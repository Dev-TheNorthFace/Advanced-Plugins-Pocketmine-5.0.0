<?php

declare(strict_types=1);

namespace North\Slot\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\Plugin;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private $plugin;
    private $cooldowns = [];
    private $symbols = ['🍒', '🍋', '💎', '🧨', '💰', '🧱'];
    private $weights = [40, 30, 15, 10, 4, 1];
    private $rewards = [
        '💰💰💰' => 50,
        '💎💎💎' => 20,
        '🍋🍋🍋' => 5,
        '🍒🍒🍒' => 2,
        'default_mixed' => 3,
        'two_same' => 1.5
    ];
    private $minBet = 100;
    private $maxBet = 5000;
    private $cooldownTime = 10;
    private $bigWinMultiplier = 20;

    public function __construct(Plugin $plugin) {
        parent::__construct("slot", "Jouer à la machine à sous");
        $this->plugin = $plugin;
        $this->setPermission("slot.command.use");
    }

    public function getOwningPlugin(): Plugin {
        return $this->plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande ne peut être utilisée que dans le jeu.");
            return false;
        }

        if (!isset($args[0])) {
            $sender->sendMessage(TextFormat::RED . "Usage: /slot <mise>");
            return false;
        }

        $bet = (int)$args[0];
        if ($bet < $this->minBet || $bet > $this->maxBet) {
            $sender->sendMessage(TextFormat::RED . "La mise doit être entre " . $this->minBet . " et " . $this->maxBet);
            return false;
        }

        $economy = $this->plugin->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if ($economy === null) {
            $sender->sendMessage(TextFormat::RED . "Le plugin EconomyAPI n'est pas installé.");
            return false;
        }

        if ($economy->myMoney($sender) < $bet) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas assez d'argent.");
            return false;
        }

        if (isset($this->cooldowns[$sender->getName()]) {
            $remaining = $this->cooldowns[$sender->getName()] - time();
            if ($remaining > 0) {
                $sender->sendMessage(TextFormat::RED . "Attendez " . $remaining . " secondes avant de rejouer.");
                return false;
            }
        }

        $economy->reduceMoney($sender, $bet);
        $this->cooldowns[$sender->getName()] = time() + $this->cooldownTime;

        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sender, $bet): void {
            $result = $this->spin($sender, $bet);
            $this->displayResult($sender, $result, $bet);
        }), 20);

        return true;
    }

    private function spin(Player $player, int $bet): array {
        $slots = [];
        for ($i = 0; $i < 3; $i++) {
            $rand = mt_rand(1, 100);
            $cumulative = 0;
            foreach ($this->weights as $index => $weight) {
                $cumulative += $weight;
                if ($rand <= $cumulative) {
                    $slots[] = $this->symbols[$index];
                    break;
                }
            }
        }
        return $slots;
    }

    private function displayResult(Player $player, array $slots, int $bet): void {
        $economy = $this->plugin->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        $result = implode("", $slots);
        $multiplier = 0;

        if ($result === "💰💰💰") {
            $multiplier = $this->rewards['💰💰💰'];
        } elseif ($result === "💎💎💎") {
            $multiplier = $this->rewards['💎💎💎'];
        } elseif ($result === "🍋🍋🍋") {
            $multiplier = $this->rewards['🍋🍋🍋'];
        } elseif ($result === "🍒🍒🍒") {
            $multiplier = $this->rewards['🍒🍒🍒'];
        } elseif (substr_count($result, '💰') >= 2 || substr_count($result, '💎') >= 2) {
            $multiplier = $this->rewards['default_mixed'];
        } elseif ($slots[0] === $slots[1] || $slots[1] === $slots[2] || $slots[0] === $slots[2]) {
            $multiplier = $this->rewards['two_same'];
        }

        $winAmount = $bet * $multiplier;
        if ($winAmount > 0) {
            $economy->addMoney($player, $winAmount);
        }

        $player->sendMessage("§l§6 SLOT MACHINE");
        $player->sendMessage("§r| " . $slots[0] . " | " . $slots[1] . " | " . $slots[2] . " |");

        if ($multiplier > 0) {
            $player->sendMessage("§a Tu as gagné " . number_format($winAmount) . "$ !");
            if ($multiplier >= $this->bigWinMultiplier) {
                $this->plugin->getServer()->broadcastMessage("§6§l" . $player->getName() . " a gagné x" . $multiplier . " (" . number_format($winAmount) . "$) à la machine à sous !");
            }
        } else {
            $player->sendMessage("§cTu as perdu ta mise de " . number_format($bet) . "$");
        }
    }
}