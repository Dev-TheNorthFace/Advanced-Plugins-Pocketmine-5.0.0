<?php

declare(strict_types=1);

namespace North\AntiESPChest\Utils\TrajectoryAnalyzer;

use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use North\AntiESPChest\Main;

class TrajectoryAnalyzer {

    private const HISTORY_SIZE = 30;
    private const DIRECTNESS_THRESHOLD = 0.85;
    private const MIN_DISTANCE = 5.0;
    private const MAX_DISTANCE = 20.0;

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function analyze(Player $player, array &$playerData): void {
        if (count($playerData["movements"]) < 10) {
            return;
        }

        foreach ($this->plugin->getTargetBlocks() as $chestData) {
            $chestPos = $chestData["position"];
            $distance = $player->getPosition()->distance($chestPos);

            if ($distance < self::MIN_DISTANCE || $distance > self::MAX_DISTANCE) {
                continue;
            }

            $directness = $this->calculateDirectness($playerData["movements"], $chestPos);
            $chestKey = $this->plugin->posToKey($chestPos);

            if ($directness > self::DIRECTNESS_THRESHOLD && !$this->plugin->getRaycastUtils()->hasLineOfSight($player, $chestPos)) {
                $playerData["suspect_targets"][$chestKey] = ($playerData["suspect_targets"][$chestKey] ?? 0) + 1;

                if ($playerData["suspect_targets"][$chestKey] > 3) {
                    $this->plugin->flagPlayer($player, "Trajectoire suspecte vers coffre caché (Directness: ".round($directness, 2).")");
                }
            }
        }
    }

    private function calculateDirectness(array $movementHistory, Position $target): float {
        $directHits = 0;
        $totalChecks = 0;

        foreach ($movementHistory as $move) {
            $directionToTarget = $target->subtract($move["position"])->normalize();
            $dotProduct = $move["direction"]->dot($directionToTarget);

            if ($dotProduct > 0.95) {
                $directHits++;
            }
            $totalChecks++;
        }

        return $totalChecks > 0 ? $directHits / $totalChecks : 0;
    }

    public function calculatePathEfficiency(array $movementHistory, Position $target): float {
        if (count($movementHistory) < 2) {
            return 0.0;
        }

        $firstPos = $movementHistory[0]["position"];
        $directDistance = $firstPos->distance($target);
        $actualDistance = 0.0;

        for ($i = 1; $i < count($movementHistory); $i++) {
            $actualDistance += $movementHistory[$i-1]["position"]->distance($movementHistory[$i]["position"]);
        }

        return $directDistance > 0 ? $directDistance / $actualDistance : 0;
    }

    public function hasSharpDirectionChanges(array $movementHistory, float $threshold = 30.0): bool {
        if (count($movementHistory) < 3) {
            return false;
        }

        for ($i = 1; $i < count($movementHistory) - 1; $i++) {
            $prevDir = $movementHistory[$i-1]["direction"];
            $currentDir = $movementHistory[$i]["direction"];

            $angle = rad2deg(acos($prevDir->dot($currentDir)));
            if ($angle > $threshold) {
                return true;
            }
        }

        return false;
    }
}