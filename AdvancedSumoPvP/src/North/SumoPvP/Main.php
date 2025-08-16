<?php

namespace North\SumoPvP\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\item\VanillaItems;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\world\particle\FlameParticle;

class Main extends PluginBase implements Listener {

    private $arenas = [];
    private $players = [];
    private $config;
    private $elo = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->elo = new Config($this->getDataFolder() . "elo.yml", Config::YAML, []);
        $this->loadArenas();
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            public function __construct(private Main $plugin) {}
            public function onRun(): void {
                $this->plugin->tickArenas();
            }
        }, 20);
    }

    private function loadArenas(): void {
        $this->arenas = [
            "sumo1" => [
                "pos1" => new Vector3(100, 65, 100),
                "pos2" => new Vector3(110, 70, 110),
                "spawn" => new Vector3(105, 66, 105),
                "world" => "sumo",
                "active" => false,
                "players" => [],
                "shrinkTimer" => 60,
                "originalSize" => 10
            ]
        ];
    }

    public function onDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            foreach ($this->arenas as $arena) {
                if (in_array($entity->getName(), $arena["players"])) {
                    if ($event instanceof EntityDamageByEntityEvent) {
                        $damager = $event->getDamager();
                        if ($damager instanceof Player) {
                            $event->setKnockback($event->getKnockback() * 1.5);
                            $this->addCombo($damager->getName());
                        }
                    } else {
                        $event->cancel();
                    }
                }
            }
        }
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();
        if ($from->distance($to) < 0.1) return;

        foreach ($this->arenas as $name => $arena) {
            if (in_array($player->getName(), $arena["players"])) {
                $world = $this->getServer()->getWorldManager()->getWorldByName($arena["world"]);
                if ($player->getWorld() === $world) {
                    if ($to->y < $arena["pos1"]->y - 5) {
                        $this->eliminatePlayer($player, $name);
                    }
                }
            }
        }
    }

    private function eliminatePlayer(Player $player, string $arenaName): void {
        $arena = $this->arenas[$arenaName];
        $winner = null;
        $index = array_search($player->getName(), $arena["players"]);
        if ($index !== false) {
            unset($this->arenas[$arenaName]["players"][$index]);
            $this->arenas[$arenaName]["players"] = array_values($this->arenas[$arenaName]["players"]);
            $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
            $player->getInventory()->clearAll();
            $player->getEffects()->clear();

            if (count($this->arenas[$arenaName]["players"]) === 1) {
                $winner = $this->getServer()->getPlayerExact($this->arenas[$arenaName]["players"][0]);
                $this->endGame($winner, $arenaName);
            }
        }
    }

    private function endGame(Player $winner, string $arenaName): void {
        $this->giveRewards($winner);
        $loserElo = $this->getElo($winner->getName());
        $this->setElo($winner->getName(), $loserElo + 10);
        $winner->sendTitle("§aVictoire!", "§7Tu as gagné le combat Sumo!");
        $this->spawnParticles($winner);

        foreach ($this->arenas[$arenaName]["players"] as $playerName) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player !== null) {
                $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
                $player->getInventory()->clearAll();
                $player->getEffects()->clear();
            }
        }

        $this->arenas[$arenaName]["players"] = [];
        $this->arenas[$arenaName]["active"] = false;
        $this->arenas[$arenaName]["shrinkTimer"] = 60;
    }

    private function giveRewards(Player $player): void {
        $player->getInventory()->addItem(VanillaItems::GOLD_INGOT()->setCount(3));
        $player->sendMessage("§6Récompense: §e3 lingots d'or");
    }

    private function spawnParticles(Player $player): void {
        $world = $player->getWorld();
        for ($i = 0; $i < 360; $i += 10) {
            $x = 0.5 * cos(deg2rad($i));
            $z = 0.5 * sin(deg2rad($i));
            $world->addParticle($player->getPosition()->add($x, 1, $z), new FlameParticle());
        }
    }

    private function addCombo(string $playerName): void {
        if (!isset($this->players[$playerName]["combo"])) {
            $this->players[$playerName]["combo"] = 0;
        }
        $this->players[$playerName]["combo"]++;
        $this->players[$playerName]["lastHit"] = time();
    }

    private function tickArenas(): void {
        foreach ($this->arenas as $name => &$arena) {
            if ($arena["active"]) {
                $arena["shrinkTimer"]--;
                if ($arena["shrinkTimer"] <= 0 && $arena["originalSize"] > 3) {
                    $arena["originalSize"]--;
                    $arena["pos1"] = $arena["pos1"]->add(0.5, 0, 0.5);
                    $arena["pos2"] = $arena["pos2"]->subtract(0.5, 0, 0.5);
                    $arena["shrinkTimer"] = 60;
                }

                if (mt_rand(1, 100) < 10) {
                    $this->applyRandomEffect($name);
                }
            }
        }
    }

    private function applyRandomEffect(string $arenaName): void {
        $effects = [
            VanillaEffects::SPEED(),
            VanillaEffects::SLOWNESS(),
            VanillaEffects::JUMP_BOOST()
        ];
        $effect = $effects[array_rand($effects)];
        $duration = mt_rand(5, 15) * 20;
        $amplifier = mt_rand(0, 2);

        foreach ($this->arenas[$arenaName]["players"] as $playerName) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player !== null) {
                $player->getEffects()->add(new EffectInstance($effect, $duration, $amplifier));
                $player->sendMessage("§dEffet aléatoire: §f" . $effect->getName());
            }
        }
    }

    public function getElo(string $playerName): int {
        return $this->elo->get($playerName, 1000);
    }

    public function setElo(string $playerName, int $elo): void {
        $this->elo->set($playerName, $elo);
        $this->elo->save();
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        foreach ($this->arenas as $name => $arena) {
            if (in_array($player->getName(), $arena["players"])) {
                $this->eliminatePlayer($player, $name);
            }
        }
    }
}