<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Milestone achievements awarded on stars *received*.
 * Ordered ascending by threshold.
 */
final class AchievementCatalog
{
    /**
     * @var array<int,array{code:string,threshold:int,emoji:string,name:string}>
     */
    private const TIERS = [
        ['code' => 'first_star',  'threshold' => 1,   'emoji' => '🌟', 'name' => 'First Star'],
        ['code' => 'rising_star', 'threshold' => 5,   'emoji' => '✨', 'name' => 'Rising Star'],
        ['code' => 'star_player', 'threshold' => 10,  'emoji' => '⭐', 'name' => 'Star Player'],
        ['code' => 'superstar',   'threshold' => 25,  'emoji' => '🌠', 'name' => 'Superstar'],
        ['code' => 'all_star',    'threshold' => 50,  'emoji' => '💫', 'name' => 'All-Star'],
        ['code' => 'legend',      'threshold' => 100, 'emoji' => '🏆', 'name' => 'Legend'],
    ];

    /**
     * All tiers, ascending by threshold.
     *
     * @return array<int,array{code:string,threshold:int,emoji:string,name:string}>
     */
    public static function all(): array
    {
        return self::TIERS;
    }

    /**
     * Return every tier whose threshold sits in (previousTotal, newTotal].
     * Handles a single command that crosses more than one tier at once.
     *
     * @return array<int,array{code:string,threshold:int,emoji:string,name:string}>
     */
    public static function newlyReached(int $previousTotal, int $newTotal): array
    {
        $reached = [];
        foreach (self::TIERS as $tier) {
            if ($tier['threshold'] > $previousTotal && $tier['threshold'] <= $newTotal) {
                $reached[] = $tier;
            }
        }
        return $reached;
    }
}
