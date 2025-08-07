<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Events\ClickEvent;

use pocketmine\event\player\PlayerEvent;
use pocketmine\player\Player;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

class ClickEvent extends PlayerEvent implements Cancellable {

    use CancellableTrait;

    private float $clickTime;
    private string $clickType;
    private float $clickDelay;
    private array $clickData;

    public function __construct(Player $player, float $clickTime, string $clickType, float $clickDelay, array $clickData) {
        $this->player = $player;
        $this->clickTime = $clickTime;
        $this->clickType = $clickType;
        $this->clickDelay = $clickDelay;
        $this->clickData = $clickData;
    }

    public function getClickTime(): float {
        return $this->clickTime;
    }

    public function getClickType(): string {
        return $this->clickType;
    }

    public function getClickDelay(): float {
        return $this->clickDelay;
    }

    public function getClickData(): array {
        return $this->clickData;
    }

    public function getCurrentCPS(): float {
        $timestamps = $this->clickData['timestamps'] ?? [];
        if (count($timestamps) < 2) return 0.0;
        $interval = 1.0;
        $count = 0;
        $currentTime = microtime(true);

        foreach ($timestamps as $timestamp) {
            if ($currentTime - $timestamp <= $interval) {
                $count++;
            }
        }

        return $count / $interval;
    }

    public function getClickPattern(): array {
        $timestamps = $this->clickData['timestamps'] ?? [];
        $pattern = [
            'total_clicks' => count($timestamps),
            'average_delay' => 0.0,
            'variance' => 0.0
        ];

        if (count($timestamps) < 2) return $pattern;

        $delays = [];
        $previous = null;

        foreach ($timestamps as $timestamp) {
            if ($previous !== null) {
                $delays[] = $timestamp - $previous;
            }
            $previous = $timestamp;
        }

        if (!empty($delays)) {
            $pattern['average_delay'] = array_sum($delays) / count($delays);
            $pattern['variance'] = $this->calculateVariance($delays);
        }

        return $pattern;
    }

    private function calculateVariance(array $values): float {
        $mean = array_sum($values) / count($values);
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += pow($value - $mean, 2);
        }

        return $sum / count($values);
    }
}