<?php
/**
 * Periode-helper: één plek voor period → SQL/label/dagen mapping.
 * Gebruikt in dashboard, analytics, product-detail, page-detail.
 */

function osPeriod(?string $period): array {
    $period = $period ?: '30';
    $today = "CURDATE()";
    $yest = "DATE_SUB(CURDATE(), INTERVAL 1 DAY)";

    switch ($period) {
        case '1':
        case 'today':
            return [
                'period' => '1',
                'days' => 1,
                'label' => 'Vandaag',
                'sql' => "DATE(created_at) = $today",
                'prev_sql' => "DATE(created_at) = $yest",
            ];
        case 'yesterday':
            return [
                'period' => 'yesterday',
                'days' => 1,
                'label' => 'Gisteren',
                'sql' => "DATE(created_at) = $yest",
                'prev_sql' => "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 2 DAY)",
            ];
        case '7':
            return [
                'period' => '7',
                'days' => 7,
                'label' => '7 dagen',
                'sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
                'prev_sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            ];
        case '90':
            return [
                'period' => '90',
                'days' => 90,
                'label' => '90 dagen',
                'sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
                'prev_sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 180 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
            ];
        case '30':
        default:
            return [
                'period' => '30',
                'days' => 30,
                'label' => '30 dagen',
                'sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                'prev_sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            ];
    }
}

/** Lijst voor period filter UI */
function osPeriodOptions(): array {
    return [
        '1' => 'Vandaag',
        'yesterday' => 'Gisteren',
        '7' => '7d',
        '30' => '30d',
        '90' => '90d',
    ];
}

/**
 * Vul $rows aan met alle dagen in periode (gaten = 0).
 * Verwacht rows met keys 'date' (Y-m-d) en een numerieke value-key.
 */
function osPadDailySeries(array $rows, int $days, string $valueKey = 'views'): array {
    $byDate = [];
    foreach ($rows as $r) $byDate[$r['date']] = $r[$valueKey];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $out[] = ['date' => $d, $valueKey => (int) ($byDate[$d] ?? 0)];
    }
    return $out;
}
