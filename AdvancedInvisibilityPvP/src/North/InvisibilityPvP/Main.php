<?php

declare(strict_types=1);

namespace North\InvisibilityPvP\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\item\VanillaItems;
use pocketmine\world\World;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private $arenas = [];
    private $players = [];
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->loadArenas();
    }

    private function loadArenas(): void {
        foreach($this->config->get("arenas", []) as $arenaName => $arenaData) {
            $this->arenas[$arenaName] = $arenaData;
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($command->getName() === "pvpinvisible") {
            if(!isset($args[0])) {
                return false;
            }

            switch(strtolower($args[0])) {
                case "start":
                    if(!$sender instanceof Player) {
                        $sender->sendMessage("Commande réservée aux joueurs");
                        return true;
                    }
                    $this->startGame($sender);
                    return true;
                case "create":
                    if(!$sender->hasPermission("pvpinvisible.admin")) {
                        $sender->sendMessage("Permission manquante");
                        return true;
                    }
                    if(!isset($args[1])) {
                        $sender->sendMessage("Usage: /pvpinvisible create <nom>");
                        return true;
                    }
                    $this->createArena($sender, $args[1]);
                    return true;
                default:
                    return false;
            }
        }
        return false;
    }

    private function startGame(Player $player): void {
        $arena = $this->findAvailableArena();
        if($arena === null) {
            $player->sendMessage("Aucune arène disponible");
            return;
        }

        $this->players[$player->getName()] = $arena;
        $player->teleport($this->getServer()->getWorldManager()->getWorldByName($arena["world"])->getSpawnLocation());

        $this->applyInvisibleSetup($player);
        $player->sendMessage("Partie PvP Invisible lancée!");
    }

    private function applyInvisibleSetup(Player $player): void {
        $player->getEffects()->add(new EffectInstance(VanillaEffects::INVISIBILITY(), 999999, 1, false));
        $player->getArmorInventory()->clearAll();
        $player->getInventory()->clearAll();
        $player->getInventory()->addItem(VanillaItems::WOODEN_SWORD());
    }

    private function createArena(Player $player, string $name): void {
        $world = $player->getWorld();
        $this->arenas[$name] = [
            "world" => $world->getFolderName(),
            "spawn" => $player->getPosition()->asVector3()
        ];
        $this->config->set("arenas", $this->arenas);
        $this->config->save();
        $player->sendMessage("Arène $name créée!");
    }

    private function findAvailableArena(): ?array {
        foreach($this->arenas as $arena) {
            $world = $this->getServer()->getWorldManager()->getWorldByName($arena["world"]);
            if($world !== null && count($world->getPlayers()) < 10) {
                return $arena;
            }
        }
        return null;
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->players[$player->getName()])) {
            $this->applyInvisibleSetup($player);
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->players[$player->getName()])) {
            unset($this->players[$player->getName()]);
        }
    }

    public function onDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if(!$entity instanceof Player) {
            return;
        }

        if(!isset($this->players[$entity->getName()])) {
            return;
        }

        if($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if($damager instanceof Player && isset($this->players[$damager->getName()])) {
                $event->setBaseDamage(4);
                $this->revealPlayer($entity, 100);
                $this->revealPlayer($damager, 100);
            }
        }
    }

    private function revealPlayer(Player $player, int $duration): void {
        $player->getEffects()->remove(VanillaEffects::INVISIBILITY());
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player): void {
            if($player->isOnline() && isset($this->players[$player->getName()])) {
                $player->getEffects()->add(new EffectInstance(VanillaEffects::INVISIBILITY(), 999999, 1, false));
            }
        }), $duration);
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if(isset($this->players[$player->getName()])) {
            $world = $player->getWorld();
            $world->addParticle($player->getPosition(), new DustParticle(new Color(100, 100, 100, 50)));
        }
    }
}