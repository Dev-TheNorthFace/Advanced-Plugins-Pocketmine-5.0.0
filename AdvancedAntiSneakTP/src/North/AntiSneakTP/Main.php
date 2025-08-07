<?php

declare(strict_types=1);

namespace North\AntiSneakTP\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\Position;

class AntiSneakTP extends PluginBase implements Listener {

    private Config $config;
    private array $lastPositions = [];
    private array $violations = [];
    private array $sneakStats = [];

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antisneaktp" => [
                "enable_speed_check" => true,
                "max_sneak_speed" => 0.08,
                "max_sneak_teleport_distance" => 2.5,
                "track_chunk_crossing" => true,
                "freeze_on_flag" => true,
                "exempt_creative" => true
            ]
        ]);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if($player === null || !$packet instanceof MovePlayerPacket) {
            return;
        }

        $this->handleMovement($player, new Position($packet->position->getX(), $packet->position->getY(), $packet->position->getZ(), $player->getWorld()));
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $this->handleMovement($player, $event->getTo());
    }

    private function handleMovement(Player $player, Position $to): void {
        if(!$player->isSneaking() || ($this->config->getNested("antisneaktp.exempt_creative") && $player->getGamemode()->equals(Gamemode::CREATIVE()))) {
            return;
        }

        $from = $player->getPosition();
        $distance = $from->distance($to);

        if(!isset($this->lastPositions[$player->getName()])) {
            $this->lastPositions[$player->getName()] = $from;
            $this->sneakStats[$player->getName()] = [
                "distance" => 0,
                "ticks" => 0,
                "chunks" => []
            ];
            return;
        }

        $lastPos = $this->lastPositions[$player->getName()];
        $deltaX = abs($to->x - $lastPos->x);
        $deltaY = abs($to->y - $lastPos->y);
        $deltaZ = abs($to->z - $lastPos->z);
        $horizontalDistance = sqrt($deltaX * $deltaX + $deltaZ * $deltaZ);

        $currentChunk = $to->getFloorX() >> 4 . ":" . $to->getFloorZ() >> 4;
        $lastChunk = $lastPos->getFloorX() >> 4 . ":" . $lastPos->getFloorZ() >> 4;

        if($this->config->getNested("antisneaktp.track_chunk_crossing") && $currentChunk !== $lastChunk) {
            if(!in_array($currentChunk, $this->sneakStats[$player->getName()]["chunks"])) {
                $this->sneakStats[$player->getName()]["chunks"][] = $currentChunk;
            }
        }

        $maxDistance = $this->config->getNested("antisneaktp.max_sneak_teleport_distance");
        if($distance > $maxDistance) {
            $this->flagPlayer($player, "Téléportation de $distance blocs en sneak (max: $maxDistance)");
        }

        if($this->config->getNested("antisneaktp.enable_speed_check")) {
            $speed = $horizontalDistance / 0.05;
            $maxSpeed = $this->config->getNested("antisneaktp.max_sneak_speed");
            if($speed > $maxSpeed) {
                $this->flagPlayer($player, "Vitesse de $speed blocs/tick en sneak (max: $maxSpeed)");
            }
        }

        $this->sneakStats[$player->getName()]["distance"] += $distance;
        $this->sneakStats[$player->getName()]["ticks"]++;
        $this->lastPositions[$player->getName()] = $to;
    }

    private function flagPlayer(Player $player, string $reason): void {
        $name = $player->getName();
        $this->violations[$name] = ($this->violations[$name] ?? 0) + 1;

        $this->getLogger()->warning("SneakTP détecté: $name - $reason");

        if($this->config->getNested("antisneaktp.freeze_on_flag")) {
            $player->setImmobile(true);
        }

        if($this->violations[$name] >= 3) {
            $player->kick("SneakTP détecté (x{$this->violations[$name]})");
            unset($this->violations[$name], $this->lastPositions[$name], $this->sneakStats[$name]);
        }
    }

    public function getSneakStats(Player $player): array {
        $name = $player->getName();
        if(!isset($this->sneakStats[$name])) {
            return [];
        }

        $stats = $this->sneakStats[$name];
        $avgSpeed = $stats["ticks"] > 0 ? $stats["distance"] / $stats["ticks"] / 0.05 : 0;

        return [
            "sneaking" => $player->isSneaking(),
            "distance" => round($stats["distance"], 1),
            "avg_speed" => round($avgSpeed, 2),
            "chunks" => count($stats["chunks"]),
            "duration" => round($stats["ticks"] * 0.05, 1),
            "flagged" => ($this->violations[$name] ?? 0) > 0
        ];
    }
}