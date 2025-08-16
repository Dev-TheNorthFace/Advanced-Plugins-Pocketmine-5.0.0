<?php

namespace North\Koth\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\item\VanillaItems;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\math\Vector3;

class Main extends PluginBase implements Listener {

    private $kothRunning = false;
    private $kothTime = 600;
    private $currentTime = 0;
    private $captureTime = 0;
    private $currentKing = null;
    private $zoneCenter = null;
    private $zoneRadius = 5;
    private $scoreboard = [];
    private $floatingText = null;
    private $zoneParticles = [];
    private $rewards = [];
    private $teamMode = false;
    private $teams = [];
    private $shrinkingBorder = false;
    private $borderRadius = 50;
    private $borderCenter = null;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->rewards = $this->getConfig()->get("rewards", []);
        $this->teamMode = $this->getConfig()->get("team-mode", false);
        $this->borderCenter = new Position(
            $this->getConfig()->get("border-center-x", 0),
            $this->getConfig()->get("border-center-y", 0),
            $this->getConfig()->get("border-center-z", 0),
            $this->getServer()->getWorldManager()->getDefaultWorld()
        );
        $this->borderRadius = $this->getConfig()->get("border-radius", 50);
        $this->getScheduler()->scheduleRepeatingTask(new KOTHTask($this), 20);
    }

    public function startKOTH() {
        $this->kothRunning = true;
        $this->currentTime = $this->kothTime;
        $this->captureTime = 0;
        $this->currentKing = null;
        $this->moveZone();
        $this->getServer()->broadcastMessage("§6[KOTH] §eLe King of the Hill a commencé ! Capturez la zone pour gagner !");
    }

    public function stopKOTH() {
        $this->kothRunning = false;
        if($this->currentKing !== null) {
            $this->giveRewards($this->currentKing);
            $this->getServer()->broadcastMessage("§6[KOTH] §eLe King of the Hill est terminé ! §b" . $this->currentKing->getName() . " §ea gagné !");
        } else {
            $this->getServer()->broadcastMessage("§6[KOTH] §eLe King of the Hill est terminé ! Personne n'a gagné.");
        }
        $this->clearZone();
        $this->scoreboard = [];
    }

    public function moveZone() {
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        $x = mt_rand(-50, 50);
        $z = mt_rand(-50, 50);
        $y = $world->getHighestBlockAt($x, $z) + 1;
        $this->zoneCenter = new Position($x, $y, $z, $world);
        $this->spawnZoneParticles();
        $this->updateFloatingText();
        $this->getServer()->broadcastMessage("§6[KOTH] §eLa zone s'est déplacée vers §bX: $x, Y: $y, Z: $z");
    }

    public function spawnZoneParticles() {
        $this->clearZoneParticles();
        $center = $this->zoneCenter;
        $world = $center->getWorld();
        for($i = 0; $i < 360; $i += 15) {
            $x = $center->x + $this->zoneRadius * cos(deg2rad($i));
            $z = $center->z + $this->zoneRadius * sin(deg2rad($i));
            $pos = new Position($x, $center->y, $z, $world);
            $particle = new FloatingTextParticle("", "§6§lKOTH ZONE");
            $world->addParticle($pos, $particle);
            $this->zoneParticles[] = $particle;
        }
    }

    public function clearZoneParticles() {
        foreach($this->zoneParticles as $particle) {
            $particle->setInvisible();
        }
        $this->zoneParticles = [];
    }

    public function updateFloatingText() {
        if($this->floatingText !== null) {
            $this->floatingText->setInvisible();
        }
        if($this->zoneCenter !== null) {
            $text = "§6§lKING OF THE HILL\n";
            $text .= "§eTemps restant: §b" . gmdate("i:s", $this->currentTime) . "\n";
            if($this->currentKing !== null) {
                $text .= "§eRoi actuel: §b" . $this->currentKing->getName() . "\n";
                $text .= "§eTemps de capture: §b" . gmdate("i:s", $this->captureTime);
            } else {
                $text .= "§ePersonne ne contrôle la zone !";
            }
            $pos = clone $this->zoneCenter;
            $pos->y += 3;
            $this->floatingText = new FloatingTextParticle("", $text);
            $this->zoneCenter->getWorld()->addParticle($pos, $this->floatingText);
        }
    }

    public function checkZone(Player $player) {
        if(!$this->kothRunning || $this->zoneCenter === null) return false;
        $pos = $player->getPosition();
        $center = $this->zoneCenter;
        if($pos->getWorld() !== $center->getWorld()) return false;
        return $pos->distance($center) <= $this->zoneRadius;
    }

    public function onMove(PlayerMoveEvent $event) {
        $player = $event->getPlayer();
        if($this->checkZone($player)) {
            if($this->currentKing === null || $this->currentKing->getId() !== $player->getId()) {
                $this->currentKing = $player;
                $player->sendMessage("§6[KOTH] §eVous contrôlez maintenant la zone !");
                $this->updateFloatingText();
            }
        }
    }

    public function onDamage(EntityDamageEvent $event) {
        $entity = $event->getEntity();
        if($entity instanceof Player && $this->kothRunning) {
            if($event instanceof EntityDamageByEntityEvent) {
                $damager = $event->getDamager();
                if($damager instanceof Player) {
                    if($this->checkZone($entity) || $this->checkZone($damager)) {
                        $event->setBaseDamage($event->getBaseDamage() * 1.2);
                    }
                }
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event) {
        $player = $event->getPlayer();
        if($this->currentKing !== null && $this->currentKing->getId() === $player->getId()) {
            $this->currentKing = null;
            $this->captureTime = 0;
            $this->updateFloatingText();
        }
    }

    public function giveRewards(Player $player) {
        $name = $player->getName();
        if(!isset($this->scoreboard[$name])) {
            $this->scoreboard[$name] = 0;
        }
        $this->scoreboard[$name] += $this->captureTime;
        $reward = $this->rewards["base"] ?? 100;
        $bonus = floor($this->captureTime / 30) * ($this->rewards["bonus"] ?? 50);
        $total = $reward + $bonus;
        $player->sendMessage("§6[KOTH] §eVous avez gagné §b$total$ §epour avoir contrôlé la zone pendant §b" . gmdate("i:s", $this->captureTime));
        $item = VanillaItems::GOLD_INGOT();
        $item->setCustomName("§6Clé KOTH");
        $item->setLore(["§eUtilisez cette clé pour ouvrir un coffre KOTH !"]);
        $player->getInventory()->addItem($item);
        $effect = new EffectInstance(VanillaEffects::STRENGTH(), 20*30, 1);
        $player->getEffects()->add($effect);
    }

    public function spawnRandomChest() {
        if($this->zoneCenter === null) return;
        $world = $this->zoneCenter->getWorld();
        $chest = $world->dropItem($this->zoneCenter, VanillaItems::CHEST());
        $chest->setName("§6Coffre KOTH");
        $this->getServer()->broadcastMessage("§6[KOTH] §eUn coffre bonus est apparu au centre de la zone !");
    }

    public function shrinkBorder() {
        if($this->borderRadius > 10) {
            $this->borderRadius -= 5;
            $this->getServer()->broadcastMessage("§6[KOTH] §eLa bordure se rétrécit ! Rayon actuel: §b" . $this->borderRadius);
        }
    }

    public function checkBorder(Player $player) {
        if(!$this->shrinkingBorder) return;
        $pos = $player->getPosition();
        if($pos->distance($this->borderCenter) > $this->borderRadius) {
            $player->teleport($this->borderCenter);
            $player->sendMessage("§6[KOTH] §eVous avez été téléporté à l'intérieur de la bordure !");
        }
    }
}

class KOTHTask extends Task {

    private $plugin;
    private $moveTimer = 0;
    private $chestTimer = 0;
    private $borderTimer = 0;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        if($this->plugin->kothRunning) {
            $this->plugin->currentTime--;
            if($this->plugin->currentKing !== null) {
                $this->plugin->captureTime++;
                $effect = new EffectInstance(VanillaEffects::RESISTANCE(), 20*5, 0);
                $this->plugin->currentKing->getEffects()->add($effect);
                if($this->plugin->captureTime % 30 === 0) {
                    $this->plugin->currentKing->sendMessage("§6[KOTH] §eVotre bonus augmente !");
                }
                if($this->plugin->captureTime > 30) {
                    $effect = new EffectInstance(VanillaEffects::WITHER(), 20*5, 0);
                    $this->plugin->currentKing->getEffects()->add($effect);
                }
            }
            $this->moveTimer++;
            if($this->moveTimer >= 120) {
                $this->plugin->moveZone();
                $this->moveTimer = 0;
            }
            $this->chestTimer++;
            if($this->chestTimer >= 60) {
                $this->plugin->spawnRandomChest();
                $this->chestTimer = 0;
            }
            $this->borderTimer++;
            if($this->borderTimer >= 300 && $this->plugin->captureTime < 10) {
                $this->plugin->shrinkBorder();
                $this->borderTimer = 0;
            }
            if($this->plugin->currentTime <= 0) {
                $this->plugin->stopKOTH();
            }
            $this->plugin->updateFloatingText();
        }
    }
}