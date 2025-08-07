<?php

namespace North\FlipCombo\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private $games = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Commande uniquement utilisable en jeu.");
            return true;
        }

        if(strtolower($command->getName()) === "flipcombo") {
            if(isset($this->games[$sender->getName()])) {
                $this->handleGameCommand($sender, $args);
                return true;
            }

            if(!isset($args[0])) {
                $sender->sendMessage(TextFormat::RED . "Usage: /flipcombo <mise>");
                return true;
            }

            $bet = (int)$args[0];
            if($bet <= 0) {
                $sender->sendMessage(TextFormat::RED . "La mise doit être supérieure à 0.");
                return true;
            }

            if(!$this->getServer()->getPluginManager()->getPlugin("EconomyAPI")->myMoney($sender) >= $bet) {
                $sender->sendMessage(TextFormat::RED . "Vous n'avez pas assez d'argent.");
                return true;
            }

            $this->games[$sender->getName()] = [
                "bet" => $bet,
                "multiplier" => 1,
                "stage" => 0,
                "choice" => null
            ];

            $sender->sendMessage(TextFormat::GOLD . "/flipcombo - Jeu de pile ou face en combo");
            $sender->sendMessage(TextFormat::YELLOW . "Mise: " . TextFormat::GREEN . "$" . $bet);
            $sender->sendMessage(TextFormat::YELLOW . "Choisissez: " . TextFormat::GREEN . "/flipcombo pile" . TextFormat::YELLOW . " ou " . TextFormat::RED . "/flipcombo face");
            return true;
        }
        return false;
    }

    private function handleGameCommand(Player $player, array $args): void {
        $game = $this->games[$player->getName()];

        if($game["stage"] === 0 && !isset($args[0])) {
            $player->sendMessage(TextFormat::RED . "Choisissez pile ou face pour commencer.");
            return;
        }

        if($game["stage"] === 0 && isset($args[0])) {
            $choice = strtolower($args[0]);
            if($choice !== "pile" && $choice !== "face") {
                $player->sendMessage(TextFormat::RED . "Choix invalide. Utilisez /flipcombo pile ou /flipcombo face");
                return;
            }

            $this->games[$player->getName()]["choice"] = $choice;
            $this->flipCoin($player);
            return;
        }

        if(isset($args[0])) {
            $action = strtolower($args[0]);
            if($action === "continue") {
                $this->continueGame($player);
            } elseif($action === "cashout") {
                $this->cashOut($player);
            } else {
                $player->sendMessage(TextFormat::RED . "Option invalide. Utilisez /flipcombo continue ou /flipcombo cashout");
            }
        }
    }

    private function flipCoin(Player $player): void {
        $game = $this->games[$player->getName()];
        $result = mt_rand(0, 1) === 0 ? "pile" : "face";

        $player->sendMessage(TextFormat::GOLD . "[🔄🔄🔄] → [" . strtoupper($result) . "]");

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $result, $game): void {
            if($result === $game["choice"]) {
                $newMultiplier = $game["multiplier"] * 2;
                $this->games[$player->getName()]["multiplier"] = $newMultiplier;
                $this->games[$player->getName()]["stage"]++;

                $player->sendMessage(TextFormat::GREEN . "✔ Victoire! Multiplicateur: x" . $newMultiplier);

                if($game["stage"] < 3) {
                    $player->sendMessage(TextFormat::YELLOW . "Vous pouvez: " . TextFormat::GREEN . "/flipcombo continue" . TextFormat::YELLOW . " ou " . TextFormat::GOLD . "/flipcombo cashout");
                } else {
                    $this->cashOut($player, true);
                }
            } else {
                $player->sendMessage(TextFormat::RED . "✖ Perdu! Vous avez perdu votre mise de $" . $game["bet"]);
                $this->getServer()->getPluginManager()->getPlugin("EconomyAPI")->reduceMoney($player, $game["bet"]);
                unset($this->games[$player->getName()]);
            }
        }), 20);
    }

    private function continueGame(Player $player): void {
        $game = $this->games[$player->getName()];
        $player->sendMessage(TextFormat::GOLD . "Nouveau lancer - Étape " . ($game["stage"] + 1));
        $player->sendMessage(TextFormat::YELLOW . "Choisissez: " . TextFormat::GREEN . "/flipcombo pile" . TextFormat::YELLOW . " ou " . TextFormat::RED . "/flipcombo face");
        $this->games[$player->getName()]["choice"] = null;
    }

    private function cashOut(Player $player, bool $forced = false): void {
        $game = $this->games[$player->getName()];
        $winnings = $game["bet"] * $game["multiplier"];

        $this->getServer()->getPluginManager()->getPlugin("EconomyAPI")->addMoney($player, $winnings - $game["bet"]);

        if(!$forced) {
            $player->sendMessage(TextFormat::GOLD . "Vous avez encaissé $" . $winnings . " (x" . $game["multiplier"] . " votre mise)");
        } else {
            $player->sendMessage(TextFormat::GOLD . "Bravo! Vous avez complété le combo et gagné $" . $winnings . " (x" . $game["multiplier"] . " votre mise)");
            $this->getServer()->broadcastMessage(TextFormat::GOLD . "" . $player->getName() . " a enchaîné " . $game["stage"] . " flips d'affilée!");
            $this->getServer()->broadcastMessage(TextFormat::GOLD . "Il remporte $" . $winnings . " à partir de $" . $game["bet"] . "!");
        }

        unset($this->games[$player->getName()]);
    }
}