<?php

namespace North\AntiSpeedHack\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\effect\VanillaEffects;

class Main extends PluginBase implements Listener {

    private $violations = [];
    private $lastPositions = [];
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antispeedhack" => [
                "max_walk_speed" => 0.215,
                "max_sprint_speed" => 0.27,
                "max_jump_speed" => 0.35,
                "max_in_water" => 0.13,
                "max_sneak" => 0.1,
                "check_diagonal_boost" => true,
                "freeze_on_detect" => true,
                "exempt_creative" => true,
                "allowed_speed_effect_level" => 2
            ]
        ]);
    }

    public function onMove(PlayerMoveEvent $event) {
        $player = $event->getPlayer();
        if ($player->isCreative() && $this->config->getNested("antispeedhack.exempt_creative")) return;

        $from = $event->getFrom();
        $to = $event->getTo();
        $distance = $from->distance($to);

        $maxSpeed = $this->getMaxAllowedSpeed($player);
        $currentSpeed = $distance / 0.05;

        if ($currentSpeed > $maxSpeed) {
            $this->handleViolation($player, $currentSpeed, $maxSpeed);
        } else {
            if (isset($this->violations[$player->getName()])) {
                unset($this->violations[$player->getName()]);
            }
        }

        $this->lastPositions[$player->getName()] = [
            "position" => $to,
            "time" => microtime(true)
        ];
    }

    private function getMaxAllowedSpeed(Player $player): float {
        $baseSpeed = $this->config->getNested("antispeedhack.max_walk_speed");
        $effectManager = $player->getEffects();

        if ($player->isSprinting()) {
            $baseSpeed = $this->config->getNested("antispeedhack.max_sprint_speed");
        }

        if ($effectManager->has(VanillaEffects::SPEED())) {
            $speedEffect = $effectManager->get(VanillaEffects::SPEED());
            $level = $speedEffect->getEffectLevel();
            $allowedLevel = $this->config->getNested("antispeedhack.allowed_speed_effect_level");

            if ($level > $allowedLevel) {
                $level = $allowedLevel;
            }

            $baseSpeed *= 1 + ($level * 0.2);
        }

        if ($player->isSneaking()) {
            return min($baseSpeed, $this->config->getNested("antispeedhack.max_sneak"));
        }

        if ($player->isUnderwater() || $player->isInWater()) {
            return min($baseSpeed, $this->config->getNested("antispeedhack.max_in_water"));
        }

        return $baseSpeed;
    }

    private function handleViolation(Player $player, float $currentSpeed, float $maxSpeed) {
        $name = $player->getName();

        if (!isset($this->violations[$name])) {
            $this->violations[$name] = 0;
        }

        $this->violations[$name]++;

        $violationCount = $this->violations[$name];
        $speedDiff = $currentSpeed - $maxSpeed;

        if ($violationCount > 10 || $speedDiff > 0.5) {
            $this->getServer()->getLogger()->warning("Possible SpeedHack detected: {$name} (Speed: {$currentSpeed}, Max: {$maxSpeed}, Violations: {$violationCount})");

            if ($this->config->getNested("antispeedhack.freeze_on_detect")) {
                $player->setImmobile(true);
                $player->sendMessage("§cYou have been frozen for suspicious movement.");
            }

            $this->violations[$name] = 0;
        }
    }
}