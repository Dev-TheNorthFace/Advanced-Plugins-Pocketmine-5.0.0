<?php

namespace North\TFIL\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\world\World;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private $gameRunning = false;
    private $arenaWorld;
    private $blocksToRemove = [];
    private $gameTime = 0;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->getScheduler()->scheduleRepeatingTask(new GameTask($this), 20);
    }

    public function startGame() {
        $this->gameRunning = true;
        $this->arenaWorld = $this->getServer()->getWorldManager()->getWorldByName($this->getConfig()->get("arena-world"));
        $this->gameTime = 0;
        $this->getServer()->broadcastMessage("§cThe Floor Is Lava! §eLe sol va disparaître progressivement!");
    }

    public function onMove(PlayerMoveEvent $event) {
        if(!$this->gameRunning) return;

        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();

        if($from->floor()->equals($to->floor())) return;

        $blockPos = $to->floor()->subtract(0, 1, 0);
        $this->blocksToRemove[] = $blockPos;
    }

    public function updateGame() {
        if(!$this->gameRunning) return;

        $this->gameTime++;

        if($this->gameTime % 5 === 0) {
            $this->removeRandomBlocks();
        }

        if($this->gameTime % 30 === 0) {
            $this->applyRandomEffects();
        }
    }

    private function removeRandomBlocks() {
        if(empty($this->blocksToRemove)) return;

        $blockPos = array_shift($this->blocksToRemove);
        $world = $this->arenaWorld;

        if($world->getBlock($blockPos)->isSolid()) {
            $world->setBlock($blockPos, Block::get(0));
            $world->addParticle($blockPos->add(0.5, 0.5, 0.5), new \pocketmine\world\particle\SmokeParticle());
        }
    }

    private function applyRandomEffects() {
        $effects = [
            \pocketmine\entity\effect\EffectInstance::get(\pocketmine\entity\effect\VanillaEffects::SPEED(), 20*10, 1),
            \pocketmine\entity\effect\EffectInstance::get(\pocketmine\entity\effect\VanillaEffects::JUMP_BOOST(), 20*10, 1),
            \pocketmine\entity\effect\EffectInstance::get(\pocketmine\entity\effect\VanillaEffects::SLOWNESS(), 20*10, 1)
        ];

        foreach($this->arenaWorld->getPlayers() as $player) {
            $effect = $effects[array_rand($effects)];
            $player->getEffects()->add($effect);
            $player->sendMessage("§aEffet aléatoire: §e" . $effect->getType()->getName());
        }
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