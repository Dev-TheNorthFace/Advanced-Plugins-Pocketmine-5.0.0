<?php

namespace North\Roulette\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\event\player\PlayerJoinEvent;

class Main extends PluginBase implements Listener {

    private $cooldown = [];
    private $stats = [];
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        @mkdir($this->getDataFolder());
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->stats = new Config($this->getDataFolder() . "stats.yml", Config::YAML, []);
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
        if ($cmd->getName() === "roulette") {
            if (!$sender instanceof Player) {
                $sender->sendMessage(TextFormat::RED . "Commande réservée aux joueurs !");
                return true;
            }

            if (count($args) < 2) {
                $sender->sendMessage(TextFormat::RED . "Usage: /roulette <mise> <pari>");
                return true;
            }

            $bet = (int)$args[0];
            $minBet = $this->config->get("min_bet", 100);
            $maxBet = $this->config->get("max_bet", 10000);

            if ($bet < $minBet) {
                $sender->sendMessage(TextFormat::RED . "Mise minimale: " . $minBet . "$");
                return true;
            }

            if ($bet > $maxBet) {
                $sender->sendMessage(TextFormat::RED . "Mise maximale: " . $maxBet . "$");
                return true;
            }

            $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            if ($economy === null) {
                $sender->sendMessage(TextFormat::RED . "Plugin EconomyAPI non trouvé !");
                return true;
            }

            if ($economy->myMoney($sender) < $bet) {
                $sender->sendMessage(TextFormat::RED . "Fonds insuffisants !");
                return true;
            }

            $cooldownTime = $this->config->get("cooldown", 10);
            if (isset($this->cooldown[$sender->getName()]) && time() - $this->cooldown[$sender->getName()] < $cooldownTime) {
                $remaining = $cooldownTime - (time() - $this->cooldown[$sender->getName()]);
                $sender->sendMessage(TextFormat::RED . "Attendez " . $remaining . " secondes avant de rejouer.");
                return true;
            }

            $this->cooldown[$sender->getName()] = time();
            $bets = explode(",", $args[1]);
            $totalMultiplier = 1;

            foreach ($bets as $betType) {
                $betType = strtolower(trim($betType));
                $result = $this->processBet($betType, $sender, $bet, $totalMultiplier);
                if (!$result) {
                    return true;
                }
            }

            $economy->reduceMoney($sender, $bet);
            $winningNumber = mt_rand(0, 36);
            $sender->sendMessage(TextFormat::GOLD . "La bille s'arrête sur le " . $winningNumber . " !");

            $winAmount = 0;
            $hasWon = false;

            foreach ($bets as $betType) {
                $betType = strtolower(trim($betType));
                $winData = $this->checkWin($betType, $winningNumber);
                if ($winData['won']) {
                    $hasWon = true;
                    $winAmount += $bet * $winData['multiplier'] * $totalMultiplier;
                }
            }

            if ($hasWon) {
                $economy->addMoney($sender, $winAmount);
                $sender->sendMessage(TextFormat::GREEN . "Vous avez gagné " . $winAmount . "$ !");

                $playerStats = $this->stats->get($sender->getName(), ["wins" => 0, "losses" => 0, "total_won" => 0]);
                $playerStats["wins"]++;
                $playerStats["total_won"] += $winAmount;
                $this->stats->set($sender->getName(), $playerStats);
                $this->stats->save();

                $bigWinThreshold = $this->config->get("big_win_threshold", 10000);
                if ($winAmount >= $bigWinThreshold && $this->config->get("announce_big_win", true)) {
                    $this->getServer()->broadcastMessage(TextFormat::GOLD . "🎉 " . $sender->getName() . " a gagné " . $winAmount . "$ au /roulette ! GG !");
                }
            } else {
                $sender->sendMessage(TextFormat::RED . "Vous avez perdu votre mise de " . $bet . "$.");

                $playerStats = $this->stats->get($sender->getName(), ["wins" => 0, "losses" => 0, "total_won" => 0]);
                $playerStats["losses"]++;
                $this->stats->set($sender->getName(), $playerStats);
                $this->stats->save();
            }
            return true;
        }

        if ($cmd->getName() === "roulettestats") {
            $playerStats = $this->stats->get($sender->getName(), ["wins" => 0, "losses" => 0, "total_won" => 0]);
            $sender->sendMessage(TextFormat::GOLD . "=== Stats Roulette ===");
            $sender->sendMessage(TextFormat::GREEN . "Victoires: " . $playerStats["wins"]);
            $sender->sendMessage(TextFormat::RED . "Défaites: " . $playerStats["losses"]);
            $sender->sendMessage(TextFormat::GOLD . "Total gagné: " . $playerStats["total_won"] . "$");
            return true;
        }

        if ($cmd->getName() === "roulettetop") {
            $allStats = $this->stats->getAll();
            uasort($allStats, function($a, $b) {
                return $b["total_won"] - $a["total_won"];
            });

            $top = array_slice($allStats, 0, 5, true);
            $sender->sendMessage(TextFormat::GOLD . "=== Top Roulette ===");
            $position = 1;
            foreach ($top as $player => $stats) {
                $sender->sendMessage(TextFormat::YELLOW . $position . ". " . $player . ": " . $stats["total_won"] . "$");
                $position++;
            }
            return true;
        }
        return false;
    }

    private function processBet(string $betType, Player $player, int $bet, float &$totalMultiplier): bool {
        $validBets = [
            'rouge', 'noir', 'pair', 'impair', '1-18', '19-36',
            '1-12', '13-24', '25-36', '1ère', '2e', '3e'
        ];

        $isNumber = is_numeric($betType) && $betType >= 0 && $betType <= 36;

        if (!in_array($betType, $validBets) && !$isNumber) {
            $player->sendMessage(TextFormat::RED . "Pari invalide: " . $betType);
            return false;
        }

        if ($isNumber) {
            $totalMultiplier *= $this->config->get("payout.number", 36);
        } elseif ($betType === 'rouge' || $betType === 'noir') {
            $totalMultiplier *= $this->config->get("payout.color", 2);
        } elseif ($betType === 'pair' || $betType === 'impair') {
            $totalMultiplier *= $this->config->get("payout.even_odd", 2);
        } elseif ($betType === '1-18' || $betType === '19-36') {
            $totalMultiplier *= $this->config->get("payout.high_low", 2);
        } elseif (strpos($betType, '-') !== false || in_array($betType, ['1ère', '2e', '3e'])) {
            $totalMultiplier *= $this->config->get("payout.dozen", 3);
        }

        return true;
    }

    private function checkWin(string $betType, int $winningNumber): array {
        $betType = strtolower(trim($betType));
        $redNumbers = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];
        $blackNumbers = [2, 4, 6, 8, 10, 11, 13, 15, 17, 20, 22, 24, 26, 28, 29, 31, 33, 35];

        if (is_numeric($betType)) {
            return [
                'won' => (int)$betType === $winningNumber,
                'multiplier' => $this->config->get("payout.number", 36)
            ];
        }

        switch ($betType) {
            case 'rouge':
                return [
                    'won' => in_array($winningNumber, $redNumbers),
                    'multiplier' => $this->config->get("payout.color", 2)
                ];
            case 'noir':
                return [
                    'won' => in_array($winningNumber, $blackNumbers),
                    'multiplier' => $this->config->get("payout.color", 2)
                ];
            case 'pair':
                return [
                    'won' => $winningNumber !== 0 && $winningNumber % 2 === 0,
                    'multiplier' => $this->config->get("payout.even_odd", 2)
                ];
            case 'impair':
                return [
                    'won' => $winningNumber % 2 === 1,
                    'multiplier' => $this->config->get("payout.even_odd", 2)
                ];
            case '1-18':
                return [
                    'won' => $winningNumber >= 1 && $winningNumber <= 18,
                    'multiplier' => $this->config->get("payout.high_low", 2)
                ];
            case '19-36':
                return [
                    'won' => $winningNumber >= 19 && $winningNumber <= 36,
                    'multiplier' => $this->config->get("payout.high_low", 2)
                ];
            case '1-12':
                return [
                    'won' => $winningNumber >= 1 && $winningNumber <= 12,
                    'multiplier' => $this->config->get("payout.dozen", 3)
                ];
            case '13-24':
                return [
                    'won' => $winningNumber >= 13 && $winningNumber <= 24,
                    'multiplier' => $this->config->get("payout.dozen", 3)
                ];
            case '25-36':
                return [
                    'won' => $winningNumber >= 25 && $winningNumber <= 36,
                    'multiplier' => $this->config->get("payout.dozen", 3)
                ];
            case '1ère':
                return [
                    'won' => $winningNumber % 3 === 1 && $winningNumber !== 0,
                    'multiplier' => $this->config->get("payout.column", 3)
                ];
            case '2e':
                return [
                    'won' => $winningNumber % 3 === 2 && $winningNumber !== 0,
                    'multiplier' => $this->config->get("payout.column", 3)
                ];
            case '3e':
                return [
                    'won' => $winningNumber % 3 === 0 && $winningNumber !== 0,
                    'multiplier' => $this->config->get("payout.column", 3)
                ];
            default:
                return ['won' => false, 'multiplier' => 1];
        }
    }

    public function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        if (!$this->stats->exists($player->getName())) {
            $this->stats->set($player->getName(), ["wins" => 0, "losses" => 0, "total_won" => 0]);
            $this->stats->save();
        }
    }
}