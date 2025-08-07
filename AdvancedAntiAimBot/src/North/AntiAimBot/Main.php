<?php

declare(strict_types=1);

namespace North\AntiAimBot\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private array $playerData = [];
    private Config $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antiaimbot" => [
                "enable_angle_accuracy_check" => true,
                "yaw_threshold" => 4.5,
                "pitch_threshold" => 4.0,
                "headshot_ratio_threshold" => 0.85,
                "ticks_required_for_lock" => 5,
                "detect_through_walls" => true,
                "freeze_on_detected" => true,
                "exempt_staff" => true
            ]
        ]);
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if($this->config->getNested("antiaimbot.exempt_staff") && $player->hasPermission("antiaimbot.bypass")) return;

        $from = $event->getFrom();
        $to = $event->getTo();

        if($from->yaw !== $to->yaw || $from->pitch !== $to->pitch) {
            $this->checkRotation($player, $from, $to);
        }
    }

    private function checkRotation(Player $player, $from, $to): void {
        $yawDiff = abs($to->yaw - $from->yaw);
        $pitchDiff = abs($to->pitch - $from->pitch);

        if(!isset($this->playerData[$player->getName()])) {
            $this->playerData[$player->getName()] = [
                "rotation_ticks" => 0,
                "last_yaw" => $to->yaw,
                "last_pitch" => $to->pitch,
                "headshots" => 0,
                "total_hits" => 0,
                "last_target" => null
            ];
        }

        $data = &$this->playerData[$player->getName()];

        if($yawDiff < $this->config->getNested("antiaimbot.yaw_threshold") &&
            $pitchDiff < $this->config->getNested("antiaimbot.pitch_threshold")) {
            $data["rotation_ticks"]++;
        } else {
            $data["rotation_ticks"] = 0;
        }

        if($data["rotation_ticks"] >= $this->config->getNested("antiaimbot.ticks_required_for_lock")) {
            $this->flagPlayer($player, "Aimbot (rotation lock detected)");
        }

        $data["last_yaw"] = $to->yaw;
        $data["last_pitch"] = $to->pitch;
    }

    public function onDamage(EntityDamageByEntityEvent $event): void {
        $damager = $event->getDamager();
        $victim = $event->getEntity();

        if(!$damager instanceof Player || !$victim instanceof Player) return;
        if($this->config->getNested("antiaimbot.exempt_staff") && $damager->hasPermission("antiaimbot.bypass")) return;

        $damagerName = $damager->getName();
        if(!isset($this->playerData[$damagerName])) return;

        $data = &$this->playerData[$damagerName];
        $data["total_hits"]++;

        $hitbox = $this->getHitbox($damager, $victim);
        if($hitbox === "head") {
            $data["headshots"]++;
        }

        $headshotRatio = $data["headshots"] / $data["total_hits"];
        if($headshotRatio > $this->config->getNested("antiaimbot.headshot_ratio_threshold") && $data["total_hits"] >= 10) {
            $this->flagPlayer($damager, "Aimbot (high headshot ratio: " . round($headshotRatio * 100) . "%)");
        }

        if($this->config->getNested("antiaimbot.detect_through_walls") && !$damager->canSee($victim)) {
            $this->flagPlayer($damager, "Aimbot (hit through wall)");
        }
    }

    private function getHitbox(Player $damager, Player $victim): string {
        $damagerPos = $damager->getPosition();
        $victimPos = $victim->getPosition();
        $yDiff = $victimPos->y - $damagerPos->y;

        if($yDiff > 1.5) return "head";
        if($yDiff > 0.5) return "body";
        return "legs";
    }

    private function flagPlayer(Player $player, string $reason): void {
        $playerName = $player->getName();
        $this->getLogger()->warning("$playerName flagged for $reason");

        if($this->config->getNested("antiaimbot.freeze_on_detected")) {
            $player->setImmobile(true);
        }

        $this->getServer()->broadcastMessage("§c[AntiCheat] $playerName detected using Aimbot");
    }
}