<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableColumns(PDO $pdo, string $table): array
{
    if (!tableExists($pdo, $table)) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[strtolower((string) $column)] = (string) $column;
    }

    return $columns;
}

function safeScalar(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Admin stats query failed: ' . $e->getMessage());
        return 0;
    }
}

function safeCountTable(PDO $pdo, string $table): int
{
    if (!tableExists($pdo, $table)) {
        return 0;
    }

    return safeScalar($pdo, "SELECT COUNT(*) FROM `$table`");
}

function safeFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Admin stats list query failed: ' . $e->getMessage());
        return [];
    }
}

function monthLabel(string $yearMonth): string
{
    $labels = [
        '01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Avr',
        '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Aou',
        '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'
    ];

    $month = substr($yearMonth, 5, 2);
    return $labels[$month] ?? $yearMonth;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

require_once __DIR__ . '/../auth/guard.php';
requireRole(['admin']);

try {
    require_once __DIR__ . '/../database.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available');
    }

    $stats = [
        'total_users' => safeCountTable($pdo, 'utilisateur'),
        'total_patients' => safeCountTable($pdo, 'patient'),
        'total_doctors' => safeCountTable($pdo, 'medecin'),
        'total_secretaries' => safeCountTable($pdo, 'secretaire'),
        'total_rdv' => safeCountTable($pdo, 'rdv'),
        'total_ordonnances' => safeCountTable($pdo, 'ordonnance'),
        'rdv_by_status' => [
            'pending' => 0,
            'confirmed' => 0,
            'cancelled' => 0,
            'completed' => 0
        ],
        'monthly_rdv' => [
            'labels' => [],
            'values' => []
        ],
        'most_active_doctors' => [],
        'recent_activity' => [
            'logs_today' => 0,
            'total_notifications' => 0,
            'recent_created_users' => 0,
            'recent_created_users_supported' => false,
            'items' => []
        ]
    ];

    if (tableExists($pdo, 'rdv')) {
        $statusExpr = "LOWER(REPLACE(COALESCE(statut, ''), ' ', '_'))";
        $stats['rdv_by_status'] = [
            'pending' => safeScalar(
                $pdo,
                "SELECT COUNT(*) FROM rdv WHERE {$statusExpr} IN ('en_attente', 'pending')"
            ),
            'confirmed' => safeScalar(
                $pdo,
                "SELECT COUNT(*) FROM rdv WHERE {$statusExpr} IN ('confirme', 'confirmé', 'confirmed', 'confirm', 'planifie', 'planifié')"
            ),
            'cancelled' => safeScalar(
                $pdo,
                "SELECT COUNT(*) FROM rdv WHERE {$statusExpr} IN ('annule', 'annulé', 'refuse', 'refusé', 'cancelled', 'canceled')"
            ),
            'completed' => safeScalar(
                $pdo,
                "SELECT COUNT(*) FROM rdv WHERE {$statusExpr} IN ('present', 'venu', 'termine', 'terminé', 'done', 'complete', 'completed')"
            )
        ];

        $months = [];
        $start = new DateTime('now');
        $start->modify('first day of this month');
        $start->setTime(0, 0, 0);
        $start->modify('-5 months');
        for ($i = 0; $i < 6; $i++) {
            $key = $start->format('Y-m');
            $months[$key] = 0;
            $start->modify('+1 month');
        }

        $monthRows = safeFetchAll(
            $pdo,
            "SELECT DATE_FORMAT(dateHeure, '%Y-%m') AS ym, COUNT(*) AS total
             FROM rdv
             WHERE dateHeure >= :startDate
             GROUP BY ym
             ORDER BY ym ASC",
            ['startDate' => array_key_first($months) . '-01 00:00:00']
        );

        foreach ($monthRows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if (array_key_exists($ym, $months)) {
                $months[$ym] = (int) ($row['total'] ?? 0);
            }
        }

        $stats['monthly_rdv'] = [
            'labels' => array_map('monthLabel', array_keys($months)),
            'values' => array_values($months)
        ];
    }

    if (tableExists($pdo, 'medecin') && tableExists($pdo, 'rdv')) {
        $stats['most_active_doctors'] = safeFetchAll(
            $pdo,
            "SELECT
                m.idMedecin,
                TRIM(CONCAT(COALESCE(m.prenom, ''), ' ', COALESCE(m.nom, ''))) AS name,
                COUNT(r.idRDV) AS rdv_count
             FROM medecin m
             LEFT JOIN rdv r ON r.idMedecin = m.idMedecin
             GROUP BY m.idMedecin, m.prenom, m.nom
             ORDER BY rdv_count DESC, name ASC
             LIMIT 5"
        );
    }

    if (tableExists($pdo, 'system_logs')) {
        $stats['recent_activity']['logs_today'] = safeScalar(
            $pdo,
            'SELECT COUNT(*) FROM system_logs WHERE DATE(dateAction) = CURDATE()'
        );
    }

    if (tableExists($pdo, 'notification')) {
        $stats['recent_activity']['total_notifications'] = safeCountTable($pdo, 'notification');
    } elseif (tableExists($pdo, 'notifications')) {
        $stats['recent_activity']['total_notifications'] = safeCountTable($pdo, 'notifications');
    }

    $userColumns = tableColumns($pdo, 'utilisateur');
    foreach (['datecreation', 'date_creation', 'createdat', 'created_at', 'created'] as $candidate) {
        if (isset($userColumns[$candidate])) {
            $column = $userColumns[$candidate];
            $stats['recent_activity']['recent_created_users'] = safeScalar(
                $pdo,
                "SELECT COUNT(*) FROM utilisateur WHERE `$column` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $stats['recent_activity']['recent_created_users_supported'] = true;
            break;
        }
    }

    $stats['recent_activity']['items'] = [
        [
            'label' => 'Journaux système aujourd’hui',
            'value' => $stats['recent_activity']['logs_today'],
            'tone' => 'gold'
        ],
        [
            'label' => 'Notifications enregistrées',
            'value' => $stats['recent_activity']['total_notifications'],
            'tone' => 'teal'
        ],
        [
            'label' => $stats['recent_activity']['recent_created_users_supported']
                ? 'Nouveaux utilisateurs sur 30 jours'
                : 'Nouveaux utilisateurs sur 30 jours non disponible',
            'value' => $stats['recent_activity']['recent_created_users'],
            'tone' => 'gold'
        ]
    ];

    respond(200, [
        'success' => true,
        'stats' => $stats
    ]);
} catch (Throwable $e) {
    error_log('Get admin stats error: ' . $e->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'Server error'
    ]);
}
