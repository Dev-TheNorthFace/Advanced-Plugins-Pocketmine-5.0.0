<?php

declare(strict_types=1);

namespace North\CTF\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockTypeIds;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\world\Position;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\math\Vector3;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private $flags = [];
    private $flagCarriers = [];
    private $teams = [];
    private $scores = [];
    private $defenseTimes = [];
    private $flagBlocks = [
        "red" => BlockTypeIds::RED_WOOL,
        "blue" => BlockTypeIds::BLUE_WOOL
    ];
    private $basePositions = [];
    private $gameRunning = false;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->loadGameData();
        $this->getScheduler()->scheduleRepeatingTask(new GameTask($this), 20);
    }

    private function loadGameData(): void {
        $config = $this->getConfig();
        $this->basePositions["red"] = $this->parsePosition($config->get("red_base"));
        $this->basePositions["blue"] = $this->parsePosition($config->get("blue_base"));
        $this->scores["red"] = 0;
        $this->scores["blue"] = 0;
        $this->placeFlags();
        $this->gameRunning = true;
    }

    private function parsePosition(array $data): Position {
        return new Position($data["x"], $data["y"], $data["z"], $this->getServer()->getWorldManager()->getWorldByName($data["world"]));
    }

    private function placeFlags(): void {
        foreach($this->flagBlocks as $team => $blockId) {
            $pos = $this->basePositions[$team];
            $pos->getWorld()->setBlock($pos, BlockFactory::getInstance()->get($blockId));
            $this->flags[$team] = true;
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        if(!$this->gameRunning) return;

        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();

        if($from->distance($to) < 0.1) return;

        $this->checkFlagCapture($player, $to);
        $this->checkFlagReturn($player, $to);
    }

    private function checkFlagCapture(Player $player, Position $position): void {
        $team = $this->getPlayerTeam($player);
        if($team === null) return;

        foreach($this->flagBlocks as $targetTeam => $blockId) {
            if($team === $targetTeam) continue;

            $flagPos = $this->basePositions[$targetTeam];
            if($position->distance($flagPos) < 2 && $this->flags[$targetTeam]) {
                $this->takeFlag($player, $targetTeam);
                break;
            }
        }
    }

    private function takeFlag(Player $player, string $team): void {
        $this->flags[$team] = false;
        $this->flagCarriers[$player->getName()] = $team;
        $player->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 999999, 1));
        $player->getInventory()->clear();
        $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::WOODEN_SWORD));
        $player->sendMessage("§eVous avez pris le drapeau $team! Ramenez-le à votre base!");

        $flagPos = $this->basePositions[$team];
        $flagPos->getWorld()->setBlock($flagPos, BlockFactory::getInstance()->get(BlockTypeIds::AIR));
    }

    private function checkFlagReturn(Player $player, Position $position): void {
        $team = $this->getPlayerTeam($player);
        if($team === null || !isset($this->flagCarriers[$player->getName()])) return;

        $flagTeam = $this->flagCarriers[$player->getName()];
        $basePos = $this->basePositions[$team];

        if($position->distance($basePos) < 2 && $team !== $flagTeam) {
            $this->scorePoint($team, $flagTeam);
            $this->returnFlag($flagTeam);
            unset($this->flagCarriers[$player->getName()]);
            $player->getEffects()->remove(VanillaEffects::SLOWNESS());
            $player->sendMessage("§aVous avez ramené le drapeau $flagTeam! +5 points!");
        }
    }

    private function scorePoint(string $scoringTeam, string $flagTeam): void {
        $this->scores[$scoringTeam] += 5;
        $this->broadcastMessage("§6$scoringTeam a marqué avec le drapeau $flagTeam! Score: ".$this->scores["red"]."-".$this->scores["blue"]);
    }

    private function returnFlag(string $team): void {
        $this->flags[$team] = true;
        $pos = $this->basePositions[$team];
        $pos->getWorld()->setBlock($pos, BlockFactory::getInstance()->get($this->flagBlocks[$team]));
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        if(!$this->gameRunning) return;

        $player = $event->getPlayer();
        $name = $player->getName();

        if(isset($this->flagCarriers[$name])) {
            $team = $this->flagCarriers[$name];
            $pos = $player->getPosition();
            $pos->getWorld()->setBlock($pos, BlockFactory::getInstance()->get($this->flagBlocks[$team]));
            $this->broadcastMessage("§cLe drapeau $team a été abandonné à ".$pos->getX().", ".$pos->getY().", ".$pos->getZ());
            unset($this->flagCarriers[$name]);

            $cause = $player->getLastDamageCause();
            if($cause instanceof EntityDamageByEntityEvent) {
                $damager = $cause->getDamager();
                if($damager instanceof Player) {
                    $damagerTeam = $this->getPlayerTeam($damager);
                    if($damagerTeam !== null && $damagerTeam !== $this->getPlayerTeam($player)) {
                        $this->scores[$damagerTeam] += 2;
                        $damager->sendMessage("§a+2 points pour avoir tué un porteur de drapeau!");
                    }
                }
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        if(!$this->gameRunning) return;

        $block = $event->getBlock();
        foreach($this->basePositions as $pos) {
            if($block->getPosition()->distance($pos) < 5) {
                $event->cancel();
                $event->getPlayer()->sendMessage("§cVous ne pouvez pas placer de blocs près du drapeau!");
                break;
            }
        }
    }

    public function updateDefenseTimes(): void {
        if(!$this->gameRunning) return;

        foreach($this->teams as $team => $players) {
            $flagPos = $this->basePositions[$team];
            $enemyNear = false;

            foreach($this->getServer()->getOnlinePlayers() as $player) {
                if($this->getPlayerTeam($player) !== $team && $player->getPosition()->distance($flagPos) < 10) {
                    $enemyNear = true;
                    break;
                }
            }

            if(!$enemyNear) {
                $this->defenseTimes[$team] = ($this->defenseTimes[$team] ?? 0) + 1;
                if($this->defenseTimes[$team] >= 120) {
                    $this->scores[$team] += 1;
                    $this->broadcastMessage("§b$team a reçu +1 point pour avoir défendu son drapeau pendant 2 minutes!");
                    $this->defenseTimes[$team] = 0;
                }
            } else {
                $this->defenseTimes[$team] = 0;
            }
        }
    }

    private function getPlayerTeam(Player $player): ?string {
        foreach($this->teams as $team => $players) {
            if(in_array($player->getName(), $players)) {
                return $team;
            }
        }
        return null;
    }

    private function broadcastMessage(string $message): void {
        foreach($this->getServer()->getOnlinePlayers() as $player) {
            $player->sendMessage($message);
        }
    }
}

class GameTask extends \pocketmine\scheduler\Task {
    private $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->updateDefenseTimes();
    }
}