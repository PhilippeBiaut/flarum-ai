<?php

/**
 * Prints a human-readable preview of a plan. Handy for eyeballing realism
 * without touching a forum or the OpenAI API:
 *
 *     php tests/preview.php
 */

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Pbiaut\\AiSeeder\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use Pbiaut\AiSeeder\Planner\PlanConfig;
use Pbiaut\AiSeeder\Planner\SchedulePlanner;

$plan = (new SchedulePlanner())->plan(PlanConfig::fromArray([
    'users' => 25,
    'discussions' => 60,
    'replies' => 420,
    'date_start' => '2026-01-01',
    'date_end' => '2026-05-31',
    'distribution' => 'organic',
    'seed' => 2026,
], 'Europe/Paris'));

$summary = $plan->toSummaryArray();

echo 'TOTAUX: '.json_encode($summary['totals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL.PHP_EOL;

$byMonth = [];

foreach ($summary['days'] as $day) {
    $month = substr($day['date'], 0, 7);
    $byMonth[$month]['s'] = ($byMonth[$month]['s'] ?? 0) + $day['signups'];
    $byMonth[$month]['d'] = ($byMonth[$month]['d'] ?? 0) + $day['discussions'];
    $byMonth[$month]['r'] = ($byMonth[$month]['r'] ?? 0) + $day['replies'];
}

echo 'PAR MOIS (inscriptions / discussions / reponses):'.PHP_EOL;

foreach ($byMonth as $month => $counts) {
    printf('  %s : %3d / %3d / %3d%s', $month, $counts['s'], $counts['d'], $counts['r'], PHP_EOL);
}

echo PHP_EOL.'14 PREMIERS JOURS:'.PHP_EOL;

foreach (array_slice($summary['days'], 0, 14) as $day) {
    printf(
        '  %s (%s) inscriptions=%d discussions=%d reponses=%d%s',
        $day['date'],
        (new DateTimeImmutable($day['date']))->format('D'),
        $day['signups'],
        $day['discussions'],
        $day['replies'],
        PHP_EOL
    );
}

$first = reset($plan->discussions);

echo PHP_EOL.'EXEMPLE DE FIL: ouvert le '.$first['created_at']->format('Y-m-d H:i')
    .' par le membre #'.$first['author'].PHP_EOL;

foreach (array_slice($first['replies'], 0, 6) as $index => $reply) {
    printf('   reponse %d : %s par le membre #%d%s', $index + 1, $reply['created_at']->format('Y-m-d H:i'), $reply['author'], PHP_EOL);
}
