<?php

declare(strict_types=1);

namespace North\OITC\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\utils\Config;
use pocketmine\world\WorldManager;
use pocketmine\world\World;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {

    private $scores = [];
    private $killstreaks = [];
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->getScheduler()->scheduleRepeatingTask(new GameTimer($this), 20);
    }

    public function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $this->giveStarterKit($player);
        $this->scores[$player->getName()] = 0;
        $this->killstreaks[$player->getName()] = 0;
    }

    public function onDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();
        $event->setDrops([]);
        $this->getScheduler()->scheduleDelayedTask(new RespawnTask($this, $player), 20);
        $this->updateScore($player, -1);
        $this->killstreaks[$player->getName()] = 0;
    }

    public function onDamage(EntityDamageEvent $event) {
        $entity = $event->getEntity();
        if($entity instanceof Player) {
            if($event instanceof EntityDamageByEntityEvent) {
                $damager = $event->getDamager();
                if($damager instanceof Player) {
                    if($event->getCause() === EntityDamageEvent::CAUSE_PROJECTILE) {
                        $event->setBaseDamage(1000);
                        $this->handleKill($damager, $entity, 2);
                        $damager->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::ARROW, 0, 1));
                    } else {
                        $event->setBaseDamage(6);
                        $this->handleKill($damager, $entity, 1);
                    }
                }
            }
        }
    }

    private function handleKill(Player $killer, Player $victim, int $points) {
        $this->updateScore($killer, $points);
        $this->killstreaks[$killer->getName()]++;
        $this->checkKillstreak($killer);
    }

    private function updateScore(Player $player, int $points) {
        $name = $player->getName();
        $this->scores[$name] += $points;
        $player->sendActionBarMessage("Score: " . $this->scores[$name]);
    }

    private function checkKillstreak(Player $player) {
        $ks = $this->killstreaks[$player->getName()];
        switch($ks) {
            case 3:
                $player->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 20*30, 0));
                break;
            case 5:
                $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::ARROW, 0, 1));
                break;
            case 10:
                $player->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 20*15, 1));
                break;
        }
    }

    private function giveStarterKit(Player $player) {
        $inventory = $player->getInventory();
        $inventory->clearAll();
        $inventory->addItem(ItemFactory::getInstance()->get(ItemIds::BOW));
        $inventory->addItem(ItemFactory::getInstance()->get(ItemIds::ARROW, 0, 1));
        $inventory->addItem(ItemFactory::getInstance()->get(ItemIds::STONE_SWORD));
    }

    public function checkEndGame() {
        foreach($this->scores as $name => $score) {
            if($score >= $this->config->get("win_score", 20)) {
                $this->endGame($name);
                return;
            }
        }
    }

    private function endGame($winnerName) {
        $this->getServer()->broadcastMessage("§a$winnerName a gagné la partie avec un score de " . $this->scores[$winnerName] . "!");
        $this->saveHighScore($winnerName, $this->scores[$winnerName]);
        $this->resetGame();
    }

    private function saveHighScore($name, $score) {
        $highscores = $this->config->get("highscores", []);
        $highscores[$name] = $score;
        arsort($highscores);
        $this->config->set("highscores", $highscores);
        $this->config->save();
    }

    private function resetGame() {
        foreach($this->getServer()->getOnlinePlayers() as $player) {
            $this->giveStarterKit($player);
            $this->scores[$player->getName()] = 0;
            $this->killstreaks[$player->getName()] = 0;
        }
    }
}

class RespawnTask extends Task {
    private $plugin;
    private $player;

    public function __construct(Main $plugin, Player $player) {
        $this->plugin = $plugin;
        $this->player = $player;
    }

    public function onRun(): void {
        if($this->player->isOnline()) {
            $this->player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
            $this->plugin->giveStarterKit($this->player);
        }
    }
}

class GameTimer extends Task {
    private $plugin;
    private $time = 0;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->time++;
        if($this->time >= $this->plugin->config->get("game_time", 300)) {
            $this->plugin->endGame("Time's up! No winner this time.");
            $this->time = 0;
        }
    }
}