<?php
// api.php - Main API Endpoint

require_once 'config.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_pops':
        getPops();
        break;
    case 'get_pop_status':
        getPopStatus();
        break;
    case 'update_from_exporter':
        updateFromExporter();
        break;
    case 'get_history':
        getHistory();
        break;
    case 'set_discord_webhook':
        setDiscordWebhook();
        break;
    case 'get_discord_webhook':
        getDiscordWebhook();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}

function getPops() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM pops WHERE is_active = 1 ORDER BY name");
    $pops = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $pops]);
}

function getPopStatus() {
    $popId = $_GET['pop_id'] ?? null;
    
    if (!$popId) {
        echo json_encode(['error' => 'Missing pop_id']);
        return;
    }
    
    $db = getDB();
    
    // Get PoP info
    $stmt = $db->prepare("SELECT * FROM pops WHERE id = ?");
    $stmt->execute([$popId]);
    $pop = $stmt->fetch();
    
    if (!$pop) {
        echo json_encode(['error' => 'PoP not found']);
        return;
    }
    
    // Get session statistics
    $stmt = $db->prepare("
        SELECT 
            session_type,
            COUNT(*) as total,
            SUM(CASE WHEN last_status = 'online' THEN 1 ELSE 0 END) as online,
            SUM(CASE WHEN last_status = 'offline' THEN 1 ELSE 0 END) as offline,
            SUM(prefixes_imported) as total_prefixes_in,
            SUM(prefixes_exported) as total_prefixes_out
        FROM bgp_sessions
        WHERE pop_id = ?
        GROUP BY session_type
    ");
    $stmt->execute([$popId]);
    $stats = $stmt->fetchAll();
    
    // Get individual sessions
    $stmt = $db->prepare("
        SELECT 
            id,
            session_type,
            peer_name,
            peer_ip,
            peer_asn,
            last_status,
            prefixes_imported,
            prefixes_exported,
            last_check
        FROM bgp_sessions
        WHERE pop_id = ?
        ORDER BY session_type, peer_name
    ");
    $stmt->execute([$popId]);
    $sessions = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'pop' => $pop,
        'stats' => $stats,
        'sessions' => $sessions
    ]);
}

function updateFromExporter() {
    $popId = $_GET['pop_id'] ?? null;
    
    if (!$popId) {
        echo json_encode(['error' => 'Missing pop_id']);
        return;
    }
    
    $db = getDB();
    
    // Get PoP info
    $stmt = $db->prepare("SELECT * FROM pops WHERE id = ?");
    $stmt->execute([$popId]);
    $pop = $stmt->fetch();
    
    if (!$pop) {
        echo json_encode(['error' => 'PoP not found']);
        return;
    }
    
    // Clean old history data (keep only last 7 days)
    cleanOldHistory($db);
    
    // Fetch metrics from BIRD exporter
    $metrics = fetchBirdMetrics($pop['bird_exporter_url']);
    
    if (!$metrics) {
        echo json_encode(['error' => 'Failed to fetch metrics from exporter']);
        return;
    }
    
    // Parse and update sessions
    $result = updateSessionsFromMetrics($db, $popId, $metrics);
    
    echo json_encode([
        'success' => true,
        'updated' => $result['updated'],
        'discovered' => $result['discovered'],
        'message' => "Sessions updated: {$result['updated']} total, {$result['discovered']} newly discovered"
    ]);
}

function cleanOldHistory($db) {
    // Delete history older than 5 hours - datenbank nicht müde gehen
    $stmt = $db->query("
        DELETE FROM session_history 
        WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 5 HOUR)
    ");
}

function fetchBirdMetrics($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    return parsePrometheusMetrics($response);
}

function parsePrometheusMetrics($text) {
    $metrics = [];
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        // Parse metric line: metric_name{labels} value
        if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)\{([^}]*)\}\s+([0-9.eE+-]+)/', $line, $matches)) {
            $metricName = $matches[1];
            $labelsStr = $matches[2];
            $value = $matches[3];
            
            // Parse labels
            $labels = [];
            if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="([^"]*)"/', $labelsStr, $labelMatches, PREG_SET_ORDER)) {
                foreach ($labelMatches as $lm) {
                    $labels[$lm[1]] = $lm[2];
                }
            }
            
            $metrics[] = [
                'name' => $metricName,
                'labels' => $labels,
                'value' => floatval($value)
            ];
        }
    }
    
    return $metrics;
}

function updateSessionsFromMetrics($db, $popId, $metrics) {
    $updated = 0;
    $discovered = 0;
    
    // Group metrics by protocol/peer AND ip_version to separate v4/v6
    $bgpSessions = [];
    
    foreach ($metrics as $metric) {
        $labels = $metric['labels'];
        
        // Only process BGP protocols
        if (!isset($labels['proto']) || $labels['proto'] !== 'BGP') {
            continue;
        }
        
        if (!isset($labels['name'])) {
            continue;
        }
        
        $protocolName = $labels['name'];
        $ipVersion = $labels['ip_version'] ?? '4';
        
        // Create unique key combining name and IP version from the LABEL (not the name suffix)
        // This handles BIRD's duplicate metrics for v4/v6
        $uniqueKey = $protocolName . '_ipver' . $ipVersion;
        
        // Initialize session if not exists
        if (!isset($bgpSessions[$uniqueKey])) {
            // Extract ASN from protocol name (e.g., AS214757_UP_AS214757_v6 -> 214757)
            $peerAsn = 0;
            if (preg_match('/AS(\d+)/', $protocolName, $matches)) {
                $peerAsn = intval($matches[1]);
            }
            
            $bgpSessions[$uniqueKey] = [
                'name' => $protocolName,
                'peer_ip' => 'N/A',
                'peer_asn' => $peerAsn,
                'status' => 'unknown',
                'prefixes_in' => 0,
                'prefixes_out' => 0,
                'ip_version' => $ipVersion,
                'state' => $labels['state'] ?? ''
            ];
        }
        
        // Update peer IP if available
        if (isset($labels['peer_ip'])) {
            $bgpSessions[$uniqueKey]['peer_ip'] = $labels['peer_ip'];
        }
        if (isset($labels['neighbor'])) {
            $bgpSessions[$uniqueKey]['peer_ip'] = $labels['neighbor'];
        }
        
        // Status from bird_protocol_up - only update if this metric is for the same IP version
        if ($metric['name'] === 'bird_protocol_up' && $labels['ip_version'] == $ipVersion) {
            $bgpSessions[$uniqueKey]['status'] = ($metric['value'] == 1) ? 'online' : 'offline';
            if (isset($labels['state'])) {
                $bgpSessions[$uniqueKey]['state'] = $labels['state'];
            }
        }
        
        // Import prefixes - only for matching IP version
        if ($metric['name'] === 'bird_protocol_prefix_import_count' && $labels['ip_version'] == $ipVersion) {
            $bgpSessions[$uniqueKey]['prefixes_in'] = intval($metric['value']);
        }
        
        // Export prefixes - only for matching IP version
        if ($metric['name'] === 'bird_protocol_prefix_export_count' && $labels['ip_version'] == $ipVersion) {
            $bgpSessions[$uniqueKey]['prefixes_out'] = intval($metric['value']);
        }
    }
    
    // Process discovered sessions
    foreach ($bgpSessions as $uniqueKey => $sessionData) {
        // Check if session already exists by name AND ASN
        $stmt = $db->prepare("
            SELECT id FROM bgp_sessions 
            WHERE pop_id = ? AND peer_name = ? AND peer_asn = ?
            LIMIT 1
        ");
        $stmt->execute([$popId, $sessionData['name'], $sessionData['peer_asn']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing session
            $updateStmt = $db->prepare("
                UPDATE bgp_sessions 
                SET last_status = ?, 
                    prefixes_imported = ?, 
                    prefixes_exported = ?,
                    last_check = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $sessionData['status'],
                $sessionData['prefixes_in'],
                $sessionData['prefixes_out'],
                $existing['id']
            ]);
            
            // Store in history only if 5+ minutes since last entry (reduce spam)
            $lastHistoryStmt = $db->prepare("
                SELECT MAX(recorded_at) as last_recorded 
                FROM session_history 
                WHERE session_id = ?
            ");
            $lastHistoryStmt->execute([$existing['id']]);
            $lastHistory = $lastHistoryStmt->fetch();
            
            $shouldRecord = true;
            if ($lastHistory && $lastHistory['last_recorded']) {
                $lastTime = strtotime($lastHistory['last_recorded']);
                $now = time();
                $shouldRecord = ($now - $lastTime) >= 300; // 5 minutes
            }
            
            if ($shouldRecord) {
                $historyStmt = $db->prepare("
                    INSERT INTO session_history (session_id, status, prefixes_imported, prefixes_exported)
                    VALUES (?, ?, ?, ?)
                ");
                $historyStmt->execute([
                    $existing['id'],
                    $sessionData['status'],
                    $sessionData['prefixes_in'],
                    $sessionData['prefixes_out']
                ]);
            }
            
            $updated++;
        } else {
            // Auto-discover and insert new session
            // Try to determine session type from protocol name
            $sessionType = detectSessionType($sessionData['name'], $sessionData['peer_asn']);
            
            $insertStmt = $db->prepare("
                INSERT INTO bgp_sessions 
                (pop_id, session_type, peer_name, peer_ip, peer_asn, last_status, prefixes_imported, prefixes_exported, last_check)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insertStmt->execute([
                $popId,
                $sessionType,
                $sessionData['name'],
                $sessionData['peer_ip'],
                $sessionData['peer_asn'],
                $sessionData['status'],
                $sessionData['prefixes_in'],
                $sessionData['prefixes_out']
            ]);
            $discovered++;
            $updated++;
        }
    }
    
    // Check for alerts after updating sessions
    checkAndSendAlerts($db, $popId);
    
    return ['updated' => $updated, 'discovered' => $discovered];
}

function detectSessionType($protocolName, $peerAsn) {
    $name = strtolower($protocolName);
    
    // Detect by naming convention - more flexible matching
    // Check for UP/UPSTREAM (with or without underscores)
    if (preg_match('/(^|_)up($|_)/i', $name) || strpos($name, 'upstream') !== false || strpos($name, 'transit') !== false) {
        return 'upstream';
    }
    
    // Check for DOWN/DOWNSTREAM (with or without underscores)
    if (preg_match('/(^|_)down($|_|wg|vxlan)/i', $name) || strpos($name, 'downstream') !== false || strpos($name, 'customer') !== false) {
        return 'downstream';
    }
    
    // Check for exchange/peering patterns
    if (strpos($name, 'bgpexchange') !== false || strpos($name, 'peer') !== false || 
        strpos($name, '_ix') !== false || strpos($name, 'exchange') !== false ||
        strpos($name, 'decix') !== false || strpos($name, 'amsix') !== false ||
        strpos($name, 'linx') !== false || strpos($name, 'nlix') !== false) {
        return 'peering';
    }
    
    // Detect by common transit ASNs
    $transitAsns = [174, 1299, 3356, 6762, 6830, 2914, 3257, 6939, 5511, 3491, 1273, 6461];
    if (in_array($peerAsn, $transitAsns)) {
        return 'upstream';
    }
    
    // Default to peering for unknown
    return 'peering';
}

function getHistory() {
    $sessionId = $_GET['session_id'] ?? null;
    $hours = intval($_GET['hours'] ?? 24);
    
    if (!$sessionId) {
        echo json_encode(['error' => 'Missing session_id']);
        return;
    }
    
    $db = getDB();
    
    // Get history data
    $stmt = $db->prepare("
        SELECT 
            status,
            prefixes_imported,
            prefixes_exported,
            recorded_at
        FROM session_history
        WHERE session_id = ?
        AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$sessionId, $hours]);
    $history = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $history
    ]);
}

function checkAndSendAlerts($db, $popId) {
    // Get PoP info
    $stmt = $db->prepare("SELECT name, location FROM pops WHERE id = ?");
    $stmt->execute([$popId]);
    $pop = $stmt->fetch();
    
    // Get Discord webhook for this PoP
    $stmt = $db->prepare("SELECT discord_webhook_url FROM alert_config WHERE pop_id = ?");
    $stmt->execute([$popId]);
    $config = $stmt->fetch();
    
    if (!$config || !$config['discord_webhook_url']) {
        return; // No webhook configured
    }
    
    $webhookUrl = $config['discord_webhook_url'];
    
    // Get all sessions for this PoP
    $stmt = $db->prepare("
        SELECT id, peer_name, peer_asn, last_status, session_type
        FROM bgp_sessions
        WHERE pop_id = ?
    ");
    $stmt->execute([$popId]);
    $sessions = $stmt->fetchAll();
    
    foreach ($sessions as $session) {
        // Check if we have state tracking for this session
        $stmt = $db->prepare("
            SELECT last_known_status FROM session_state_tracking WHERE session_id = ?
        ");
        $stmt->execute([$session['id']]);
        $tracking = $stmt->fetch();
        
        $currentStatus = $session['last_status'];
        
        // Initialize tracking if not exists
        if (!$tracking) {
            $stmt = $db->prepare("
                INSERT INTO session_state_tracking (session_id, last_known_status)
                VALUES (?, ?)
            ");
            $stmt->execute([$session['id'], $currentStatus]);
            continue;
        }
        
        // Check if status changed
        if ($tracking['last_known_status'] !== $currentStatus) {
            // Status changed - send notification
            if ($currentStatus === 'offline') {
                $color = 15158332; // Red
                $title = "🔴 BGP Session DOWN";
                $description = "Session **{$session['peer_name']}** (AS{$session['peer_asn']}) went offline";
            } else {
                $color = 3066993; // Green
                $title = "🟢 BGP Session UP";
                $description = "Session **{$session['peer_name']}** (AS{$session['peer_asn']}) is back online";
            }
            
            sendDiscordNotification(
                $webhookUrl,
                $title,
                $description,
                $color,
                [
                    ['name' => 'PoP', 'value' => "{$pop['name']} ({$pop['location']})", 'inline' => true],
                    ['name' => 'Type', 'value' => ucfirst($session['session_type']), 'inline' => true],
                    ['name' => 'ASN', 'value' => "AS{$session['peer_asn']}", 'inline' => true]
                ]
            );
            
            // Update tracking
            $stmt = $db->prepare("
                UPDATE session_state_tracking
                SET last_known_status = ?, last_notification_sent = NOW()
                WHERE session_id = ?
            ");
            $stmt->execute([$currentStatus, $session['id']]);
        }
    }
}

function sendDiscordNotification($webhookUrl, $title, $description, $color, $fields = []) {
    $payload = [
        'embeds' => [
            [
                'title' => $title,
                'description' => $description,
                'color' => $color,
                'fields' => $fields,
                'timestamp' => date('c'),
                'footer' => [
                    'text' => 'V6Direct Monitoring'
                ]
            ]
        ]
    ];
    
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    curl_exec($ch);
    curl_close($ch);
}

function setDiscordWebhook() {
    $popId = $_POST['pop_id'] ?? null;
    $webhookUrl = $_POST['webhook_url'] ?? null;
    
    if (!$popId) {
        echo json_encode(['error' => 'Missing pop_id']);
        return;
    }
    
    $db = getDB();
    
    // Update or insert webhook URL
    $stmt = $db->prepare("
        INSERT INTO alert_config (pop_id, discord_webhook_url, alert_on_session_down)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE discord_webhook_url = ?
    ");
    $stmt->execute([$popId, $webhookUrl, $webhookUrl]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Discord webhook updated'
    ]);
}

function getDiscordWebhook() {
    $popId = $_GET['pop_id'] ?? null;
    
    if (!$popId) {
        echo json_encode(['error' => 'Missing pop_id']);
        return;
    }
    
    $db = getDB();
    
    $stmt = $db->prepare("SELECT discord_webhook_url FROM alert_config WHERE pop_id = ?");
    $stmt->execute([$popId]);
    $config = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'webhook_url' => $config['discord_webhook_url'] ?? ''
    ]);
}
?>
