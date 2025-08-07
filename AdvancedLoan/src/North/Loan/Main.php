<?php

namespace North\Loan\Main;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {

    private $loans = [];
    private $config;
    private $pendingRequests = [];
    private $creditScores = [];
    private $debtPlayers = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->loans = (array)$this->config->get("loans", []);
        $this->creditScores = (array)$this->config->get("credit_scores", []);

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $plugin;

            public function __construct(LoanSystem $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                $this->plugin->processDailyInterest();
            }
        }, 12000);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) return false;

        switch(strtolower($command->getName())) {
            case "loan":
                if(empty($args)) {
                    $sender->sendMessage("§cUsage: /loan give|list|pay|accept|default");
                    return true;
                }

                switch(strtolower($args[0])) {
                    case "give":
                        return $this->handleLoanGive($sender, $args);
                    case "list":
                        return $this->handleLoanList($sender);
                    case "pay":
                        return $this->handleLoanPay($sender, $args);
                    case "accept":
                        return $this->handleLoanAccept($sender, $args);
                    case "default":
                        return $this->handleLoanDefault($sender);
                    default:
                        $sender->sendMessage("§cUsage: /loan give|list|pay|accept|default");
                        return true;
                }
            default:
                return false;
        }
    }

    private function handleLoanGive(Player $sender, array $args): bool {
        if(count($args) < 5) {
            $sender->sendMessage("§cUsage: /loan give <player> <amount> <interest>% <duration>d");
            return true;
        }

        $target = $this->getServer()->getPlayerExact($args[1]);
        if(!$target instanceof Player) {
            $sender->sendMessage("§cPlayer not found or offline.");
            return true;
        }

        $amount = (float)$args[2];
        if($amount <= 0) {
            $sender->sendMessage("§cAmount must be positive.");
            return true;
        }

        $interest = rtrim($args[3], '%');
        if(!is_numeric($interest) {
        $sender->sendMessage("§cInterest must be a number.");
        return true;
    }

        $duration = rtrim($args[4], 'd');
        if(!is_numeric($duration)) {
            $sender->sendMessage("§cDuration must be a number of days.");
            return true;
        }

        $this->pendingRequests[$target->getName()] = [
            'lender' => $sender->getName(),
            'amount' => $amount,
            'interest' => (float)$interest,
            'duration' => (int)$duration,
            'timestamp' => time()
        ];

        $target->sendMessage("§aYou have received a loan request from {$sender->getName()} for $amount with {$interest}% interest for {$duration} days.");
        $target->sendMessage("§aType /loan accept {$sender->getName()} to accept.");
        $sender->sendMessage("§aLoan request sent to {$target->getName()}.");

        return true;
    }

    private function handleLoanAccept(Player $sender, array $args): bool {
        if(count($args) < 2) {
            $sender->sendMessage("§cUsage: /loan accept <player>");
            return true;
        }

        $lenderName = $args[1];
        if(!isset($this->pendingRequests[$sender->getName()]) || $this->pendingRequests[$sender->getName()]['lender'] !== $lenderName) {
            $sender->sendMessage("§cNo pending loan request from that player.");
            return true;
        }

        $request = $this->pendingRequests[$sender->getName()];
        $lender = $this->getServer()->getPlayerExact($lenderName);

        if(!$lender instanceof Player) {
            $sender->sendMessage("§cLender is no longer online.");
            return true;
        }

        $loanId = uniqid();
        $this->loans[$loanId] = [
            'lender' => $lenderName,
            'borrower' => $sender->getName(),
            'amount' => $request['amount'],
            'remaining' => $request['amount'],
            'interest' => $request['interest'],
            'duration' => $request['duration'],
            'start_time' => time(),
            'last_interest' => time(),
            'paid' => 0
        ];

        unset($this->pendingRequests[$sender->getName()]);

        $sender->sendMessage("§aYou have accepted the loan from {$lenderName}.");
        $lender->sendMessage("§a{$sender->getName()} has accepted your loan.");

        $this->saveLoans();

        return true;
    }

    private function handleLoanList(Player $sender): bool {
        $senderName = $sender->getName();
        $hasLoans = false;

        foreach($this->loans as $loan) {
            if($loan['lender'] === $senderName || $loan['borrower'] === $senderName) {
                $hasLoans = true;
                $status = $loan['borrower'] === $senderName ? "§cYou owe" : "§aYou lent";
                $other = $loan['borrower'] === $senderName ? $loan['lender'] : $loan['borrower'];
                $sender->sendMessage("$status {$loan['remaining']} to $other (original: {$loan['amount']}, interest: {$loan['interest']}%)");
            }
        }

        if(!$hasLoans) {
            $sender->sendMessage("§aYou have no active loans.");
        }

        return true;
    }

    private function handleLoanPay(Player $sender, array $args): bool {
        if(count($args) < 2) {
            $sender->sendMessage("§cUsage: /loan pay <player>");
            return true;
        }

        $lenderName = $args[1];
        $senderName = $sender->getName();
        $foundLoan = null;
        $loanId = null;

        foreach($this->loans as $id => $loan) {
            if($loan['borrower'] === $senderName && $loan['lender'] === $lenderName) {
                $foundLoan = $loan;
                $loanId = $id;
                break;
            }
        }

        if(!$foundLoan) {
            $sender->sendMessage("§cNo active loan with that player.");
            return true;
        }

        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if(!$economy) {
            $sender->sendMessage("§cEconomy system not found.");
            return true;
        }

        $balance = $economy->myMoney($sender);
        if($balance < $foundLoan['remaining']) {
            $sender->sendMessage("§cYou don't have enough money to pay back the full amount.");
            return true;
        }

        $economy->reduceMoney($sender, $foundLoan['remaining']);
        $economy->addMoney($lenderName, $foundLoan['remaining']);

        $this->updateCreditScore($senderName, true);
        unset($this->loans[$loanId]);
        $this->saveLoans();

        $sender->sendMessage("§aYou have successfully paid back your loan to $lenderName.");
        $lender = $this->getServer()->getPlayerExact($lenderName);
        if($lender instanceof Player) {
            $lender->sendMessage("§a{$senderName} has paid back their loan.");
        }

        return true;
    }

    private function handleLoanDefault(Player $sender): bool {
        $defaulters = [];

        foreach($this->loans as $loan) {
            $dueDate = $loan['start_time'] + ($loan['duration'] * 86400);
            if(time() > $dueDate && $loan['remaining'] > 0) {
                $defaulters[] = $loan['borrower'];
            }
        }

        if(empty($defaulters)) {
            $sender->sendMessage("§aNo players have defaulted on loans.");
        } else {
            $sender->sendMessage("§cPlayers who defaulted on loans:");
            foreach(array_unique($defaulters) as $defaulter) {
                $sender->sendMessage("- $defaulter");
            }
        }

        return true;
    }

    private function processDailyInterest(): void {
        foreach($this->loans as $id => &$loan) {
            if(time() - $loan['last_interest'] >= 86400) {
                $interestAmount = $loan['remaining'] * ($loan['interest'] / 100);
                $loan['remaining'] += $interestAmount;
                $loan['last_interest'] = time();

                $borrower = $this->getServer()->getPlayerExact($loan['borrower']);
                if($borrower instanceof Player) {
                    $borrower->sendMessage("§cYour loan interest has been applied. New amount owed: {$loan['remaining']}");
                }
            }

            $dueDate = $loan['start_time'] + ($loan['duration'] * 86400);
            if(time() > $dueDate && $loan['remaining'] > 0) {
                $this->handleDefault($loan['borrower']);
                $this->updateCreditScore($loan['borrower'], false);
            }
        }

        $this->saveLoans();
    }

    private function handleDefault(string $playerName): void {
        $this->debtPlayers[$playerName] = true;

        $player = $this->getServer()->getPlayerExact($playerName);
        if($player instanceof Player) {
            $player->sendMessage("§cYou have defaulted on your loan! Penalties applied.");
        }
    }

    private function updateCreditScore(string $playerName, bool $positive): void {
        if(!isset($this->creditScores[$playerName])) {
            $this->creditScores[$playerName] = 100;
        }

        if($positive) {
            $this->creditScores[$playerName] = min(200, $this->creditScores[$playerName] + 10);
        } else {
            $this->creditScores[$playerName] = max(0, $this->creditScores[$playerName] - 20);
        }

        $this->saveLoans();
    }

    private function saveLoans(): void {
        $this->config->set("loans", $this->loans);
        $this->config->set("credit_scores", $this->creditScores);
        $this->config->save();
    }

    public function onDisable(): void {
        $this->saveLoans();
    }
}