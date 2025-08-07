<?php

declare(strict_types=1);

namespace North\AntiESPChest\Utils\RaycastUtils;

use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use North\AntiESPChest\Main;

class RaycastUtils {

    private const RAYCAST_STEP = 0.5;
    private const MAX_RAY_DISTANCE = 100;

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function hasLineOfSight(Player $player, Position $target): bool {
        $start = $player->getEyePos();
        $end = $target->add(0.5, 0.5, 0.5);

        if ($start->distance($end) > $this->getMaxDetectionDistance()) {
            return false;
        }

        $direction = $end->subtractVector($start)->normalize();
        $currentPos = $start->asVector3();
        $world = $player->getWorld();

        for ($i = 0; $i < self::MAX_RAY_DISTANCE; $i += self::RAYCAST_STEP) {
            $currentPos = $currentPos->addVector($direction->multiply(self::RAYCAST_STEP));

            if ($currentPos->distanceSquared($start) > $end->distanceSquared($start)) {
                return true;
            }

            $block = $world->getBlockAt(
                (int)floor($currentPos->x),
                (int)floor($currentPos->y),
                (int)floor($currentPos->z)
            );

            if ($block->isSolid() && !$this->plugin->isTargetBlock($block)) {
                return false;
            }
        }

        return false;
    }

    private function getMaxDetectionDistance(): float {
        return (float)$this->plugin->getConfig()->getNested("detection.max_hidden_distance", 6);
    }

    public function getBlocksBetween(Player $player, Position $target): array {
        $blocks = [];
        $start = $player->getEyePos();
        $end = $target->add(0.5, 0.5, 0.5);
        $direction = $end->subtractVector($start)->normalize();
        $currentPos = $start->asVector3();
        $world = $player->getWorld();

        for ($i = 0; $i < self::MAX_RAY_DISTANCE; $i += self::RAYCAST_STEP) {
            $currentPos = $currentPos->addVector($direction->multiply(self::RAYCAST_STEP));

            if ($currentPos->distanceSquared($start) > $end->distanceSquared($start)) {
                break;
            }

            $block = $world->getBlockAt(
                (int)floor($currentPos->x),
                (int)floor($currentPos->y),
                (int)floor($currentPos->z)
            );

            $blocks[] = $block;
        }

        return $blocks;
    }
}