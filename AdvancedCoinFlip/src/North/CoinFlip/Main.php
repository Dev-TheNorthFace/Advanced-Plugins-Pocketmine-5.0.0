<?php

namespace North\CoinFlip\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\sound\PopSound;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;

class Main extends PluginBase {

    private $config;
    private $stats;
    private $jackpot = 0;
    private $cooldowns = [];
    private $duels = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->stats = new Config($this->getDataFolder() . "stats.yml", Config::YAML, []);

        $this->jackpot = $this->config->get("jackpot", 0);

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                $this->plugin->saveData();
            }
        }, 12000);
    }

    public function onDisable(): void {
        $this->saveData();
    }

    private function saveData() {
        $this->config->set("jackpot", $this->jackpot);
        $this->config->save();
        $this->stats->save();
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage("§cCommande uniquement utilisable en jeu.");
            return true;
        }

        if($cmd->getName() === "coinflip") {
            if(isset($this->cooldowns[$sender->getName()]) {
                if(time() - $this->cooldowns[$sender->getName()] < 5) {
                    $sender->sendMessage("§cAttendez 5 secondes entre chaque coinflip.");
                    return true;
                }
            }

            if(count($args) < 1) {
                $sender->sendMessage("§cUsage: /coinflip <montant> [pile|face]");
                return true;
            }

            $amount = (int)$args[0];
            if($amount <= 0) {
                $sender->sendMessage("§cLe montant doit être supérieur à 0.");
                return true;
            }

            $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            if(!$economy) {
                $sender->sendMessage("§cLe plugin EconomyAPI n'est pas installé.");
                return true;
            }

            if($economy->myMoney($sender) < $amount) {
                $sender->sendMessage("§cVous n'avez pas assez d'argent.");
                return true;
            }

            $choice = strtolower($args[1] ?? "");
            if($choice !== "" && $choice !== "pile" && $choice !== "face") {
                $sender->sendMessage("§cChoix invalide. Utilisez 'pile' ou 'face'.");
                return true;
            }

            $economy->reduceMoney($sender, $amount);
            $this->updateStats($sender->getName(), "played", 1);
            $this->updateStats($sender->getName(), "wagered", $amount);

            $jackpotContribution = (int)($amount * 0.05);
            $this->jackpot += $jackpotContribution;
            $amount -= $jackpotContribution;

            $this->cooldowns[$sender->getName()] = time();
            $this->startCoinFlipAnimation($sender, $amount, $choice);
        }
        elseif($cmd->getName() === "coinflipstats") {
            $this->showStats($sender);
        }
        elseif($cmd->getName() === "coinfliptop") {
            $this->showLeaderboard($sender);
        }
        return true;
    }

    private function startCoinFlipAnimation(Player $player, int $amount, string $choice = "") {
        $player->sendTitle("§e§lCOIN FLIP!", "§7En cours...");

        $world = $player->getWorld();
        $pos = $player->getPosition();

        for($i = 0; $i < 10; $i++) {
            $this->getScheduler()->scheduleDelayedTask(new class($world, $pos) extends Task {
                private $world;
                private $pos;

                public function __construct($world, $pos) {
                    $this->world = $world;
                    $this->pos = $pos;
                }

                public function onRun(): void {
                    $this->world->addParticle($this->pos, new FlameParticle());
                    $this->world->addSound($this->pos, new PopSound());
                }
            }, $i * 5);
        }

        $this->getScheduler()->scheduleDelayedTask(new class($this, $player, $amount, $choice) extends Task {
            private $plugin;
            private $player;
            private $amount;
            private $choice;

            public function __construct(Main $plugin, Player $player, int $amount, string $choice) {
                $this->plugin = $plugin;
                $this->player = $player;
                $this->amount = $amount;
                $this->choice = $choice;
            }

            public function onRun(): void {
                $result = mt_rand(0, 1) ? "pile" : "face";
                $won = ($this->choice === "" || $this->choice === $result);

                $economy = $this->plugin->getServer()->getPluginManager()->getPlugin("EconomyAPI");
                if($economy) {
                    if($won) {
                        $winAmount = $this->amount * 1.9;
                        $economy->addMoney($this->player, $winAmount);
                        $this->player->sendTitle("§a§l" . strtoupper($result) . "!", "§a+$winAmount");
                        $this->plugin->updateStats($this->player->getName(), "wins", 1);
                        $this->plugin->updateStats($this->player->getName(), "profit", $winAmount - $this->amount);
                    } else {
                        $this->player->sendTitle("§c§l" . strtoupper($result) . "!", "§c-$this->amount");
                        $this->plugin->updateStats($this->player->getName(), "losses", 1);
                        $this->plugin->updateStats($this->player->getName(), "profit", -$this->amount);
                    }
                }
            }
        }, 60);
    }

    private function updateStats(string $player, string $stat, $value) {
        $stats = $this->stats->get($player, []);
        $stats[$stat] = ($stats[$stat] ?? 0) + $value;
        $this->stats->set($player, $stats);
    }

    private function showStats(Player $player) {
        $stats = $this->stats->get($player->getName(), []);

        $message = [
            "§6§lVos stats CoinFlip:",
            "§eParties jouées: §f" . ($stats["played"] ?? 0),
            "§eVictoires: §a" . ($stats["wins"] ?? 0),
            "§eDéfaites: §c" . ($stats["losses"] ?? 0),
            "§eTotal misé: §6" . ($stats["wagered"] ?? 0) . "$",
            "§eProfit total: §" . (($stats["profit"] ?? 0) >= 0 ? "a+" : "c") . ($stats["profit"] ?? 0) . "$",
            "§eJackpot actuel: §6" . $this->jackpot . "$"
        ];

        $player->sendMessage(implode("\n", $message));
    }

    private function showLeaderboard(Player $player) {
        $allStats = $this->stats->getAll();
        $topWinners = [];
        $topWagered = [];

        foreach($allStats as $name => $stats) {
            $topWinners[$name] = $stats["profit"] ?? 0;
            $topWagered[$name] = $stats["wagered"] ?? 0;
        }

        arsort($topWinners);
        arsort($topWagered);

        $message = ["§6§lClassement CoinFlip:"];
        $message[] = "§eTop gagnants:";

        $i = 0;
        foreach(array_slice($topWinners, 0, 5) as $name => $profit) {
            $i++;
            $message[] = "§f$i. §a$name: §f" . ($profit >= 0 ? "+" : "") . "$profit$";
        }

        $message[] = "§eTop mises:";
        $i = 0;
        foreach(array_slice($topWagered, 0, 5) as $name => $amount) {
            $i++;
            $message[] = "§f$i. §6$name: §f$amount$";
        }

        $player->sendMessage(implode("\n", $message));
    }
}