<?php

declare(strict_types=1);

namespace North\AntiESPChest\Tasks\GhostChestTask;

use pocketmine\scheduler\Task;
use pocketmine\world\World;
use pocketmine\world\Position;
use North\AntiESPChest\Main;

class GhostChestTask extends Task {

    private const CHEST_LIFETIME = 1200;
    private const CHEST_SPAWN_RADIUS = 1000;
    private const CHESTS_PER_WORLD = 5;

    private Main $plugin;
    private array $activeChests = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->rotateGhostChests();
    }

    public function onRun(): void {
        $this->rotateGhostChests();
        $this->cleanupExpiredChests();
    }

    private function rotateGhostChests(): void {
        foreach ($this->plugin->getServer()->getWorldManager()->getWorlds() as $world) {
            $worldName = $world->getFolderName();
            $worldChests = 0;

            foreach ($this->activeChests as $key => $chest) {
                if ($chest['world'] === $worldName) {
                    $worldChests++;
                }
            }

            while ($worldChests < self::CHESTS_PER_WORLD) {
                $this->spawnNewGhostChest($world);
                $worldChests++;
            }
        }
    }

    private function spawnNewGhostChest(World $world): void {
        $attempts = 0;
        $maxAttempts = 10;

        while ($attempts < $maxAttempts) {
            $x = mt_rand(-self::CHEST_SPAWN_RADIUS, self::CHEST_SPAWN_RADIUS);
            $z = mt_rand(-self::CHEST_SPAWN_RADIUS, self::CHEST_SPAWN_RADIUS);
            $y = $world->getHighestBlockAt($x, $z) + 1;

            $posKey = $this->plugin->posToKey($x, $y, $z, $world->getFolderName());

            if (!isset($this->activeChests[$posKey]) && !isset($this->plugin->getGhostChests()[$posKey])) {
                $this->activeChests[$posKey] = [
                    'position' => new Position($x, $y, $z, $world),
                    'world' => $world->getFolderName(),
                    'created' => time()
                ];
                $this->plugin->getGhostChests()[$posKey] = true;
                break;
            }

            $attempts++;
        }
    }

    private function cleanupExpiredChests(): void {
        $now = time();
        $removed = 0;

        foreach ($this->activeChests as $key => $chest) {
            if ($now - $chest['created'] > self::CHEST_LIFETIME) {
                unset($this->activeChests[$key]);
                unset($this->plugin->getGhostChests()[$key]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->plugin->getLogger()->debug("Suppression de $removed coffres fantômes expirés");
        }
    }

    public function getActiveChestCount(): int {
        return count($this->activeChests);
    }
}