<?php

namespace North\Spawn\Main;

use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\world\Position;
use pocketmine\utils\Config;
use pocketmine\scheduler\ClosureTask;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\math\Vector3;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\world\particle\EndermanTeleportParticle;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;

class Main extends PluginBase implements Listener {

    private $config;
    private $teleporting = [];
    private $cooldowns = [];
    private $protectedPlayers = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage("§cCette commande ne peut être utilisée que dans le jeu.");
            return true;
        }

        switch(strtolower($command->getName())) {
            case "spawn":
                $this->handleSpawnCommand($sender);
                break;
            case "setspawn":
                $this->handleSetSpawnCommand($sender, $args);
                break;
            case "spawninfo":
                $this->handleSpawnInfoCommand($sender);
                break;
        }
        return true;
    }

    private function handleSpawnCommand(Player $player) {
        $worldName = $player->getWorld()->getFolderName();
        $spawnLocation = $this->config->get("spawns.$worldName", $this->config->get("spawn.default"));

        if(!$spawnLocation) {
            $player->sendMessage("§cAucun spawn n'a été défini pour ce monde.");
            return;
        }

        if($this->isInCombat($player)) {
            $player->sendMessage("§cVous ne pouvez pas vous téléporter en combat !");
            return;
        }

        if(isset($this->cooldowns[$player->getName()]) && time() < $this->cooldowns[$player->getName()]) {
            $remaining = $this->cooldowns[$player->getName()] - time();
            $player->sendMessage("§cVous devez attendre §e{$remaining}§c secondes avant de réutiliser cette commande.");
            return;
        }

        if($player->hasPermission("spawn.instant")) {
            $this->teleportToSpawn($player);
            return;
        }

        $this->teleporting[$player->getName()] = [
            "time" => 5,
            "position" => $player->getPosition(),
            "world" => $worldName
        ];

        $player->sendMessage("§aTéléportation dans §e5§a secondes... Ne bougez pas !");
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use ($player): void {
            if(!isset($this->teleporting[$player->getName()])) return;

            $data = $this->teleporting[$player->getName()];
            $data["time"]--;

            if($data["time"] <= 0) {
                $this->teleportToSpawn($player);
                unset($this->teleporting[$player->getName()]);
                return;
            }

            $player->sendMessage("§aTéléportation dans §e{$data["time"]}§a secondes... Ne bougez pas !");
            $this->teleporting[$player->getName()] = $data;

            $player->getWorld()->addParticle($player->getPosition(), new EndermanTeleportParticle());
        }), 20);
    }

    private function teleportToSpawn(Player $player) {
        $worldName = $player->getWorld()->getFolderName();
        $spawnData = $this->config->get("spawns.$worldName", $this->config->get("spawn.default"));

        if(!$spawnData) {
            $player->sendMessage("§cAucun spawn n'a été défini pour ce monde.");
            return;
        }

        $world = $this->getServer()->getWorldManager()->getWorldByName($spawnData["world"]);
        $position = new Position($spawnData["x"], $spawnData["y"], $spawnData["z"], $world);

        $player->teleport($position);
        $player->sendMessage("§aTéléportation au spawn réussie !");

        $player->getWorld()->addParticle($player->getPosition(), new EndermanTeleportParticle());
        $player->getWorld()->addSound($player->getPosition(), new EndermanTeleportSound());

        $this->cooldowns[$player->getName()] = time() + $this->config->get("cooldown", 60);

        $this->protectedPlayers[$player->getName()] = time() + 3;
        $player->getEffects()->add(new EffectInstance(VanillaEffects::RESISTANCE(), 60, 255, false));
    }

    private function handleSetSpawnCommand(Player $player, array $args) {
        if(!$player->hasPermission("spawn.set")) {
            $player->sendMessage("§cVous n'avez pas la permission de définir le spawn.");
            return;
        }

        $worldName = $player->getWorld()->getFolderName();
        $spawnName = empty($args) ? "default" : strtolower($args[0]);

        $spawnData = [
            "x" => $player->getPosition()->getX(),
            "y" => $player->getPosition()->getY(),
            "z" => $player->getPosition()->getZ(),
            "world" => $worldName
        ];

        $this->config->set("spawns.$worldName.$spawnName", $spawnData);
        $this->config->save();

        $player->sendMessage("§aSpawn défini avec succès pour le monde §e$worldName§a (type: §e$spawnName§a)");
    }

    private function handleSpawnInfoCommand(Player $player) {
        $worldName = $player->getWorld()->getFolderName();
        $spawnData = $this->config->get("spawns.$worldName", $this->config->get("spawn.default"));

        if(!$spawnData) {
            $player->sendMessage("§cAucun spawn n'a été défini pour ce monde.");
            return;
        }

        $player->sendMessage("§6Informations du spawn:");
        $player->sendMessage("§eMonde: §f" . $spawnData["world"]);
        $player->sendMessage("§eCoordonnées: §fX: " . round($spawnData["x"], 1) . " Y: " . round($spawnData["y"], 1) . " Z: " . round($spawnData["z"], 1));
    }

    private function isInCombat(Player $player): bool {
        return false;
    }

    public function onPlayerMove(PlayerMoveEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->teleporting[$player->getName()])) {
            $originalPos = $this->teleporting[$player->getName()]["position"];
            if($originalPos->distance($player->getPosition()) > 1) {
                unset($this->teleporting[$player->getName()]);
                $player->sendMessage("§cTéléportation annulée car vous avez bougé !");
            }
        }
    }

    public function onDamage(EntityDamageEvent $event) {
        $entity = $event->getEntity();
        if($entity instanceof Player) {
            if(isset($this->teleporting[$entity->getName()])) {
                unset($this->teleporting[$entity->getName()]);
                $entity->sendMessage("§cTéléportation annulée car vous avez subi des dégâts !");
            }

            if(isset($this->protectedPlayers[$entity->getName()]) && time() < $this->protectedPlayers[$entity->getName()]) {
                $event->cancel();
            }

            if($event instanceof EntityDamageByEntityEvent) {
                $damager = $event->getDamager();
                if($damager instanceof Player && isset($this->protectedPlayers[$damager->getName()]) && time() < $this->protectedPlayers[$damager->getName()]) {
                    $event->cancel();
                }
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event) {
        $player = $event->getPlayer();
        unset($this->teleporting[$player->getName()]);
    }
}