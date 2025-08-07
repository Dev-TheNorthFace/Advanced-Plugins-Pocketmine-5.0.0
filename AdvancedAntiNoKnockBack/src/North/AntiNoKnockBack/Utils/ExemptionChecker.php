<?php

namespace North\AntiNoKnockBack\Utils\ExemptionChecker;

use pocketmine\player\Player;
use pocketmine\block\Block;
use pocketmine\world\World;
use pocketmine\entity\effect\VanillaEffects;
use North\AntiNoKnockBack\Main;

class ExemptionChecker {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function isExempt(Player $player, string $checkType = 'all'): bool {
        $config = $this->plugin->getKBConfig();
        return match($checkType) {
            'ping' => $this->checkPingExemption($player, $config),
            'environment' => $this->checkEnvironmentExemption($player, $config),
            'effects' => $this->checkEffectExemption($player),
            'movement' => $this->checkMovementExemption($player),
            default => $this->checkPingExemption($player, $config) ||
                $this->checkEnvironmentExemption($player, $config) ||
                $this->checkEffectExemption($player) ||
                $this->checkMovementExemption($player)
        };
    }

    private function checkPingExemption(Player $player, array $config): bool {
        if ($config['exempt_when_ping_above'] <= 0) {
            return false;
        }

        $ping = $player->getNetworkSession()->getPing();
        return $ping > $config['exempt_when_ping_above'];
    }

    private function checkEnvironmentExemption(Player $player, array $config): bool {
        $world = $player->getWorld();
        $position = $player->getPosition();
        if ($player->isUnderwater() || $world->getBlock($position)->getTypeId() === Block::WATER) {
            return true;
        }

        if ($config['exempt_when_stuck'] && $this->isPlayerStuck($player)) {
            return true;
        }

        $footBlock = $world->getBlock($position->subtract(0, 0.1, 0));
        return in_array($footBlock->getTypeId(), [
            Block::SLIME_BLOCK,
            Block::COBWEB,
            Block::HONEY_BLOCK
        ]);
    }

    private function isPlayerStuck(Player $player): bool {
        $direction = $player->getDirectionVector();
        $world = $player->getWorld();
        for ($i = 1; $i <= 3; $i++) {
            $checkPos = $player->getPosition()
                ->subtract($direction->x * $i, 0, $direction->z * $i);

            if (!$world->getBlock($checkPos)->isTransparent()) {
                return true;
            }
        }

        return false;
    }

    private function checkEffectExemption(Player $player): bool {
        $effects = $player->getEffects();

        return $effects->has(VanillaEffects::RESISTANCE()) ||
            $effects->has(VanillaEffects::FIRE_RESISTANCE()) ||
            $effects->has(VanillaEffects::LEVITATION());
    }

    private function checkMovementExemption(Player $player): bool {
        if (!$player->isOnGround()) {
            return true;
        }

        if ($player->isSneaking()) {
            $footBlock = $player->getWorld()->getBlock(
                $player->getPosition()->subtract(0, 0.1, 0)
            );

            return $footBlock instanceof \pocketmine\block\Stair ||
                $footBlock instanceof \pocketmine\block\Slab;
        }

        return false;
    }

    public function getExemptionReason(Player $player): string {
        if ($this->checkPingExemption($player, $this->plugin->getKBConfig())) {
            return "Ping trop élevé";
        }

        if ($this->checkEffectExemption($player)) {
            return "Effets spéciaux actifs";
        }

        if ($this->checkEnvironmentExemption($player, $this->plugin->getKBConfig())) {
            return "Environnement bloquant";
        }

        if ($this->checkMovementExemption($player)) {
            return "Mouvement spécial détecté";
        }

        return "Aucune exemption";
    }
}