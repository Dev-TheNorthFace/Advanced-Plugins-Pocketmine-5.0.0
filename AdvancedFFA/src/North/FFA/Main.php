<?php

declare(strict_types=1);

namespace North\FFA\Main;

use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\world\World;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\scheduler\Task;
use pocketmine\math\Vector3;

class Main extends PluginBase implements Listener {

    private $players = [];
    private $arena;
    private $lobby;
    private $spectator;
    private $status = "waiting";
    private $countdown = 60;
    private $gameTime = 0;
    private $starting = false;
    private $playing = false;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->loadArenas();
    }

    private function loadArenas(): void {
        $this->arena = $this->getConfig()->get("arena");
        $this->lobby = $this->getConfig()->get("lobby");
        $this->spectator = $this->getConfig()->get("spectator");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(strtolower($command->getName()) === "ffa") {
            if(!$sender instanceof Player) return false;

            if(!isset($args[0])) {
                $sender->sendMessage(TextFormat::RED . "Usage: /ffa join");
                return false;
            }

            switch(strtolower($args[0])) {
                case "join":
                    $this->joinFFA($sender);
                    break;
                default:
                    $sender->sendMessage(TextFormat::RED . "Usage: /ffa join");
                    break;
            }
        }
        return true;
    }

    private function joinFFA(Player $player): void {
        if($this->status !== "waiting") {
            $player->sendMessage(TextFormat::RED . "L'événement FFA a déjà commencé !");
            return;
        }

        if(isset($this->players[$player->getName()])) {
            $player->sendMessage(TextFormat::RED . "Vous avez déjà rejoint l'événement FFA !");
            return;
        }

        $this->players[$player->getName()] = $player;
        $player->sendMessage(TextFormat::GREEN . "Vous avez rejoint l'événement FFA !");

        $lobbyPos = new Vector3($this->lobby["x"], $this->lobby["y"], $this->lobby["z"]);
        $player->teleport($this->getServer()->getWorldManager()->getWorldByName($this->lobby["world"])->getSafeSpawn());
        $player->teleport($lobbyPos);

        if(count($this->players) >= 2 && !$this->starting) {
            $this->starting = true;
            $this->getServer()->broadcastMessage(TextFormat::BOLD . TextFormat::RED . "[🔥] Événement FFA – Dernier survivant !");
            $this->getServer()->broadcastMessage(TextFormat::GOLD . "Tape /ffa join pour participer.");
            $this->getServer()->broadcastMessage(TextFormat::YELLOW . "Début dans 60 secondes.");
            $this->getScheduler()->scheduleRepeatingTask(new CountdownTask($this), 20);
        }
    }

    public function startGame(): void {
        $this->status = "running";
        $this->playing = true;
        $this->starting = false;
        $this->countdown = 0;

        foreach($this->players as $player) {
            $arenaPos = new Vector3($this->arena["x"], $this->arena["y"], $this->arena["z"]);
            $player->teleport($this->getServer()->getWorldManager()->getWorldByName($this->arena["world"])->getSafeSpawn());
            $player->teleport($arenaPos);
            $this->giveKit($player);
        }

        $this->getServer()->broadcastMessage(TextFormat::BOLD . TextFormat::RED . "3... 2... 1... " . TextFormat::GREEN . "GO !");
        $this->getScheduler()->scheduleRepeatingTask(new GameTask($this), 20);
    }

    private function giveKit(Player $player): void {
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();

        $sword = ItemFactory::getInstance()->get(ItemIds::DIAMOND_SWORD);
        $player->getInventory()->addItem($sword);

        $helmet = ItemFactory::getInstance()->get(ItemIds::DIAMOND_HELMET);
        $chestplate = ItemFactory::getInstance()->get(ItemIds::DIAMOND_CHESTPLATE);
        $leggings = ItemFactory::getInstance()->get(ItemIds::DIAMOND_LEGGINGS);
        $boots = ItemFactory::getInstance()->get(ItemIds::DIAMOND_BOOTS);
        $player->getArmorInventory()->setHelmet($helmet);
        $player->getArmorInventory()->setChestplate($chestplate);
        $player->getArmorInventory()->setLeggings($leggings);
        $player->getArmorInventory()->setBoots($boots);

        $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::GOLDEN_APPLE, 0, 5));
    }

    public function eliminatePlayer(Player $player): void {
        if(!isset($this->players[$player->getName()])) return;

        unset($this->players[$player->getName()]);
        $spectatorPos = new Vector3($this->spectator["x"], $this->spectator["y"], $this->spectator["z"]);
        $player->teleport($this->getServer()->getWorldManager()->getWorldByName($this->spectator["world"])->getSafeSpawn());
        $player->teleport($spectatorPos);
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->setHealth(20);
        $player->setFood(20);

        $this->getServer()->broadcastMessage(TextFormat::RED . "[] " . $player->getName() . " a été éliminé – " . count($this->players) . " joueurs restants");

        if(count($this->players) === 1) {
            $this->endGame();
        }
    }

    private function endGame(): void {
        $this->playing = false;
        $winner = array_pop($this->players);

        $this->getServer()->broadcastMessage(TextFormat::GOLD . "[] " . $winner->getName() . " est le dernier survivant du FFA et gagne 500$ + clé légendaire !");

        $spectatorPos = new Vector3($this->spectator["x"], $this->spectator["y"], $this->spectator["z"]);
        $winner->teleport($this->getServer()->getWorldManager()->getWorldByName($this->spectator["world"])->getSafeSpawn());
        $winner->teleport($spectatorPos);
        $winner->getInventory()->clearAll();
        $winner->getArmorInventory()->clearAll();

        $this->status = "waiting";
        $this->countdown = 60;
        $this->gameTime = 0;
    }

    public function onDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->players[$player->getName()])) {
            $this->eliminatePlayer($player);
        }
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->players[$player->getName()])) {
            $this->eliminatePlayer($player);
        }
    }

    public function onMove(PlayerMoveEvent $event): void {
        if(!$this->playing) return;

        $player = $event->getPlayer();
        if(!isset($this->players[$player->getName()])) return;

        $pos = $event->getTo();
        $arenaWorld = $this->getServer()->getWorldManager()->getWorldByName($this->arena["world"]);

        if($pos->getWorld() !== $arenaWorld) {
            $player->teleport($arenaWorld->getSafeSpawn());
            $player->sendMessage(TextFormat::RED . "Vous ne pouvez pas quitter l'arène !");
        }
    }

    public function onDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if(!$entity instanceof Player) return;

        if($this->status === "waiting" && isset($this->players[$entity->getName()])) {
            $event->cancel();
            return;
        }

        if($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if($damager instanceof Player && !isset($this->players[$damager->getName()])) {
                $event->cancel();
            }
        }
    }

    public function getCountdown(): int {
        return $this->countdown;
    }

    public function setCountdown(int $countdown): void {
        $this->countdown = $countdown;
    }

    public function getGameTime(): int {
        return $this->gameTime;
    }

    public function setGameTime(int $gameTime): void {
        $this->gameTime = $gameTime;
    }
}

class CountdownTask extends Task {

    private $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $countdown = $this->plugin->getCountdown();

        if($countdown <= 0) {
            $this->plugin->startGame();
            $this->getHandler()->cancel();
            return;
        }

        if($countdown % 15 === 0 || $countdown <= 5) {
            $this->plugin->getServer()->broadcastMessage(TextFormat::YELLOW . "Début du FFA dans " . $countdown . " seconde(s) !");
        }

        $this->plugin->setCountdown($countdown - 1);
    }
}

class GameTask extends Task {

    private $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $gameTime = $this->plugin->getGameTime();
        $this->plugin->setGameTime($gameTime + 1);

        if(count($this->plugin->getPlayers()) <= 1) {
            $this->getHandler()->cancel();
        }
    }
}