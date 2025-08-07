<?php

declare(strict_types=1);

namespace North\Scratch\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

    private Config $data;
    private array $symbols = [
        "🍀" => ["name" => "Chance", "probability" => 25, "reward" => ["money" => 500]],
        "🔧" => ["name" => "Outil", "probability" => 20, "reward" => ["item" => "iron_pickaxe"]],
        "🧱" => ["name" => "Bloc", "probability" => 20, "reward" => ["blocks" => ["stone" => 64]]],
        "💣" => ["name" => "Explosion", "probability" => 15, "reward" => ["nothing" => true]],
        "💎" => ["name" => "Diamant", "probability" => 10, "reward" => ["money" => 2000]],
        "🧨" => ["name" => "Mythique", "probability" => 5, "reward" => ["money" => 5000, "item" => "diamond_sword"]],
        "🧬" => ["name" => "Légendaire", "probability" => 2, "reward" => ["money" => 10000, "item" => "netherite_sword"]]
    ];
    private array $ticketTypes = [
        "basic" => ["price" => 1000, "display" => "§fBasic Ticket"],
        "epic" => ["price" => 5000, "display" => "§dEpic Ticket"],
        "legendary" => ["price" => 25000, "display" => "§6Legendary Ticket"]
    ];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) return false;

        switch(strtolower($command->getName())) {
            case "scratch":
                if(empty($args)) {
                    $sender->sendMessage("§aUsage: /scratch <use|buy|stats>");
                    return true;
                }

                switch(strtolower($args[0])) {
                    case "use":
                        $this->useTicket($sender);
                        break;
                    case "buy":
                        if(empty($args[1])) {
                            $sender->sendMessage("§aAvailable tickets:");
                            foreach($this->ticketTypes as $type => $data) {
                                $sender->sendMessage("§7- /scratch buy $type §f(${$data['price']}$)");
                            }
                            return true;
                        }
                        $this->buyTicket($sender, strtolower($args[1]));
                        break;
                    case "stats":
                        $this->showStats($sender);
                        break;
                    default:
                        $sender->sendMessage("§cUnknown subcommand. Use /scratch <use|buy|stats>");
                }
                return true;
        }
        return false;
    }

    private function useTicket(Player $player): void {
        $tickets = $this->data->getNested($player->getName() . ".tickets", ["basic" => 0, "epic" => 0, "legendary" => 0]);
        $ticketType = null;

        if($tickets["legendary"] > 0) {
            $ticketType = "legendary";
        } elseif($tickets["epic"] > 0) {
            $ticketType = "epic";
        } elseif($tickets["basic"] > 0) {
            $ticketType = "basic";
        }

        if($ticketType === null) {
            $player->sendMessage("§cYou don't have any scratch tickets!");
            return;
        }

        $tickets[$ticketType]--;
        $this->data->setNested($player->getName() . ".tickets", $tickets);
        $this->data->save();

        $results = [];
        for($i = 0; $i < 3; $i++) {
            $results[] = $this->getRandomSymbol($ticketType);
        }

        $player->sendMessage("§a§l🎟icket à gratterr️");
        $player->sendMessage("§7-------------------------");
        $player->sendMessage("§7| " . implode(" | ", $results) . " |");
        $player->sendMessage("");

        if(count(array_unique($results)) === 1) {
            $symbol = $results[0];
            $reward = $this->symbols[$symbol]["reward"];
            $this->giveReward($player, $reward, $symbol);

            if(in_array($symbol, ["💎", "🧨", "🧬"])) {
                $this->getServer()->broadcastMessage("§6§lJACKPOT");
                $this->getServer()->broadcastMessage("§7| " . implode(" | ", $results) . " |");
                $this->getServer()->broadcastMessage("§a" . $player->getName() . " won big with a " . $this->symbols[$symbol]["name"] . " prize!");
            }
        } else {
            $player->sendMessage("§cTu n'as rien gagné cette fois...");
        }

        $stats = $this->data->getNested($player->getName() . ".stats", ["used" => 0, "wins" => 0]);
        $stats["used"]++;
        if(count(array_unique($results)) === 1) $stats["wins"]++;
        $this->data->setNested($player->getName() . ".stats", $stats);
        $this->data->save();
    }

    private function getRandomSymbol(string $ticketType): string {
        $totalProbability = 0;
        $modifiedProbabilities = [];

        foreach($this->symbols as $symbol => $data) {
            $baseProb = $data["probability"];
            $multiplier = 1;

            if($ticketType === "epic") $multiplier = 1.5;
            elseif($ticketType === "legendary") $multiplier = 2;

            if(in_array($symbol, ["💎", "🧨", "🧬"])) {
                $modifiedProb = $baseProb * $multiplier;
            } else {
                $modifiedProb = $baseProb;
            }

            $modifiedProbabilities[$symbol] = $modifiedProb;
            $totalProbability += $modifiedProb;
        }

        $rand = mt_rand(1, $totalProbability);
        $current = 0;

        foreach($modifiedProbabilities as $symbol => $probability) {
            $current += $probability;
            if($rand <= $current) {
                return $symbol;
            }
        }

        return "🍀";
    }

    private function giveReward(Player $player, array $reward, string $symbol): void {
        $player->sendMessage("§a§lYOU WIN!");
        $player->sendMessage("§aSymbol: " . $symbol . " " . $this->symbols[$symbol]["name"]);
        $player->sendMessage("§aRewards:");

        if(isset($reward["money"])) {
            $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            if($economy !== null) {
                $economy->addMoney($player, $reward["money"]);
                $player->sendMessage("§7- $" . $reward["money"]);
            }
        }

        if(isset($reward["item"])) {
            $item = ItemFactory::getInstance()->get($reward["item"]);
            if($player->getInventory()->canAddItem($item)) {
                $player->getInventory()->addItem($item);
                $player->sendMessage("§7- " . $item->getName());
            }
        }

        if(isset($reward["blocks"])) {
            foreach($reward["blocks"] as $block => $count) {
                $blockItem = ItemFactory::getInstance()->get($block, 0, $count);
                if($player->getInventory()->canAddItem($blockItem)) {
                    $player->getInventory()->addItem($blockItem);
                    $player->sendMessage("§7- " . $count . "x " . $blockItem->getName());
                }
            }
        }

        if(isset($reward["nothing"])) {
            $player->sendMessage("§7- Nothing... Better luck next time!");
        }
    }

    private function buyTicket(Player $player, string $type): void {
        if(!isset($this->ticketTypes[$type])) {
            $player->sendMessage("§cInvalid ticket type. Available: basic, epic, legendary");
            return;
        }

        $price = $this->ticketTypes[$type]["price"];
        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");

        if($economy === null || $economy->getMoney($player) < $price) {
            $player->sendMessage("§cYou don't have enough money to buy this ticket!");
            return;
        }

        $economy->reduceMoney($player, $price);
        $tickets = $this->data->getNested($player->getName() . ".tickets", ["basic" => 0, "epic" => 0, "legendary" => 0]);
        $tickets[$type]++;
        $this->data->setNested($player->getName() . ".tickets", $tickets);
        $this->data->save();

        $player->sendMessage("§aYou bought a " . $this->ticketTypes[$type]["display"] . "§a for $" . $price);
    }

    private function showStats(Player $player): void {
        $stats = $this->data->getNested($player->getName() . ".stats", ["used" => 0, "wins" => 0]);
        $tickets = $this->data->getNested($player->getName() . ".tickets", ["basic" => 0, "epic" => 0, "legendary" => 0]);

        $player->sendMessage("§6§lYour Scratch Ticket Stats");
        $player->sendMessage("§7Tickets used: §f" . $stats["used"]);
        $player->sendMessage("§7Wins: §f" . $stats["wins"]);

        if($stats["used"] > 0) {
            $winRate = round(($stats["wins"] / $stats["used"]) * 100, 2);
            $player->sendMessage("§7Win rate: §f" . $winRate . "%");
        }

        $player->sendMessage("");
        $player->sendMessage("§7Available tickets:");
        $player->sendMessage("§7- Basic: §f" . $tickets["basic"]);
        $player->sendMessage("§7- Epic: §f" . $tickets["epic"]);
        $player->sendMessage("§7- Legendary: §f" . $tickets["legendary"]);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if(!$this->data->exists($player->getName())) {
            $this->data->setNested($player->getName() . ".tickets", ["basic" => 1, "epic" => 0, "legendary" => 0]);
            $this->data->setNested($player->getName() . ".stats", ["used" => 0, "wins" => 0]);
            $this->data->save();
        }
    }
}