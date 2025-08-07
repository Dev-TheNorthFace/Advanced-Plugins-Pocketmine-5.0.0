<?php

declare(strict_types=1);

namespace North\JackPot\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\sound\PopSound;

class Main extends PluginBase implements Listener {

    private array $participants = [];
    private int $totalPot = 0;
    private int $timer = 300;
    private bool $isRunning = false;
    private Config $data;
    private array $config = [
        "min_bet" => 100,
        "max_bet" => 10000,
        "round_duration" => 300,
        "tax_percentage" => 5,
        "enable_effects" => true,
        "announce_winner" => true
    ];

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = array_merge($this->config, $this->getConfig()->getAll());
        $this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML, ["winners" => []]);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "This command can only be used in-game!");
            return true;
        }

        if (count($args) !== 1 || !is_numeric($args[0])) {
            $sender->sendMessage(TextFormat::RED . "Usage: /jackpot <amount>");
            return true;
        }

        $amount = (int)$args[0];

        if ($amount < $this->config["min_bet"]) {
            $sender->sendMessage(TextFormat::RED . "Minimum bet is " . $this->config["min_bet"] . "$");
            return true;
        }

        if ($amount > $this->config["max_bet"]) {
            $sender->sendMessage(TextFormat::RED . "Maximum bet is " . $this->config["max_bet"] . "$");
            return true;
        }

        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if ($economy === null) {
            $sender->sendMessage(TextFormat::RED . "Economy plugin not found!");
            return true;
        }

        if ($economy->myMoney($sender) < $amount) {
            $sender->sendMessage(TextFormat::RED . "You don't have enough money!");
            return true;
        }

        $economy->reduceMoney($sender, $amount);

        $this->participants[$sender->getName()] = $amount;
        $this->totalPot += $amount;

        $this->broadcastMessage(TextFormat::GREEN . $sender->getName() . " entered the jackpot with " . $amount . "$. Total: " . $this->totalPot . "$");

        if (!$this->isRunning) {
            $this->isRunning = true;
            $this->timer = $this->config["round_duration"];
            $this->scheduleTimer();
        }

        return true;
    }

    private function scheduleTimer(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->timer--;

            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                $player->sendActionBarMessage(
                    TextFormat::GOLD . "Jackpot: " . $this->totalPot . "$" .
                    TextFormat::WHITE . " | " .
                    TextFormat::AQUA . "Participants: " . count($this->participants) .
                    TextFormat::WHITE . " | " .
                    TextFormat::GREEN . "Draw in: " . $this->formatTime($this->timer)
                );
            }

            if ($this->timer <= 0) {
                $this->drawWinner();
            }
        }), 20);
    }

    private function drawWinner(): void {
        $totalChances = array_sum($this->participants);
        $random = mt_rand(1, $totalChances);
        $current = 0;
        $winner = null;

        foreach ($this->participants as $name => $amount) {
            $current += $amount;
            if ($random <= $current) {
                $winner = $name;
                break;
            }
        }

        if ($winner === null) {
            $this->getLogger()->warning("No winner found in jackpot draw!");
            $this->resetJackpot();
            return;
        }

        $tax = (int)($this->totalPot * ($this->config["tax_percentage"] / 100));
        $prize = $this->totalPot - $tax;

        $player = $this->getServer()->getPlayerExact($winner);
        if ($player !== null) {
            $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            $economy->addMoney($player, $prize);

            if ($this->config["enable_effects"]) {
                $this->playWinEffects($player);
            }

            $player->sendTitle(TextFormat::GOLD . "YOU WON!", TextFormat::GREEN . $prize . "$", 20, 60, 20);
        }

        $winners = $this->data->get("winners", []);
        $winners[$winner] = ($winners[$winner] ?? 0) + $prize;
        $this->data->set("winners", $winners);
        $this->data->save();

        $this->broadcastMessage(
            TextFormat::GOLD . "DRAW COMPLETE!" .
            TextFormat::WHITE . " Winner: " . TextFormat::GREEN . $winner .
            TextFormat::WHITE . " with " . TextFormat::YELLOW . $this->participants[$winner] . "$" .
            TextFormat::WHITE . " bet! Prize: " . TextFormat::GREEN . $prize . "$"
        );

        $this->resetJackpot();
    }

    private function playWinEffects(Player $player): void {
        $world = $player->getWorld();
        $pos = $player->getPosition();

        for ($i = 0; $i < 50; $i++) {
            $world->addParticle($pos, new FlameParticle());
        }

        $world->addSound($pos, new ExplodeSound());
        $world->addSound($pos, new PopSound());
    }

    private function resetJackpot(): void {
        $this->participants = [];
        $this->totalPot = 0;
        $this->timer = $this->config["round_duration"];
        $this->isRunning = false;
    }

    private function broadcastMessage(string $message): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $player->sendMessage($message);
        }
        $this->getLogger()->info($message);
    }

    private function formatTime(int $seconds): string {
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        return sprintf("%02d:%02d", $minutes, $seconds);
    }
}