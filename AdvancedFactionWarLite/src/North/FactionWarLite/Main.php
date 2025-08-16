<?php

namespace North\FactionWarLite\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;
use pocketmine\math\Vector3;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;

class Main extends PluginBase implements Listener {

    private $teams = [];
    private $cores = [];
    private $gameTime = 1200;
    private $isGameRunning = false;
    private $scoreboard = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->initTeams();
        $this->initWorld();
        $this->getScheduler()->scheduleRepeatingTask(new GameTask($this), 20);
    }

    private function initTeams() {
        $this->teams = [
            "red" => ["players" => [], "kills" => 0, "resources" => 0, "color" => "§c"],
            "blue" => ["players" => [], "kills" => 0, "resources" => 0, "color" => "§9"]
        ];

        $this->cores["red"] = new Position(10, 70, 10, $this->getServer()->getWorldManager()->getDefaultWorld());
        $this->cores["blue"] = new Position(90, 70, 90, $this->getServer()->getWorldManager()->getDefaultWorld());
    }

    private function initWorld() {
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        $world->setTime(6000);
        $world->stopTime();

        $this->placeCore("red");
        $this->placeCore("blue");
    }

    private function placeCore($team) {
        $pos = $this->cores[$team];
        $pos->getWorld()->setBlock($pos, Block::get(Block::BEDROCK));

        $beaconPos = $pos->add(0, 1, 0);
        $beaconPos->getWorld()->setBlock($beaconPos, Block::get(Block::BEACON));
    }

    public function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();

        if(!$this->isGameRunning) {
            $this->assignTeam($player);
            $this->giveStarterKit($player);
            $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        }
    }

    private function assignTeam(Player $player) {
        $redCount = count($this->teams["red"]["players"]);
        $blueCount = count($this->teams["blue"]["players"]);

        $team = ($redCount <= $blueCount) ? "red" : "blue";
        $this->teams[$team]["players"][$player->getName()] = $player;

        $player->setDisplayName($this->teams[$team]["color"] . $player->getName());
        $player->setNameTag($this->teams[$team]["color"] . $player->getName());
    }

    private function giveStarterKit(Player $player) {
        $player->getInventory()->clearAll();

        $items = [
            Item::get(Item::IRON_SWORD),
            Item::get(Item::IRON_PICKAXE),
            Item::get(Item::IRON_AXE),
            Item::get(Item::IRON_SHOVEL),
            Item::get(Item::IRON_HELMET),
            Item::get(Item::IRON_CHESTPLATE),
            Item::get(Item::IRON_LEGGINGS),
            Item::get(Item::IRON_BOOTS),
            Item::get(Item::STEAK, 0, 8)
        ];

        foreach($items as $item) {
            $player->getInventory()->addItem($item);
        }
    }

    public function onBreak(BlockBreakEvent $event) {
        if(!$this->isGameRunning) {
            $event->cancel();
            return;
        }

        $player = $event->getPlayer();
        $block = $event->getBlock();
        $team = $this->getPlayerTeam($player);

        foreach($this->cores as $t => $corePos) {
            if($block->getPosition()->equals($corePos)) {
                if($t !== $team) {
                    $this->endGame($team);
                } else {
                    $player->sendMessage("§cVous ne pouvez pas détruire votre propre cœur!");
                }
                $event->cancel();
                return;
            }
        }
    }

    public function onDamage(EntityDamageByEntityEvent $event) {
        $entity = $event->getEntity();
        $damager = $event->getDamager();

        if(!$entity instanceof Player || !$damager instanceof Player) return;

        $teamEntity = $this->getPlayerTeam($entity);
        $teamDamager = $this->getPlayerTeam($damager);

        if($teamEntity === $teamDamager) {
            $event->cancel();
        }
    }

    public function onDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();
        $cause = $player->getLastDamageCause();

        if($cause instanceof EntityDamageByEntityEvent) {
            $killer = $cause->getDamager();
            if($killer instanceof Player) {
                $team = $this->getPlayerTeam($killer);
                $this->teams[$team]["kills"]++;
            }
        }
    }

    private function getPlayerTeam(Player $player) {
        foreach($this->teams as $team => $data) {
            if(isset($data["players"][$player->getName()])) {
                return $team;
            }
        }
        return null;
    }

    public function updateGame() {
        if(!$this->isGameRunning) {
            if(count($this->teams["red"]["players"]) > 0 && count($this->teams["blue"]["players"]) > 0) {
                $this->startGame();
            }
            return;
        }

        $this->gameTime--;

        if($this->gameTime % 300 === 0) {
            $this->spawnResources();
        }

        if($this->gameTime <= 0) {
            $this->endGame();
        }

        $this->updateScoreboard();
    }

    private function startGame() {
        $this->isGameRunning = true;
        $this->gameTime = 1200;

        foreach($this->teams as $team => $data) {
            foreach($data["players"] as $player) {
                $player->sendTitle("§l" . strtoupper($team) . " TEAM", "§eProtégez votre cœur!", 20, 60, 20);
            }
        }
    }

    private function spawnResources() {
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        $center = new Position(50, 70, 50, $world);

        $items = [
            Item::get(Item::TNT, 0, 3),
            Item::get(Item::DIAMOND, 0, 2),
            Item::get(Item::IRON_INGOT, 0, 8),
            Item::get(Item::GOLDEN_APPLE, 0, 1)
        ];

        foreach($items as $item) {
            $world->dropItem($center->add(mt_rand(-5, 5), 0, mt_rand(-5, 5)), $item);
        }
    }

    private function endGame($winnerTeam = null) {
        $this->isGameRunning = false;

        if($winnerTeam) {
            $message = "§l§6" . strtoupper($winnerTeam) . " TEAM A GAGNÉ!";
        } else {
            $redPoints = $this->teams["red"]["kills"] + $this->teams["red"]["resources"];
            $bluePoints = $this->teams["blue"]["kills"] + $this->teams["blue"]["resources"];

            if($redPoints > $bluePoints) {
                $message = "§l§6RED TEAM A GAGNÉ PAR POINTS!";
            } elseif($bluePoints > $redPoints) {
                $message = "§l§6BLUE TEAM A GAGNÉ PAR POINTS!";
            } else {
                $message = "§l§6MATCH NUL!";
            }
        }

        $this->getServer()->broadcastTitle($message, "§eFin de la partie!", 20, 100, 20);

        $this->initTeams();
        $this->initWorld();
    }

    private function updateScoreboard() {
        foreach($this->teams as $team => $data) {
            foreach($data["players"] as $player) {
                $lines = [
                    "§6=== FACTION WAR ===",
                    "Temps: §e" . gmdate("i:s", $this->gameTime),
                    " ",
                    "§cRed Team:",
                    "Kills: §f" . $this->teams["red"]["kills"],
                    "Ressources: §f" . $this->teams["red"]["resources"],
                    " ",
                    "§9Blue Team:",
                    "Kills: §f" . $this->teams["blue"]["kills"],
                    "Ressources: §f" . $this->teams["blue"]["resources"]
                ];

                $this->sendScoreboard($player, "§l§6FACTION WAR", $lines);
            }
        }
    }

    private function sendScoreboard(Player $player, $title, $lines) {
    }
}

class GameTask extends Task {

    private $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->updateGame();
    }
}