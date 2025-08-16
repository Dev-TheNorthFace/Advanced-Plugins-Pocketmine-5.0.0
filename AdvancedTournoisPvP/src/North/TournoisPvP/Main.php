<?php

namespace North\TournoisPvP\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\scheduler\Task;
use pocketmine\item\Item;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;

class Main extends PluginBase implements Listener {

    private $config;
    private $arenas = [];
    private $players = [];
    private $teams = [];
    private $matches = [];
    private $status = "idle";
    private $type = "1v1";
    private $countdown = 60;
    private $rewardMoney = 500;
    private $rewardElo = 100;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->loadArenas();
    }

    private function loadArenas() {
        $worlds = $this->getServer()->getWorldManager()->getWorlds();
        foreach($worlds as $world) {
            if(strpos($world->getFolderName(), "arena") !== false) {
                $this->arenas[] = $world->getFolderName();
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
        if($cmd->getName() === "tournament") {
            if(!isset($args[0])) {
                $sender->sendMessage("§a[🏆] Commands: /tournament join|leave|start|stop|setarena");
                return true;
            }

            $sub = strtolower($args[0]);

            switch($sub) {
                case "join":
                    $this->joinTournament($sender);
                    break;
                case "leave":
                    $this->leaveTournament($sender);
                    break;
                case "start":
                    $this->startTournament($sender);
                    break;
                case "stop":
                    $this->stopTournament($sender);
                    break;
                case "setarena":
                    $this->setArena($sender);
                    break;
                default:
                    $sender->sendMessage("§cUnknown tournament command");
            }
            return true;
        }
        return false;
    }

    private function joinTournament(Player $player) {
        if($this->status !== "waiting") {
            $player->sendMessage("§cNo tournament available to join");
            return;
        }

        if(isset($this->players[$player->getName()])) {
            $player->sendMessage("§cYou already joined the tournament");
            return;
        }

        $this->players[$player->getName()] = $player;
        $player->sendMessage("§a[🏆] You joined the tournament!");
        $this->getServer()->broadcastMessage("§a[🏆] " . $player->getName() . " joined the tournament! (" . count($this->players) . "/" . $this->getMaxPlayers() . ")");

        if(count($this->players) >= $this->getMaxPlayers()) {
            $this->startCountdown(10);
        }
    }

    private function leaveTournament(Player $player) {
        if(!isset($this->players[$player->getName()])) {
            $player->sendMessage("§cYou didn't join the tournament");
            return;
        }

        unset($this->players[$player->getName()]);
        $player->sendMessage("§a[🏆] You left the tournament");
    }

    private function startTournament(CommandSender $sender) {
        if($this->status !== "idle") {
            $sender->sendMessage("§cTournament already running");
            return;
        }

        $this->status = "waiting";
        $this->players = [];
        $this->getServer()->broadcastMessage("§a[🏆] Tournament " . $this->type . " started! Type /tournament join to participate!");
        $this->startCountdown($this->countdown);
    }

    private function stopTournament(CommandSender $sender) {
        if($this->status === "idle") {
            $sender->sendMessage("§cNo tournament running");
            return;
        }

        $this->status = "idle";
        $this->getServer()->broadcastMessage("§c[🏆] Tournament stopped by admin");

        foreach($this->players as $player) {
            $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        }

        $this->players = [];
        $this->teams = [];
        $this->matches = [];
    }

    private function setArena(Player $player) {
        $world = $player->getWorld()->getFolderName();
        if(in_array($world, $this->arenas)) {
            $player->sendMessage("§cThis arena is already registered");
            return;
        }

        $this->arenas[] = $world;
        $player->sendMessage("§aArena " . $world . " added to tournament system");
    }

    private function startCountdown($time) {
        $this->getScheduler()->scheduleRepeatingTask(new class($this, $time) extends Task {
            private $plugin;
            private $time;

            public function __construct(Main $plugin, $time) {
                $this->plugin = $plugin;
                $this->time = $time;
            }

            public function onRun(): void {
                if($this->time <= 0) {
                    $this->plugin->beginTournament();
                    $this->getHandler()->cancel();
                    return;
                }

                if($this->time % 10 === 0 || $this->time <= 5) {
                    $this->plugin->getServer()->broadcastMessage("§a[⌛] Tournament starts in " . $this->time . " seconds!");
                }

                $this->time--;
            }
        }, 20);
    }

    private function beginTournament() {
        if(count($this->players) < 2) {
            $this->getServer()->broadcastMessage("§c[🏆] Not enough players to start tournament");
            $this->status = "idle";
            return;
        }

        $this->status = "running";
        $this->getServer()->broadcastMessage("§a[⚔️] Tournament started with " . count($this->players) . " players!");

        $this->setupTeams();
        $this->startMatches();
    }

    private function setupTeams() {
        $players = array_values($this->players);
        shuffle($players);

        if($this->type === "1v1") {
            foreach($players as $player) {
                $this->teams[] = [$player];
            }
        } elseif($this->type === "2v2") {
            for($i = 0; $i < count($players); $i += 2) {
                if(isset($players[$i+1])) {
                    $this->teams[] = [$players[$i], $players[$i+1]];
                }
            }
        } elseif($this->type === "3v3") {
            for($i = 0; $i < count($players); $i += 3) {
                if(isset($players[$i+2])) {
                    $this->teams[] = [$players[$i], $players[$i+1], $players[$i+2]];
                }
            }
        }
    }

    private function startMatches() {
        if(empty($this->arenas)) {
            $this->getServer()->broadcastMessage("§cNo arenas available for tournament");
            $this->stopTournament($this->getServer()->getConsoleSender());
            return;
        }

        $arenaIndex = 0;
        $arenaCount = count($this->arenas);

        for($i = 0; $i < count($this->teams); $i += 2) {
            if(isset($this->teams[$i+1])) {
                $arena = $this->arenas[$arenaIndex % $arenaCount];
                $this->matches[] = [
                    'team1' => $this->teams[$i],
                    'team2' => $this->teams[$i+1],
                    'arena' => $arena,
                    'winner' => null
                ];

                $team1Names = array_map(function($p) { return $p->getName(); }, $this->teams[$i]);
                $team2Names = array_map(function($p) { return $p->getName(); }, $this->teams[$i+1]);

                $this->getServer()->broadcastMessage("§a[⚔️] Match: " . implode(", ", $team1Names) . " vs " . implode(", ", $team2Names) . " in " . $arena);

                $arenaIndex++;

                $world = $this->getServer()->getWorldManager()->getWorldByName($arena);
                $spawn = $world->getSpawnLocation();

                foreach($this->teams[$i] as $player) {
                    $player->teleport($spawn->add(5, 0, 0));
                    $this->giveKit($player);
                }

                foreach($this->teams[$i+1] as $player) {
                    $player->teleport($spawn->add(-5, 0, 0));
                    $this->giveKit($player);
                }
            }
        }
    }

    private function giveKit(Player $player) {
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->setHealth(20);
        $player->getHungerManager()->setFood(20);

        $sword = Item::get(Item::DIAMOND_SWORD);
        $helmet = Item::get(Item::DIAMOND_HELMET);
        $chestplate = Item::get(Item::DIAMOND_CHESTPLATE);
        $leggings = Item::get(Item::DIAMOND_LEGGINGS);
        $boots = Item::get(Item::DIAMOND_BOOTS);

        $player->getInventory()->addItem($sword);
        $player->getArmorInventory()->setHelmet($helmet);
        $player->getArmorInventory()->setChestplate($chestplate);
        $player->getArmorInventory()->setLeggings($leggings);
        $player->getArmorInventory()->setBoots($boots);
    }

    private function getMaxPlayers() {
        switch($this->type) {
            case "1v1": return 16;
            case "2v2": return 16;
            case "3v3": return 18;
            default: return 16;
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();

        if($this->status !== "running") return;

        foreach($this->matches as &$match) {
            $allPlayers = array_merge($match['team1'], $match['team2']);

            if(in_array($player, $allPlayers, true)) {
                $aliveTeam1 = array_filter($match['team1'], function($p) { return $p->isAlive(); });
                $aliveTeam2 = array_filter($match['team2'], function($p) { return $p->isAlive(); });

                if(empty($aliveTeam1)) {
                    $this->endMatch($match, $match['team2']);
                    break;
                } elseif(empty($aliveTeam2)) {
                    $this->endMatch($match, $match['team1']);
                    break;
                }
            }
        }
    }

    private function endMatch($match, $winnerTeam) {
        $winnerNames = array_map(function($p) { return $p->getName(); }, $winnerTeam);
        $this->getServer()->broadcastMessage("§a[✅] Team " . implode(", ", $winnerNames) . " wins the match!");

        foreach($match['team1'] as $player) {
            $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        }

        foreach($match['team2'] as $player) {
            $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        }

        $match['winner'] = $winnerTeam;

        $this->checkTournamentEnd();
    }

    private function checkTournamentEnd() {
        $remainingMatches = array_filter($this->matches, function($match) { return $match['winner'] === null; });

        if(empty($remainingMatches)) {
            $this->endTournament();
        }
    }

    private function endTournament() {
        $winners = [];

        foreach($this->matches as $match) {
            if($match['winner'] !== null) {
                foreach($match['winner'] as $player) {
                    $winners[] = $player->getName();
                }
            }
        }

        if(!empty($winners)) {
            $this->getServer()->broadcastMessage("§a[🎉] Tournament winners: " . implode(", ", $winners) . "!");
            $this->giveRewards($winners);
        } else {
            $this->getServer()->broadcastMessage("§c[🏆] Tournament ended with no winners");
        }

        $this->status = "idle";
        $this->players = [];
        $this->teams = [];
        $this->matches = [];
    }

    private function giveRewards($winners) {
        foreach($winners as $winnerName) {
            $player = $this->getServer()->getPlayerExact($winnerName);
            if($player !== null) {
                $player->sendMessage("§aYou received $" . $this->rewardMoney . " and " . $this->rewardElo . " Elo points!");
            }
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event) {
        $player = $event->getPlayer();

        if(isset($this->players[$player->getName()])) {
            unset($this->players[$player->getName()]);

            if($this->status === "running") {
                $this->getServer()->broadcastMessage("§c" . $player->getName() . " left during tournament!");
            }
        }
    }

    public function onDamage(EntityDamageEvent $event) {
        if($this->status !== "running") return;

        $entity = $event->getEntity();
        if(!$entity instanceof Player) return;

        foreach($this->matches as $match) {
            $allPlayers = array_merge($match['team1'], $match['team2']);

            if(in_array($entity, $allPlayers, true)) {
                if($event instanceof EntityDamageByEntityEvent) {
                    $damager = $event->getDamager();
                    if($damager instanceof Player && in_array($damager, $allPlayers, true)) {
                        return;
                    }
                }
                $event->cancel();
                return;
            }
        }
    }
}