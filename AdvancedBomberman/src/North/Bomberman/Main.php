<?php

namespace North\Bomberman\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\block\Block;
use pocketmine\block\TNT;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {

    private $arenas = [];
    private $players = [];
    private $config;
    private $gameWorld;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->initArena();
        $this->getScheduler()->scheduleRepeatingTask(new GameTask($this), 20);
    }

    private function initArena(): void {
        $this->gameWorld = $this->getServer()->getWorldManager()->getWorldByName("bomberman");
        if($this->gameWorld === null) {
            $this->getLogger()->warning("World 'bomberman' not found!");
            return;
        }

        $this->arenas["default"] = [
            "world" => $this->gameWorld,
            "players" => [],
            "blocks" => [],
            "explosions" => []
        ];
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
        if($cmd->getName() === "bomberman") {
            if(!$sender instanceof Player) return false;

            if(isset($args[0])) {
                switch(strtolower($args[0])) {
                    case "join":
                        $this->joinGame($sender);
                        return true;
                    case "leave":
                        $this->leaveGame($sender);
                        return true;
                }
            }
        }
        return false;
    }

    private function joinGame(Player $player): void {
        $this->players[$player->getName()] = [
            "tnt_count" => 1,
            "range" => 2,
            "speed" => 1,
            "shield" => false
        ];

        $arena = "default";
        $this->arenas[$arena]["players"][$player->getName()] = $player;
        $player->teleport($this->gameWorld->getSpawnLocation());
        $player->sendMessage("You joined Bomberman!");
    }

    private function leaveGame(Player $player): void {
        unset($this->players[$player->getName()]);
        foreach($this->arenas as $arenaName => $arenaData) {
            if(isset($arenaData["players"][$player->getName()])) {
                unset($this->arenas[$arenaName]["players"][$player->getName()]);
            }
        }
        $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        $player->sendMessage("You left Bomberman!");
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if(!isset($this->players[$player->getName()])) return;

        if($block instanceof TNT) {
            $event->cancel();
            $this->placeBomb($player, $block->getPosition());
        }
    }

    private function placeBomb(Player $player, Vector3 $pos): void {
        $world = $player->getWorld();
        $world->setBlock($pos, Block::get(Block::TNT));

        $this->getScheduler()->scheduleDelayedTask(new BombTask($this, $pos, $player->getName()), 20 * 3);
    }

    public function explodeBomb(Vector3 $pos, string $playerName): void {
        $world = $this->gameWorld;
        $range = $this->players[$playerName]["range"] ?? 2;

        $this->createExplosion($pos, $range, $world);

        for($i = 1; $i <= $range; $i++) {
            $this->createExplosion($pos->add($i, 0, 0), 0, $world);
            $this->createExplosion($pos->subtract($i, 0, 0), 0, $world);
            $this->createExplosion($pos->add(0, 0, $i), 0, $world);
            $this->createExplosion($pos->subtract(0, 0, $i), 0, $world);
        }
    }

    private function createExplosion(Vector3 $pos, int $power, World $world): void {
        $blocks = [];

        for($x = -$power; $x <= $power; $x++) {
            for($y = -$power; $y <= $power; $y++) {
                for($z = -$power; $z <= $power; $z++) {
                    $block = $world->getBlockAt($pos->x + $x, $pos->y + $y, $pos->z + $z);
                    if($block->getId() !== Block::BEDROCK) {
                        $blocks[] = $block;
                    }
                }
            }
        }

        foreach($blocks as $block) {
            if($block->getId() !== Block::AIR) {
                $this->arenas["default"]["blocks"][] = [
                    "x" => $block->getPosition()->x,
                    "y" => $block->getPosition()->y,
                    "z" => $block->getPosition()->z,
                    "id" => $block->getId(),
                    "meta" => $block->getMeta()
                ];
                $world->setBlock($block->getPosition(), Block::get(Block::AIR));
            }
        }

        foreach($this->arenas["default"]["players"] as $player) {
            if($player->getPosition()->distance($pos) <= $power + 1) {
                if(!$this->players[$player->getName()]["shield"]) {
                    $this->leaveGame($player);
                }
            }
        }
    }

    public function onExplode(EntityExplodeEvent $event): void {
        $event->cancel();
    }

    public function restoreBlocks(): void {
        foreach($this->arenas["default"]["blocks"] as $blockData) {
            $pos = new Vector3($blockData["x"], $blockData["y"], $blockData["z"]);
            $this->gameWorld->setBlock($pos, Block::get($blockData["id"], $blockData["meta"]));
        }
        $this->arenas["default"]["blocks"] = [];
    }

    public function checkGameEnd(): void {
        foreach($this->arenas as $arenaName => $arenaData) {
            if(count($arenaData["players"]) === 1) {
                $winner = array_pop($arenaData["players"]);
                $this->getServer()->broadcastMessage($winner->getName() . " won the Bomberman game!");
                $this->restoreBlocks();
            }
        }
    }
}

class BombTask extends Task {
    private $plugin;
    private $pos;
    private $playerName;

    public function __construct(Main $plugin, Vector3 $pos, string $playerName) {
        $this->plugin = $plugin;
        $this->pos = $pos;
        $this->playerName = $playerName;
    }

    public function onRun(): void {
        $this->plugin->explodeBomb($this->pos, $this->playerName);
    }
}

class GameTask extends Task {
    private $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->checkGameEnd();
    }
}