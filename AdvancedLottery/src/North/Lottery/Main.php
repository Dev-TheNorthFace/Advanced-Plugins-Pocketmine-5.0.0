<?php

declare(strict_types=1);

namespace North\Lottery\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

    private Config $data;
    private int $pot = 0;
    private int $timer = 1800;
    private array $tickets = [];
    private array $winners = [];
    private array $leaderboard = [];
    private int $ticketPrice = 500;
    private int $maxTicketsPerPlayer = 100;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML);

        $this->pot = $this->data->get("pot", 0);
        $this->tickets = $this->data->get("tickets", []);
        $this->winners = $this->data->get("winners", []);
        $this->leaderboard = $this->data->get("leaderboard", []);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->timer--;

            if($this->timer === 600) {
                $this->getServer()->broadcastMessage(TextFormat::GOLD . "Le tirage de la loterie a lieu dans 10 minutes! Achetez vos tickets avec /lottery buy!");
            }

            if($this->timer <= 0) {
                $this->drawLottery();
                $this->timer = 1800;
            }
        }), 20);
    }

    protected function onDisable(): void {
        $this->data->set("pot", $this->pot);
        $this->data->set("tickets", $this->tickets);
        $this->data->set("winners", $this->winners);
        $this->data->set("leaderboard", $this->leaderboard);
        $this->data->save();
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if(!isset($this->leaderboard[$player->getName()])) {
            $this->leaderboard[$player->getName()] = 0;
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) return false;

        switch(strtolower($args[0] ?? "")) {
            case "buy":
                $this->buyTicket($sender);
                break;

            case "time":
                $minutes = (int)($this->timer / 60);
                $sender->sendMessage(TextFormat::GREEN . "Prochain tirage dans $minutes minutes");
                break;

            case "pot":
                $sender->sendMessage(TextFormat::GOLD . "Pot actuel: " . $this->pot . "$");
                break;

            case "top":
                $this->showLeaderboard($sender);
                break;

            case "history":
                $this->showHistory($sender);
                break;

            default:
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /lottery <buy|time|pot|top|history>");
                break;
        }
        return true;
    }

    private function buyTicket(Player $player): void {
        $name = $player->getName();
        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");

        if($economy === null) {
            $player->sendMessage(TextFormat::RED . "Le système économique n'est pas disponible");
            return;
        }

        $ticketsCount = $this->tickets[$name] ?? 0;

        if($ticketsCount >= $this->maxTicketsPerPlayer) {
            $player->sendMessage(TextFormat::RED . "Vous avez atteint la limite de tickets (".$this->maxTicketsPerPlayer.")");
            return;
        }

        if(!$economy->reduceMoney($player, $this->ticketPrice)) {
            $player->sendMessage(TextFormat::RED . "Vous n'avez pas assez d'argent (Prix: ".$this->ticketPrice."$)");
            return;
        }

        $this->tickets[$name] = ($this->tickets[$name] ?? 0) + 1;
        $this->pot += $this->ticketPrice;

        $chance = $this->calculateChance($name);
        $player->sendMessage(TextFormat::GREEN . "Vous avez acheté un ticket! (Chance: $chance%)");
        $player->sendMessage(TextFormat::GOLD . "Pot actuel: " . $this->pot . "$");
    }

    private function calculateChance(string $playerName): float {
        if(empty($this->tickets)) return 0;

        $playerTickets = $this->tickets[$playerName] ?? 0;
        $totalTickets = array_sum($this->tickets);

        return round(($playerTickets / $totalTickets) * 100, 2);
    }

    private function drawLottery(): void {
        if(empty($this->tickets)) {
            $this->getServer()->broadcastMessage(TextFormat::YELLOW . "Aucun ticket acheté pour ce tirage");
            $this->timer = 1800;
            return;
        }

        $winnerName = $this->selectWinner();
        $winner = $this->getServer()->getPlayerExact($winnerName);

        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if($economy !== null) {
            $economy->addMoney($winnerName, $this->pot);
        }

        $this->leaderboard[$winnerName] = ($this->leaderboard[$winnerName] ?? 0) + 1;
        $this->winners[] = ["name" => $winnerName, "amount" => $this->pot, "time" => time()];

        $this->getServer()->broadcastMessage(TextFormat::GOLD . "La loterie a été tirée!");
        $this->getServer()->broadcastMessage(TextFormat::GOLD . "Gagnant: " . $winnerName);
        $this->getServer()->broadcastMessage(TextFormat::GOLD . "Gain: " . $this->pot . "$");
        $this->getServer()->broadcastMessage(TextFormat::GOLD . "Prochain tirage dans 30 minutes!");

        if($winner instanceof Player) {
            $winner->sendMessage(TextFormat::GREEN . "Vous avez gagné la loterie! Montant: " . $this->pot . "$");
        }

        $this->pot = 0;
        $this->tickets = [];
        $this->timer = 1800;
    }

    private function selectWinner(): string {
        $totalTickets = array_sum($this->tickets);
        $random = mt_rand(1, $totalTickets);
        $current = 0;

        foreach($this->tickets as $name => $count) {
            $current += $count;
            if($random <= $current) {
                return $name;
            }
        }

        return array_key_first($this->tickets);
    }

    private function showLeaderboard(Player $player): void {
        arsort($this->leaderboard);
        $top = array_slice($this->leaderboard, 0, 10, true);

        $player->sendMessage(TextFormat::GOLD . "Classement des gagnants:");
        $position = 1;
        foreach($top as $name => $wins) {
            $player->sendMessage(TextFormat::YELLOW . "$position. $name: $wins victoires");
            $position++;
        }
    }

    private function showHistory(Player $player): void {
        $history = array_slice(array_reverse($this->winners), 0, 5);

        $player->sendMessage(TextFormat::GOLD . "Derniers gagnants:");
        foreach($history as $entry) {
            $time = date("d/m H:i", $entry["time"]);
            $player->sendMessage(TextFormat::YELLOW . "$time: " . $entry["name"] . " - " . $entry["amount"] . "$");
        }
    }
}