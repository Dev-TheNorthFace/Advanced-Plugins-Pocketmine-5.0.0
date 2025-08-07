<?php

namespace North\AntiSpider\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;

class Main implements Listener {

    private array $wallContactTicks = [];
    private Config $config;

    public function __construct(Config $config) {
        $this->config = $config;
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();

        if($player->getAllowFlight() || $player->isCreative()) {
            return;
        }

        $from = $event->getFrom();
        $to = $event->getTo();

        $yDiff = $to->getY() - $from->getY();

        if($yDiff <= 0) {
            return;
        }

        $this->checkVerticalMovement($player, $yDiff, $to);
        $this->checkWallContact($player, $to);
        $this->checkNoSupport($player, $to);
    }

    private function checkVerticalMovement(Player $player, float $yDiff, Position $to): void {
        if(!$this->config->get("enable_vertical_climb_check", true)) {
            return;
        }

        $maxRise = $this->config->get("max_allowed_y_rise_per_tick", 0.5);

        if($yDiff > $maxRise && !$player->isOnGround() && !$player->isOnLadder()) {
            $this->flagSpider($player, "Y+ sans saut ni échelle");
        }
    }

    private function checkWallContact(Player $player, Position $to): void {
        if(!$player->isCollidedHorizontally() || $player->getMotion()->y <= 0.1) {
            unset($this->wallContactTicks[$player->getId()]);
            return;
        }

        $playerId = $player->getId();
        $this->wallContactTicks[$playerId] = ($this->wallContactTicks[$playerId] ?? 0) + 1;

        $maxTicks = $this->config->get("max_ticks_against_wall", 4);

        if($this->wallContactTicks[$playerId] >= $maxTicks) {
            $this->flagSpider($player, "Contact prolongé avec mur");
        }
    }

    private function checkNoSupport(Player $player, Position $to): void {
        $world = $player->getWorld();
        $positionBelow = $to->subtract(0, 0.5, 0);

        if($world->getBlock($positionBelow)->isSolid()) {
            return;
        }

        if($player->getMotion()->y > 0 && !$player->isOnLadder()) {
            $this->flagSpider($player, "Montée sans support");
        }
    }

    private function flagSpider(Player $player, string $reason): void {
        if($this->config->get("freeze_on_detected", true)) {
            $player->setMotion($player->getMotion()->withComponents(0, 0, 0));
        }

        $player->sendMessage("§cAnti-Cheat: Spider détecté ($reason)");

        $this->logDetection($player, $reason);
    }

    private function logDetection(Player $player, string $reason): void {
        $logData = [
            "player" => $player->getName(),
            "reason" => $reason,
            "position" => $player->getPosition()->__toString(),
            "time" => date("Y-m-d H:i:s")
        ];

        file_put_contents("spider_detections.log", json_encode($logData) . PHP_EOL, FILE_APPEND);
    }
}