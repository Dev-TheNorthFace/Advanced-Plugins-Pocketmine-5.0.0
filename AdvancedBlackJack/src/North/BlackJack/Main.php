<?php

namespace North\BlackJack\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {
    private $games = [];
    private $stats = [];
    private $streaks = [];

    public function onEnable(): void {
        $this->saveResource("stats.yml");
        $this->stats = yaml_parse_file($this->getDataFolder() . "stats.yml");
    }

    public function onDisable(): void {
        yaml_emit_file($this->getDataFolder() . "stats.yml", $this->stats);
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
        if(!$sender instanceof Player) return false;

        if($cmd->getName() === "blackjack") {
            if(empty($args)) {
                $sender->sendMessage(TextFormat::RED."Usage: /blackjack start|hit|stand|double|stats|top");
                return true;
            }

            $subcmd = strtolower(array_shift($args));
            $playerName = $sender->getName();

            switch($subcmd) {
                case "start":
                    if(isset($this->games[$playerName])) {
                        $sender->sendMessage(TextFormat::RED."Vous avez déjà une partie en cours!");
                        return true;
                    }
                    if(empty($args)) {
                        $sender->sendMessage(TextFormat::RED."Usage: /blackjack start <mise>");
                        return true;
                    }
                    $bet = (int)$args[0];
                    if($bet <= 0) {
                        $sender->sendMessage(TextFormat::RED."Mise invalide!");
                        return true;
                    }
                    $this->startGame($sender, $bet);
                    break;

                case "hit":
                    if(!isset($this->games[$playerName])) {
                        $sender->sendMessage(TextFormat::RED."Aucune partie en cours!");
                        return true;
                    }
                    $this->hit($sender);
                    break;

                case "stand":
                    if(!isset($this->games[$playerName])) {
                        $sender->sendMessage(TextFormat::RED."Aucune partie en cours!");
                        return true;
                    }
                    $this->stand($sender);
                    break;

                case "double":
                    if(!isset($this->games[$playerName])) {
                        $sender->sendMessage(TextFormat::RED."Aucune partie en cours!");
                        return true;
                    }
                    $this->double($sender);
                    break;

                case "stats":
                    $this->showStats($sender);
                    break;

                case "top":
                    $this->showTopPlayers($sender);
                    break;

                default:
                    $sender->sendMessage(TextFormat::RED."Sous-commande inconnue!");
                    break;
            }
            return true;
        }
        return false;
    }

    private function startGame(Player $player, int $bet) {
        $playerName = $player->getName();

        $this->games[$playerName] = [
            "bet" => $bet,
            "player_cards" => [$this->drawCard(), $this->drawCard()],
            "dealer_cards" => [$this->drawCard(), $this->drawCard()],
            "state" => "player_turn"
        ];

        $this->updateStats($playerName, "games_played", 1);

        $player->sendMessage(TextFormat::GREEN."♠️ Blackjack - Mise: ".$bet."$");
        $player->sendMessage($this->formatCards($playerName));
        $player->sendMessage(TextFormat::GOLD."Choisissez: /blackjack hit, stand ou double");
    }

    private function hit(Player $player) {
        $playerName = $player->getName();
        $game = $this->games[$playerName];

        if($game["state"] !== "player_turn") {
            $player->sendMessage(TextFormat::RED."Ce n'est pas votre tour!");
            return;
        }

        $game["player_cards"][] = $this->drawCard();
        $this->games[$playerName] = $game;

        $playerTotal = $this->calculateTotal($game["player_cards"]);
        if($playerTotal > 21) {
            $player->sendMessage($this->formatCards($playerName));
            $this->endGame($player, "bust");
        } else {
            $player->sendMessage($this->formatCards($playerName));
            $player->sendMessage(TextFormat::GOLD."Choisissez: /blackjack hit, stand ou double");
        }
    }

    private function stand(Player $player) {
        $playerName = $player->getName();
        $game = $this->games[$playerName];

        if($game["state"] !== "player_turn") {
            $player->sendMessage(TextFormat::RED."Ce n'est pas votre tour!");
            return;
        }

        $this->games[$playerName]["state"] = "dealer_turn";
        $this->dealerPlay($player);
    }

    private function double(Player $player) {
        $playerName = $player->getName();
        $game = $this->games[$playerName];

        if($game["state"] !== "player_turn") {
            $player->sendMessage(TextFormat::RED."Ce n'est pas votre tour!");
            return;
        }

        if(count($game["player_cards"]) > 2) {
            $player->sendMessage(TextFormat::RED."Vous ne pouvez doubler qu'avec vos 2 premières cartes!");
            return;
        }

        $this->games[$playerName]["bet"] *= 2;
        $game = $this->games[$playerName];

        $game["player_cards"][] = $this->drawCard();
        $this->games[$playerName] = $game;

        $playerTotal = $this->calculateTotal($game["player_cards"]);
        $player->sendMessage($this->formatCards($playerName));

        if($playerTotal > 21) {
            $this->endGame($player, "bust");
        } else {
            $this->games[$playerName]["state"] = "dealer_turn";
            $this->dealerPlay($player);
        }
    }

    private function dealerPlay(Player $player) {
        $playerName = $player->getName();
        $game = $this->games[$playerName];

        $this->getScheduler()->scheduleDelayedTask(new class($this, $player) extends Task {
            private $plugin;
            private $player;

            public function __construct(Main $plugin, Player $player) {
                $this->plugin = $plugin;
                $this->player = $player;
            }

            public function onRun(): void {
                $playerName = $this->player->getName();
                $game = $this->plugin->games[$playerName];

                $dealerTotal = $this->plugin->calculateTotal($game["dealer_cards"]);
                $playerTotal = $this->plugin->calculateTotal($game["player_cards"]);

                while($dealerTotal < 17 && $dealerTotal <= $playerTotal) {
                    $game["dealer_cards"][] = $this->plugin->drawCard();
                    $dealerTotal = $this->plugin->calculateTotal($game["dealer_cards"]);
                    $this->plugin->games[$playerName] = $game;
                }

                $this->player->sendMessage($this->plugin->formatCards($playerName));

                if($dealerTotal > 21) {
                    $this->plugin->endGame($this->player, "dealer_bust");
                } elseif($dealerTotal > $playerTotal) {
                    $this->plugin->endGame($this->player, "lose");
                } elseif($dealerTotal < $playerTotal) {
                    $this->plugin->endGame($this->player, "win");
                } else {
                    $this->plugin->endGame($this->player, "push");
                }
            }
        }, 20);
    }

    private function endGame(Player $player, string $result) {
        $playerName = $player->getName();
        $game = $this->games[$playerName];
        $bet = $game["bet"];
        $playerTotal = $this->calculateTotal($game["player_cards"]);
        $dealerTotal = $this->calculateTotal($game["dealer_cards"]);

        $message = TextFormat::GRAY."--------------------------------\n";
        $message .= TextFormat::BOLD.TextFormat::DARK_PURPLE."♠️ Résultat du Blackjack\n";

        switch($result) {
            case "bust":
                $message .= TextFormat::RED."Vous avez dépassé 21!\n";
                $message .= TextFormat::RED."💀 Vous perdez ".$bet."$\n";
                $this->updateStats($playerName, "losses", 1);
                $this->updateStreak($playerName, false);
                break;

            case "dealer_bust":
                $message .= TextFormat::GREEN."Le croupier a dépassé 21!\n";
                $winAmount = $bet * ($this->isBlackjack($game["player_cards"]) ? 2.5 : 2);
                $message .= TextFormat::GREEN."🎉 Vous gagnez ".$winAmount."$!\n";
                $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::GOLD_INGOT, 0, $winAmount));
                $this->updateStats($playerName, "wins", 1);
                $this->updateStreak($playerName, true);
                if($this->isBlackjack($game["player_cards"])) {
                    $this->updateStats($playerName, "blackjacks", 1);
                }
                break;

            case "win":
                $message .= TextFormat::GREEN."Vous avez battu le croupier!\n";
                $winAmount = $bet * ($this->isBlackjack($game["player_cards"]) ? 2.5 : 2);
                $message .= TextFormat::GREEN."🎉 Vous gagnez ".$winAmount."$!\n";
                $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::GOLD_INGOT, 0, $winAmount));
                $this->updateStats($playerName, "wins", 1);
                $this->updateStreak($playerName, true);
                if($this->isBlackjack($game["player_cards"])) {
                    $this->updateStats($playerName, "blackjacks", 1);
                }
                break;

            case "lose":
                $message .= TextFormat::RED."Le croupier vous a battu!\n";
                $message .= TextFormat::RED."💀 Vous perdez ".$bet."$\n";
                $this->updateStats($playerName, "losses", 1);
                $this->updateStreak($playerName, false);
                break;

            case "push":
                $message .= TextFormat::YELLOW."Égalité!\n";
                $message .= TextFormat::YELLOW."🤝 Vous récupérez votre mise de ".$bet."$\n";
                $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::GOLD_INGOT, 0, $bet));
                $this->updateStats($playerName, "pushes", 1);
                break;
        }

        $message .= TextFormat::GRAY."--------------------------------";
        $player->sendMessage($message);

        unset($this->games[$playerName]);
    }

    private function drawCard(): array {
        $suits = ["♥", "♦", "♣", "♠"];
        $values = ["A", 2, 3, 4, 5, 6, 7, 8, 9, 10, "J", "Q", "K"];

        return [
            "suit" => $suits[array_rand($suits)],
            "value" => $values[array_rand($values)]
        ];
    }

    private function calculateTotal(array $cards): int {
        $total = 0;
        $aces = 0;

        foreach($cards as $card) {
            if($card["value"] === "A") {
                $aces++;
                $total += 11;
            } elseif(in_array($card["value"], ["J", "Q", "K"])) {
                $total += 10;
            } else {
                $total += (int)$card["value"];
            }
        }

        while($total > 21 && $aces > 0) {
            $total -= 10;
            $aces--;
        }

        return $total;
    }

    private function isBlackjack(array $cards): bool {
        return count($cards) === 2 && $this->calculateTotal($cards) === 21;
    }

    private function formatCards(string $playerName): string {
        $game = $this->games[$playerName];
        $playerCards = $game["player_cards"];
        $dealerCards = $game["dealer_cards"];

        $message = TextFormat::GRAY."--------------------------------\n";
        $message .= TextFormat::BOLD.TextFormat::BLUE."Vos cartes:\n";

        foreach($playerCards as $card) {
            $color = ($card["suit"] === "♥" || $card["suit"] === "♦") ? TextFormat::RED : TextFormat::BLACK;
            $message .= $color."[".$card["suit"]." ".$card["value"]."] ";
        }

        $playerTotal = $this->calculateTotal($playerCards);
        $message .= TextFormat::RESET." → Total: ".$playerTotal."\n\n";

        $message .= TextFormat::BOLD.TextFormat::DARK_RED."Cartes du croupier:\n";

        if($game["state"] === "player_turn") {
            $color = ($dealerCards[0]["suit"] === "♥" || $dealerCards[0]["suit"] === "♦") ? TextFormat::RED : TextFormat::BLACK;
            $message .= $color."[".$dealerCards[0]["suit"]." ".$dealerCards[0]["value"]."] ";
            $message .= TextFormat::DARK_GRAY."[? ?]\n";
        } else {
            foreach($dealerCards as $card) {
                $color = ($card["suit"] === "♥" || $card["suit"] === "♦") ? TextFormat::RED : TextFormat::BLACK;
                $message .= $color."[".$card["suit"]." ".$card["value"]."] ";
            }
            $dealerTotal = $this->calculateTotal($dealerCards);
            $message .= TextFormat::RESET." → Total: ".$dealerTotal."\n";
        }

        $message .= TextFormat::GRAY."--------------------------------";
        return $message;
    }

    private function updateStats(string $playerName, string $stat, int $value = 1) {
        if(!isset($this->stats[$playerName])) {
            $this->stats[$playerName] = [
                "games_played" => 0,
                "wins" => 0,
                "losses" => 0,
                "pushes" => 0,
                "blackjacks" => 0,
                "highest_win" => 0,
                "current_streak" => 0,
                "max_streak" => 0
            ];
        }

        $this->stats[$playerName][$stat] += $value;

        if($stat === "wins" && $this->games[$playerName]["bet"] * 2 > $this->stats[$playerName]["highest_win"]) {
            $this->stats[$playerName]["highest_win"] = $this->games[$playerName]["bet"] * 2;
        }
    }

    private function updateStreak(string $playerName, bool $won) {
        if(!isset($this->stats[$playerName])) return;

        if($won) {
            $this->stats[$playerName]["current_streak"]++;
            if($this->stats[$playerName]["current_streak"] > $this->stats[$playerName]["max_streak"]) {
                $this->stats[$playerName]["max_streak"] = $this->stats[$playerName]["current_streak"];
            }
        } else {
            $this->stats[$playerName]["current_streak"] = 0;
        }
    }

    private function showStats(Player $player) {
        $playerName = $player->getName();

        if(!isset($this->stats[$playerName])) {
            $player->sendMessage(TextFormat::RED."Vous n'avez pas encore joué au Blackjack!");
            return;
        }

        $stats = $this->stats[$playerName];
        $winRate = round(($stats["wins"] / $stats["games_played"]) * 100, 2);

        $message = TextFormat::GRAY."--------------------------------\n";
        $message .= TextFormat::BOLD.TextFormat::DARK_PURPLE."♠️ Vos statistiques Blackjack\n\n";
        $message .= TextFormat::GOLD."Parties jouées: ".TextFormat::WHITE.$stats["games_played"]."\n";
        $message .= TextFormat::GREEN."Victoires: ".TextFormat::WHITE.$stats["wins"]." (".$winRate."%)\n";
        $message .= TextFormat::RED."Défaites: ".TextFormat::WHITE.$stats["losses"]."\n";
        $message .= TextFormat::YELLOW."Égalités: ".TextFormat::WHITE.$stats["pushes"]."\n";
        $message .= TextFormat::LIGHT_PURPLE."Blackjacks: ".TextFormat::WHITE.$stats["blackjacks"]."\n";
        $message .= TextFormat::AQUA."Plus gros gain: ".TextFormat::WHITE.$stats["highest_win"]."$\n";
        $message .= TextFormat::GOLD."Série actuelle: ".TextFormat::WHITE.$stats["current_streak"]."\n";
        $message .= TextFormat::GOLD."Série max: ".TextFormat::WHITE.$stats["max_streak"]."\n";
        $message .= TextFormat::GRAY."--------------------------------";

        $player->sendMessage($message);
    }

    private function showTopPlayers(Player $player) {
        if(empty($this->stats)) {
            $player->sendMessage(TextFormat::RED."Aucune statistique disponible!");
            return;
        }

        $sortedStats = $this->stats;
        usort($sortedStats, function($a, $b) {
            return $b["wins"] - $a["wins"];
        });

        $message = TextFormat::GRAY."--------------------------------\n";
        $message .= TextFormat::BOLD.TextFormat::DARK_PURPLE."♠️ Top 5 des joueurs Blackjack\n\n";

        $count = min(5, count($sortedStats));
        for($i = 0; $i < $count; $i++) {
            $playerStats = $sortedStats[$i];
            $winRate = round(($playerStats["wins"] / $playerStats["games_played"]) * 100, 2);
            $playerName = array_search($playerStats, $this->stats);

            $message .= TextFormat::GOLD.($i + 1).". ".TextFormat::WHITE.$playerName."\n";
            $message .= TextFormat::GRAY."Victoires: ".TextFormat::WHITE.$playerStats["wins"]." (".$winRate."%)";
            $message .= TextFormat::GRAY." | BJ: ".TextFormat::WHITE.$playerStats["blackjacks"]."\n";
        }

        $message .= TextFormat::GRAY."--------------------------------";
        $player->sendMessage($message);
    }
}