<?php

namespace North\AntiFly\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if ($this->isIllegallyFlying($player)) {
            $this->kickPlayer($player);
        }
    }

    private function isIllegallyFlying(Player $player): bool {
        if ($player->getGamemode() === GameMode::CREATIVE() || $player->getGamemode() === GameMode::SPECTATOR()) {
            return false;
        }

        if ($player->hasPermission("fly.bypass")) {
            return false;
        }

        return $player->isFlying();
    }

    private function kickPlayer(Player $player): void {
        $player->kick(
            TextFormat::colorize("§cVous avez été kick pour vol (fly) non autorisé!\n§r§7Contactez un staff si vous pensez que c'est une erreur.")
        );
        $this->getLogger()->warning("Joueur " . $player->getName() . " kick pour fly non autorisé.");
    }
}