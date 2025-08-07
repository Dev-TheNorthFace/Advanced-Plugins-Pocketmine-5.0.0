<?php

declare(strict_types=1);

namespace North\AntiAFK\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerBlockBreakEvent;
use pocketmine\event\player\PlayerBlockPlaceEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private array $lastActivity = [];
    private array $movementPatterns = [];
    private array $afkPlayers = [];
    private Config $config;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "afk_after_seconds" => 180,
            "detect_fake_movement" => true,
            "detect_macro_sneak" => true,
            "exempt_players" => ["admin", "mod"],
            "kick_after_seconds" => 600,
            "notify_afk_in_chat" => true,
            "block_rewards_while_afk" => true,
            "move_to_spawn_on_afk" => false
        ]);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new AFKCheckTask($this), 20 * 30);
    }

    public function updateActivity(Player $player, string $actionType): void {
        $username = $player->getName();
        $this->lastActivity[$username] = time();

        if($actionType === "move") {
            $pos = $player->getPosition();
            $this->movementPatterns[$username][] = [$pos->x, $pos->y, $pos->z];

            if(count($this->movementPatterns[$username]) > 10) {
                array_shift($this->movementPatterns[$username]);
            }
        }

        if(isset($this->afkPlayers[$username])) {
            unset($this->afkPlayers[$username]);
            $player->setNameTag(str_replace("[AFK] ", "", $player->getNameTag()));
        }
    }

    public function checkAFKStatus(Player $player): void {
        $username = $player->getName();

        if(in_array($username, $this->config->get("exempt_players"))) {
            return;
        }

        $currentTime = time();
        $lastActive = $this->lastActivity[$username] ?? $currentTime;
        $inactiveTime = $currentTime - $lastActive;

        if($inactiveTime >= $this->config->get("afk_after_seconds")) {
            if(!isset($this->afkPlayers[$username])) {
                $this->afkPlayers[$username] = true;
                $player->setNameTag("[AFK] " . $player->getNameTag());

                if($this->config->get("notify_afk_in_chat")) {
                    $this->getServer()->broadcastMessage("§e" . $username . " est maintenant AFK");
                }
            }

            if($inactiveTime >= $this->config->get("kick_after_seconds")) {
                $player->kick("§cVous avez été kick pour AFK trop longtemps");
                unset($this->afkPlayers[$username]);
            }
        }

        if($this->config->get("detect_fake_movement") && isset($this->movementPatterns[$username])) {
            $patterns = $this->movementPatterns[$username];
            if(count($patterns) >= 5 && $this->isRepetitiveMovement($patterns)) {
                $player->sendMessage("§cAttention: Mouvements répétitifs détectés!");
                $this->updateActivity($player, "fake_move");
            }
        }
    }

    private function isRepetitiveMovement(array $positions): bool {
        $uniquePositions = [];
        foreach($positions as $pos) {
            $key = $pos[0] . ":" . $pos[1] . ":" . $pos[2];
            $uniquePositions[$key] = true;
        }
        return count($uniquePositions) <= 2;
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if($event->getFrom()->distanceSquared($event->getTo()) > 0.1) {
            $this->updateActivity($player, "move");
        }
    }

    public function onChat(PlayerChatEvent $event): void {
        $this->updateActivity($event->getPlayer(), "chat");
    }

    public function onInteract(PlayerInteractEvent $event): void {
        $this->updateActivity($event->getPlayer(), "interact");
    }

    public function onItemUse(PlayerItemUseEvent $event): void {
        $this->updateActivity($event->getPlayer(), "item_use");
    }

    public function onBlockBreak(PlayerBlockBreakEvent $event): void {
        $this->updateActivity($event->getPlayer(), "block_break");
    }

    public function onBlockPlace(PlayerBlockPlaceEvent $event): void {
        $this->updateActivity($event->getPlayer(), "block_place");
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        if($packet instanceof MovePlayerPacket) {
            $player = $event->getOrigin()->getPlayer();
            if($player !== null) {
                $this->updateActivity($player, "packet_move");
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $username = $event->getPlayer()->getName();
        unset($this->lastActivity[$username]);
        unset($this->movementPatterns[$username]);
        unset($this->afkPlayers[$username]);
    }

    public function isAFK(Player $player): bool {
        return isset($this->afkPlayers[$player->getName()]);
    }
}

class AFKCheckTask extends Task {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player) {
            $this->plugin->checkAFKStatus($player);
        }
    }
}