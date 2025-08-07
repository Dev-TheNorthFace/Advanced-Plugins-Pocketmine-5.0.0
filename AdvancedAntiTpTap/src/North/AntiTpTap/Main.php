<?php

declare(strict_types=1);

namespace North\AntiTpTap\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\World;

class Main extends PluginBase implements Listener {

    private Config $config;
    private array $lastPositions = [];
    private array $violations = [];
    private array $lastActions = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if($player === null) return;

        if($packet instanceof InventoryTransactionPacket) {
            $trData = $packet->trData;

            if($trData instanceof UseItemOnEntityTransactionData) {
                $target = $player->getWorld()->getEntity($trData->getEntityRuntimeId());
                if($target !== null) {
                    $this->checkInteraction($player, $target->getPosition(), "entity");
                }
            }
        }
    }

    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $this->checkInteraction($player, $block->getPosition(), "block");
    }

    private function checkInteraction(Player $player, Vector3 $targetPos, string $type): void {
        $distance = $player->getPosition()->distance($targetPos);
        $maxDistance = $type === "entity" ? $this->config->get("max_attack_distance", 4.2) : $this->config->get("max_interact_distance", 5.0);

        if($distance > $maxDistance) {
            $this->flagPlayer($player, "distance", $distance);
        }
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();

        if($from->distance($to) > 5 && !$player->hasMovementTeleport()) {
            $this->flagPlayer($player, "teleport_spike", $from->distance($to));
        }

        $this->lastPositions[$player->getName()] = [
            "position" => $to,
            "time" => microtime(true)
        ];
    }

    private function flagPlayer(Player $player, string $reason, float $data): void {
        $name = $player->getName();

        if(($this->config->get("exempt_admins", true) && $player->hasPermission("antitptap.bypass")) || !$player->isConnected()) {
            return;
        }

        $this->violations[$name][$reason] = ($this->violations[$name][$reason] ?? 0) + 1;
        $this->lastActions[$name] = time();

        $this->getLogger()->warning("TpTap suspected from {$name}: {$reason} (value: {$data})");

        $violationCount = count($this->violations[$name]);

        if($violationCount === 1) {
            $player->sendMessage("§cWarning: Suspicious movement detected!");
        } elseif($violationCount === 2) {
            if($this->config->get("freeze_on_tp_spike", true)) {
                $player->setImmobile(true);
                $player->sendMessage("§cYou have been frozen for suspicious teleportation.");
            }
        } elseif($violationCount >= 3) {
            $player->kick("§cKicked for suspicious teleportation");
        }
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $name = $event->getPlayer()->getName();
        unset($this->lastPositions[$name], $this->violations[$name], $this->lastActions[$name]);
    }

    public function getLastPosition(Player $player): ?array {
        return $this->lastPositions[$player->getName()] ?? null;
    }
}