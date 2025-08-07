<?php

declare(strict_types=1);

namespace North\AntiESPChest\Commands\CheatTestCommand;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use North\AntiESPChest\Main;

class CheatTestCommand extends Command {

    private Main $plugin;

    public function __construct(Main $plugin) {
        parent::__construct(
            "cheattest",
            "Teste le système de détection AntiESPChest",
            "/cheattest <type> [joueur]",
            ["ctest"]
        );
        $this->setPermission("antiespchest.command.test");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void {
        if (!$this->testPermission($sender)) {
            return;
        }

        if (count($args) < 1) {
            $sender->sendMessage(TF::RED . "Usage: " . $this->getUsage());
            return;
        }

        $target = $sender instanceof Player ? $sender : null;
        if (isset($args[1])) {
            $target = $this->plugin->getServer()->getPlayerExact($args[1]);
            if ($target === null) {
                $sender->sendMessage(TF::RED . "Joueur introuvable!");
                return;
            }
        }

        if ($target === null) {
            $sender->sendMessage(TF::RED . "Vous devez spécifier un joueur!");
            return;
        }

        switch (strtolower($args[0])) {
            case "ghost":
                $this->testGhostChest($sender, $target);
                break;
            case "trajectory":
                $this->testTrajectory($sender, $target);
                break;
            case "vision":
                $this->testVision($sender, $target);
                break;
            case "all":
                $this->runAllTests($sender, $target);
                break;
            default:
                $sender->sendMessage(TF::RED . "Tests disponibles: ghost, trajectory, vision, all");
        }
    }

    private function testGhostChest(CommandSender $sender, Player $target): void {
        $ghostChests = $this->plugin->getGhostChests();
        if (empty($ghostChests)) {
            $sender->sendMessage(TF::YELLOW . "Aucun coffre fantôme actif");
            return;
        }

        $posKey = array_key_first($ghostChests);
        [$world, $x, $y, $z] = explode(":", $posKey);

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($world);
        if ($world === null) {
            $sender->sendMessage(TF::RED . "Monde des coffres fantômes introuvable!");
            return;
        }

        $target->teleport(new Position((float)$x, (float)$y, (float)$z, $world));
        $sender->sendMessage(TF::GREEN . "Téléportation vers un coffre fantôme - vérifiez les logs");
    }

    private function testTrajectory(CommandSender $sender, Player $target): void {
        $targetBlocks = $this->plugin->getTargetBlocks();
        if (empty($targetBlocks)) {
            $sender->sendMessage(TF::YELLOW . "Aucun coffre cible enregistré");
            return;
        }

        $chestData = reset($targetBlocks);
        $chestPos = $chestData["position"];

        $startPos = $chestPos->add(-5, 0, -5);
        $target->teleport($startPos);

        $sender->sendMessage(sprintf(
            TF::GREEN . "Test de trajectoire configuré - Dirigez-vous vers X: %d Y: %d Z: %d",
            $chestPos->getFloorX(),
            $chestPos->getFloorY(),
            $chestPos->getFloorZ()
        ));
    }

    private function testVision(CommandSender $sender, Player $target): void {
        $targetBlocks = $this->plugin->getTargetBlocks();
        if (empty($targetBlocks)) {
            $sender->sendMessage(TF::YELLOW . "Aucun coffre cible enregistré");
            return;
        }

        $chestData = reset($targetBlocks);
        $chestPos = $chestData["position"];

        $testPos = $chestPos->add(3, 0, 3);
        $target->teleport($testPos);

        $hasLOS = $this->plugin->getRaycastUtils()->hasLineOfSight($target, $chestPos);

        $sender->sendMessage(sprintf(
            TF::GREEN . "Test de vision - Ligne de vue: %s",
            $hasLOS ? TF::GREEN . "OUI" : TF::RED . "NON"
        ));
    }

    private function runAllTests(CommandSender $sender, Player $target): void {
        $sender->sendMessage(TF::GOLD . "Lancement de tous les tests...");
        $this->testGhostChest($sender, $target);
        $this->testTrajectory($sender, $target);
        $this->testVision($sender, $target);
    }

    public function getPlugin(): Main {
        return $this->plugin;
    }
}