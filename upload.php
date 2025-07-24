<?php
// Einfache Logging-Funktion
function write_log($message) {
    $logFile = __DIR__ . '/upload_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    if (!empty($_GET)) {
        $logMessage .= " | GET: " . http_build_query($_GET);
    }
    if (!empty($_POST)) {
        $logMessage .= " | POST: " . http_build_query($_POST);
    }
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Datenbankinitialisierung für KI-Workflows
function init_database() {
    $db_path = __DIR__ . '/workflows.db';
    $db = new SQLite3($db_path);
    
    // SQLite Optimierungen für bessere Concurrency
    $db->exec("PRAGMA journal_mode = WAL");  // Write-Ahead Logging für bessere Concurrency
    $db->exec("PRAGMA synchronous = NORMAL"); // Balance zwischen Performance und Sicherheit
    $db->exec("PRAGMA busy_timeout = 5000");  // 5 Sekunden Timeout bei Locks
    
    // Workflows-Tabelle
    $db->exec("CREATE TABLE IF NOT EXISTS workflows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        active BOOLEAN DEFAULT 1,
        filter_esp_id TEXT DEFAULT '',
        filter_wake_reason TEXT DEFAULT '',
        ollama_url TEXT NOT NULL,
        ollama_model TEXT NOT NULL,
        ai_prompt TEXT NOT NULL,
        email_recipient TEXT DEFAULT '',
        notification_channels TEXT DEFAULT 'email',
        discord_webhook_url TEXT DEFAULT '',
        nextcloud_server_url TEXT DEFAULT '',
        nextcloud_talk_token TEXT DEFAULT '',
        nextcloud_username TEXT DEFAULT '',
        nextcloud_password TEXT DEFAULT '',
        attach_image_discord BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Migration: Neue Spalten zu bestehenden workflows-Tabellen hinzufügen (nur wenn noch nicht vorhanden)
    $columns_to_add = [
        'notification_channels' => "TEXT DEFAULT 'email'",
        'discord_webhook_url' => "TEXT DEFAULT ''",
        'nextcloud_server_url' => "TEXT DEFAULT ''",
        'nextcloud_talk_token' => "TEXT DEFAULT ''",
        'nextcloud_username' => "TEXT DEFAULT ''",
        'nextcloud_password' => "TEXT DEFAULT ''",
        'attach_image_discord' => "BOOLEAN DEFAULT 0"
    ];
    
    // Prüfen welche Spalten bereits existieren
    $existing_columns = [];
    $result = $db->query("PRAGMA table_info(workflows)");
    while ($row = $result->fetchArray()) {
        $existing_columns[] = $row['name'];
    }
    
    foreach ($columns_to_add as $column => $definition) {
        if (!in_array($column, $existing_columns)) {
            try {
                $db->exec("ALTER TABLE workflows ADD COLUMN $column $definition");
                write_log("Migration: Spalte '$column' zur workflows-Tabelle hinzugefügt");
            } catch (Exception $e) {
                write_log("Migration Fehler für Spalte '$column': " . $e->getMessage());
            }
        }
    }
    
    // email_recipient kann jetzt leer sein (falls nur Discord/Nextcloud verwendet wird)
    try {
        // Retry-Mechanismus für Database-Locks
        $max_retries = 3;
        $retry_count = 0;
        while ($retry_count < $max_retries) {
            try {
                $db->exec("UPDATE workflows SET email_recipient = '' WHERE email_recipient IS NULL");
                break; // Erfolgreich, Loop verlassen
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'database is locked') !== false && $retry_count < $max_retries - 1) {
                    $retry_count++;
                    usleep(100000); // 100ms warten
                    continue;
                } else {
                    throw $e; // Anderen Fehler weiterwerfen
                }
            }
        }
    } catch (Exception $e) {
        // Ignorieren falls Spalte nicht existiert oder andere nicht-kritische Fehler
        write_log("Migration Warning: " . $e->getMessage());
    }
    
    // Verarbeitete Bilder-Tabelle
    $db->exec("CREATE TABLE IF NOT EXISTS processed_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workflow_id INTEGER,
        image_path TEXT NOT NULL,
        ai_result TEXT,
        email_sent BOOLEAN DEFAULT 0,
        error_message TEXT DEFAULT '',
        processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(workflow_id) REFERENCES workflows(id)
    )");
    
    return $db;
}

// Sichere Datenbankoperation mit Retry-Mechanismus
function safe_db_exec($db, $query, $max_retries = 3) {
    $retry_count = 0;
    while ($retry_count < $max_retries) {
        try {
            return $db->exec($query);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'database is locked') !== false && $retry_count < $max_retries - 1) {
                $retry_count++;
                write_log("Database locked, retry $retry_count/$max_retries");
                usleep(200000 * $retry_count); // Exponential backoff: 200ms, 400ms, 600ms
                continue;
            } else {
                write_log("Database error: " . $e->getMessage());
                throw $e;
            }
        }
    }
    return false;
}

// File-basiertes Locking für Ollama-Anfragen
function acquire_ollama_lock($timeout_seconds = 300) {
    $lock_file = __DIR__ . '/ollama_processing.lock';
    $max_wait = time() + $timeout_seconds;
    
    while (time() < $max_wait) {
        if (!file_exists($lock_file)) {
            // Versuche Lock zu erstellen
            $lock_data = [
                'pid' => getmypid(),
                'timestamp' => time(),
                'script' => $_SERVER['SCRIPT_NAME'] ?? 'unknown'
            ];
            
            if (file_put_contents($lock_file, json_encode($lock_data), LOCK_EX) !== false) {
                write_log("Ollama-Lock erfolgreich erhalten (PID: " . getmypid() . ")");
                return true;
            }
        } else {
            // Prüfe ob Lock veraltet ist
            $lock_content = file_get_contents($lock_file);
            if ($lock_content) {
                $lock_data = json_decode($lock_content, true);
                if ($lock_data && isset($lock_data['timestamp'])) {
                    // Lock älter als 10 Minuten? -> Als stale betrachten
                    if (time() - $lock_data['timestamp'] > 600) {
                        write_log("Stale Ollama-Lock erkannt, entferne Lock (Alt-PID: " . ($lock_data['pid'] ?? 'unknown') . ")");
                        unlink($lock_file);
                        continue;
                    }
                }
            }
        }
        
        // Kurz warten bevor erneuter Versuch
        usleep(500000); // 0.5 Sekunden
    }
    
    write_log("Ollama-Lock Timeout erreicht nach {$timeout_seconds} Sekunden");
    return false;
}

function release_ollama_lock() {
    $lock_file = __DIR__ . '/ollama_processing.lock';
    
    if (file_exists($lock_file)) {
        $lock_content = file_get_contents($lock_file);
        $lock_data = json_decode($lock_content, true);
        
        // Prüfe ob wir der Besitzer des Locks sind
        if ($lock_data && isset($lock_data['pid']) && $lock_data['pid'] == getmypid()) {
            unlink($lock_file);
            write_log("Ollama-Lock erfolgreich freigegeben (PID: " . getmypid() . ")");
            return true;
        } else {
            write_log("Warnung: Versuch Lock freizugeben, aber PID stimmt nicht überein");
            return false;
        }
    }
    
    return true; // Kein Lock vorhanden, ist ok
}

// Cleanup-Funktion für verwaiste Locks
function cleanup_stale_locks() {
    $lock_file = __DIR__ . '/ollama_processing.lock';
    
    if (file_exists($lock_file)) {
        $lock_content = file_get_contents($lock_file);
        if ($lock_content) {
            $lock_data = json_decode($lock_content, true);
            if ($lock_data && isset($lock_data['timestamp'])) {
                // Lock älter als 10 Minuten?
                if (time() - $lock_data['timestamp'] > 600) {
                    unlink($lock_file);
                    write_log("Cleanup: Stale Ollama-Lock entfernt (Alt-PID: " . ($lock_data['pid'] ?? 'unknown') . ")");
                }
            }
        }
    }
}

// Ollama API Integration
function analyze_image_with_ollama($image_path, $ollama_url, $model, $prompt) {
    $image_data = base64_encode(file_get_contents($image_path));
    
    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'images' => [$image_data],
        'stream' => false
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($ollama_url, '/') . '/api/generate');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 Minuten Timeout
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        write_log("Ollama CURL Error: " . $error);
        return ['success' => false, 'error' => $error];
    }
    
    if ($http_code !== 200) {
        write_log("Ollama HTTP Error: " . $http_code . " - " . $response);
        return ['success' => false, 'error' => "HTTP $http_code: $response"];
    }
    
    $result = json_decode($response, true);
    if (!$result || !isset($result['response'])) {
        write_log("Ollama JSON Error: " . $response);
        return ['success' => false, 'error' => 'Invalid JSON response'];
    }
    
    return ['success' => true, 'result' => $result['response']];
}

// E-Mail versenden via direktes SMTP
function send_email($recipient, $subject, $message) {
    // SMTP-Server aus E-Mail-Domain extrahieren
    if (strpos($recipient, '@') === false) {
        write_log("Ungültige E-Mail-Adresse: " . $recipient);
        return false;
    }
    
    $email_parts = explode('@', $recipient);
    $domain = $email_parts[1];
    $smtp_server = $domain; // Verwendet die Domain als SMTP-Server
    $smtp_port = 25;
    $from = 'ecosnapcam@' . $domain; // From-Adresse aus gleicher Domain
    
    // SMTP-Verbindung aufbauen
    $socket = fsockopen($smtp_server, $smtp_port, $errno, $errstr, 10);
    if (!$socket) {
        write_log("SMTP-Verbindung fehlgeschlagen: $errstr ($errno)");
        return false;
    }
    
    // SMTP-Response lesen
    if (!function_exists('smtp_read')) {
        function smtp_read($socket) {
            $response = '';
            while ($line = fgets($socket)) {
                $response .= $line;
                if (substr($line, 3, 1) == ' ') break;
            }
            return trim($response);
        }
    }
    
    // SMTP-Kommando senden
    if (!function_exists('smtp_send')) {
        function smtp_send($socket, $command) {
            fputs($socket, $command . "\r\n");
            return smtp_read($socket);
        }
    }
    
    try {
        // Willkommensnachricht lesen
        $response = smtp_read($socket);
        write_log("SMTP Connect: " . $response);
        
        // EHLO senden
        $response = smtp_send($socket, "EHLO localhost");
        write_log("SMTP EHLO: " . $response);
        
        // MAIL FROM
        $response = smtp_send($socket, "MAIL FROM: <$from>");
        write_log("SMTP MAIL FROM: " . $response);
        if (substr($response, 0, 3) != '250') {
            throw new Exception("MAIL FROM failed: " . $response);
        }
        
        // RCPT TO
        $response = smtp_send($socket, "RCPT TO: <$recipient>");
        write_log("SMTP RCPT TO: " . $response);
        if (substr($response, 0, 3) != '250') {
            throw new Exception("RCPT TO failed: " . $response);
        }
        
        // DATA
        $response = smtp_send($socket, "DATA");
        write_log("SMTP DATA: " . $response);
        if (substr($response, 0, 3) != '354') {
            throw new Exception("DATA failed: " . $response);
        }
        
        // E-Mail-Header und -Body
        $email_data = "From: $from\r\n";
        $email_data .= "To: $recipient\r\n";
        $email_data .= "Subject: $subject\r\n";
        $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email_data .= "Date: " . date('r') . "\r\n";
        $email_data .= "\r\n";
        $email_data .= $message . "\r\n";
        $email_data .= ".";
        
        $response = smtp_send($socket, $email_data);
        write_log("SMTP Email Data: " . $response);
        if (substr($response, 0, 3) != '250') {
            throw new Exception("Email data failed: " . $response);
        }
        
        // QUIT
        smtp_send($socket, "QUIT");
        fclose($socket);
        
        write_log("E-Mail erfolgreich gesendet an: " . $recipient);
        return true;
        
    } catch (Exception $e) {
        write_log("SMTP-Fehler: " . $e->getMessage());
        fclose($socket);
        return false;
    }
}

// Discord Webhook Integration
function send_discord_message($webhook_url, $message, $image_path = null, $attach_image = false) {
    if (empty($webhook_url)) {
        write_log("Discord Webhook-URL ist leer");
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhook_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Längerer Timeout für Bild-Upload
    
    if ($attach_image && $image_path && file_exists($image_path)) {
        // Multipart-Upload mit Bild-Attachment
        $embed_data = [
            'title' => '🦌 EcoSnapCam - Wildlife Detection',
            'description' => $message,
            'color' => 0x2ecc71, // Grün
            'timestamp' => date('c'),
            'footer' => [
                'text' => 'EcoSnapCam Wildlife Monitor',
                'icon_url' => 'https://raw.githubusercontent.com/microsoft/fluentui-emoji/main/assets/Camera/3D/camera_3d.png'
            ]
        ];
        
        $payload = [
            'username' => 'EcoSnapCam',
            'embeds' => [$embed_data]
        ];
        
        $postfields = [
            'payload_json' => json_encode($payload),
            'file' => new CURLFile($image_path, mime_content_type($image_path), basename($image_path))
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        write_log("Discord: Sende Nachricht mit Bild-Attachment: " . basename($image_path));
        
    } else {
        // Standard JSON-Upload ohne Bild
        $data = [
            'username' => 'EcoSnapCam',
            'content' => $message,
            'embeds' => [
                [
                    'title' => '🦌 EcoSnapCam - Wildlife Detection',
                    'description' => $message,
                    'color' => 0x3498db, // Blau
                    'timestamp' => date('c'),
                    'footer' => [
                        'text' => 'EcoSnapCam Wildlife Monitor'
                    ]
                ]
            ]
        ];
        
        $headers = ['Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        write_log("Discord: Sende reine Text-Nachricht");
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        write_log("Discord cURL Fehler: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($http_code >= 200 && $http_code < 300) {
        $image_info = ($attach_image && $image_path) ? " mit Bild" : " ohne Bild";
        write_log("Discord-Nachricht erfolgreich gesendet (HTTP $http_code)" . $image_info);
        return true;
    } else {
        write_log("Discord-Fehler: HTTP $http_code - $response");
        return false;
    }
}

// Nextcloud Talk Integration
function send_nextcloud_talk($server_url, $talk_token, $username, $password, $message) {
    if (empty($server_url) || empty($talk_token) || empty($username) || empty($password)) {
        write_log("Nextcloud Talk Parameter unvollständig");
        return false;
    }
    
    // Entferne trailing slash von server_url
    $server_url = rtrim($server_url, '/');
    
    $url = $server_url . '/ocs/v2.php/apps/spreed/api/v1/chat/' . $talk_token;
    
    $data = [
        'message' => $message
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'OCS-APIRequest: true',
        'Authorization: Basic ' . base64_encode($username . ':' . $password)
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        write_log("Nextcloud Talk cURL Fehler: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($http_code >= 200 && $http_code < 300) {
        write_log("Nextcloud Talk-Nachricht erfolgreich gesendet (HTTP $http_code)");
        return true;
    } else {
        write_log("Nextcloud Talk-Fehler: HTTP $http_code - $response");
        return false;
    }
}

// Vereinheitlichte Notification-Funktion
function send_notification($workflow, $subject, $message, $image_path = null) {
    $channels = explode(',', $workflow['notification_channels']);
    $results = [];
    
    foreach ($channels as $channel) {
        $channel = trim($channel);
        
        switch ($channel) {
            case 'email':
                if (!empty($workflow['email_recipient'])) {
                    $results['email'] = send_email($workflow['email_recipient'], $subject, $message);
                    if ($results['email']) {
                        write_log("E-Mail erfolgreich gesendet an: " . $workflow['email_recipient']);
                    }
                } else {
                    write_log("E-Mail übersprungen: Keine Empfänger-Adresse konfiguriert");
                    $results['email'] = false;
                }
                break;
                
            case 'discord':
                if (!empty($workflow['discord_webhook_url'])) {
                    // Discord-spezifische Formatierung
                    $discord_message = "**$subject**\n\n$message";
                    $attach_image = !empty($workflow['attach_image_discord']) && $workflow['attach_image_discord'] == 1;
                    $results['discord'] = send_discord_message($workflow['discord_webhook_url'], $discord_message, $image_path, $attach_image);
                    if ($results['discord']) {
                        $image_info = $attach_image ? " (mit Bild)" : "";
                        write_log("Discord-Nachricht erfolgreich gesendet" . $image_info);
                    }
                } else {
                    write_log("Discord übersprungen: Keine Webhook-URL konfiguriert");
                    $results['discord'] = false;
                }
                break;
                
            case 'nextcloud':
                if (!empty($workflow['nextcloud_server_url']) && !empty($workflow['nextcloud_talk_token']) && 
                    !empty($workflow['nextcloud_username']) && !empty($workflow['nextcloud_password'])) {
                    // Nextcloud-spezifische Formatierung
                    $nextcloud_message = "$subject\n\n$message";
                    $results['nextcloud'] = send_nextcloud_talk(
                        $workflow['nextcloud_server_url'],
                        $workflow['nextcloud_talk_token'],
                        $workflow['nextcloud_username'],
                        $workflow['nextcloud_password'],
                        $nextcloud_message
                    );
                    if ($results['nextcloud']) {
                        write_log("Nextcloud Talk-Nachricht erfolgreich gesendet");
                    }
                } else {
                    write_log("Nextcloud Talk übersprungen: Unvollständige Konfiguration");
                    $results['nextcloud'] = false;
                }
                break;
                
            default:
                write_log("Unbekannter Notification-Kanal: $channel");
                break;
        }
    }
    
    // Mindestens ein Kanal erfolgreich?
    $success = array_filter($results);
    return !empty($success);
}

// Workflows verarbeiten
function process_workflows($image_path, $metadata, $db) {
    // Cleanup verwaiste Locks vor Verarbeitung
    cleanup_stale_locks();
    
    $stmt = $db->prepare("SELECT * FROM workflows WHERE active = 1");
    $result = $stmt->execute();
    
    while ($workflow = $result->fetchArray(SQLITE3_ASSOC)) {
        // Filter prüfen
        $esp_match = empty($workflow['filter_esp_id']) || $workflow['filter_esp_id'] === $metadata['esp_id'];
        $wake_match = empty($workflow['filter_wake_reason']) || $workflow['filter_wake_reason'] === $metadata['wake_reason'];
        
        if ($esp_match && $wake_match) {
            write_log("Workflow '" . $workflow['name'] . "' matched für Bild: " . basename($image_path));
            
            // Lock für Ollama-Anfrage erhalten
            if (!acquire_ollama_lock()) {
                $error_message = "Timeout beim Warten auf Ollama-Lock";
                write_log("KI-Analyse übersprungen für Workflow '" . $workflow['name'] . "': " . $error_message);
                
                // Fehler in Datenbank speichern
                $stmt_insert = $db->prepare("INSERT INTO processed_images (workflow_id, image_path, ai_result, email_sent, error_message) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->bindValue(1, $workflow['id'], SQLITE3_INTEGER);
                $stmt_insert->bindValue(2, $image_path, SQLITE3_TEXT);
                $stmt_insert->bindValue(3, '', SQLITE3_TEXT);
                $stmt_insert->bindValue(4, 0, SQLITE3_INTEGER);
                $stmt_insert->bindValue(5, $error_message, SQLITE3_TEXT);
                $stmt_insert->execute();
                continue;
            }
            
            $analysis = null;
            $notification_sent = false;
            $error_message = '';
            
            try {
                // KI-Analyse durchführen (in kritischer Sektion)
                $analysis = analyze_image_with_ollama(
                    $image_path,
                    $workflow['ollama_url'],
                    $workflow['ollama_model'],
                    $workflow['ai_prompt']
                );
                
                if ($analysis['success']) {
                    // Benachrichtigungen über alle konfigurierten Kanäle senden
                    $subject = "KI-Analyse: " . $workflow['name'] . " - " . $metadata['esp_id'];
                    $message_body = "Bildanalyse von " . basename($image_path) . "\n\n";
                    $message_body .= "ESP-ID: " . $metadata['esp_id'] . "\n";
                    $message_body .= "Wake Reason: " . $metadata['wake_reason'] . "\n";
                    $message_body .= "Zeitstempel: " . $metadata['timestamp_short'] . "\n";
                    if (!empty($metadata['battery_voltage'])) {
                        $message_body .= "Batterie: " . $metadata['battery_voltage'] . "V\n";
                    }
                    $message_body .= "\nKI-Analyse:\n" . $analysis['result'];
                    
                    try {
                        $notification_sent = send_notification($workflow, $subject, $message_body, $image_path);
                        if (!$notification_sent) {
                            $error_message = "Benachrichtigung konnte nicht gesendet werden";
                            write_log("Benachrichtigung fehlgeschlagen für Workflow '" . $workflow['name'] . "'");
                        }
                    } catch (Exception $notification_error) {
                        $error_message = "Benachrichtigung-Fehler: " . $notification_error->getMessage();
                        write_log($error_message);
                        $notification_sent = false;
                    }
                } else {
                    $error_message = $analysis['error'];
                    write_log("KI-Analyse fehlgeschlagen für Workflow '" . $workflow['name'] . "': " . $error_message);
                }
            } catch (Exception $e) {
                $error_message = "Exception bei KI-Analyse: " . $e->getMessage();
                write_log($error_message);
            } finally {
                // Lock immer freigeben, auch bei Fehlern
                release_ollama_lock();
            }
            
            // Ergebnis in Datenbank speichern
            $stmt_insert = $db->prepare("INSERT INTO processed_images (workflow_id, image_path, ai_result, email_sent, error_message) VALUES (?, ?, ?, ?, ?)");
            $stmt_insert->bindValue(1, $workflow['id'], SQLITE3_INTEGER);
            $stmt_insert->bindValue(2, $image_path, SQLITE3_TEXT);
            $stmt_insert->bindValue(3, ($analysis && $analysis['success']) ? $analysis['result'] : '', SQLITE3_TEXT);
            $stmt_insert->bindValue(4, $notification_sent ? 1 : 0, SQLITE3_INTEGER);
            $stmt_insert->bindValue(5, $error_message, SQLITE3_TEXT);
            $stmt_insert->execute();
        }
    }
}

// Hilfsfunktion: Aktueller Lock-Status
function get_ollama_lock_status() {
    $lock_file = __DIR__ . '/ollama_processing.lock';
    
    if (!file_exists($lock_file)) {
        return ['locked' => false, 'message' => 'Ollama verfügbar'];
    }
    
    $lock_content = file_get_contents($lock_file);
    if (!$lock_content) {
        return ['locked' => true, 'message' => 'Lock-Datei beschädigt'];
    }
    
    $lock_data = json_decode($lock_content, true);
    if (!$lock_data || !isset($lock_data['timestamp'])) {
        return ['locked' => true, 'message' => 'Ungültige Lock-Daten'];
    }
    
    $age_seconds = time() - $lock_data['timestamp'];
    $age_minutes = round($age_seconds / 60, 1);
    
    return [
        'locked' => true,
        'message' => "Ollama verarbeitet Bild (seit {$age_minutes} Min, PID: " . ($lock_data['pid'] ?? 'unknown') . ")",
        'age_seconds' => $age_seconds,
        'pid' => $lock_data['pid'] ?? null
    ];
}

write_log("Request erhalten. Methode: " . $_SERVER['REQUEST_METHOD']);

// Datenbank initialisieren
$db = init_database();

// Verzeichnis für die Uploads
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    write_log("Upload-Verzeichnis '{$uploadDir}' existiert nicht. Versuche es zu erstellen.");
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        $errorMsg = 'Fehler: Upload-Verzeichnis konnte nicht erstellt werden.';
        write_log($errorMsg);
        die($errorMsg);
    }
    write_log("Upload-Verzeichnis '{$uploadDir}' erfolgreich erstellt.");
} elseif (!is_writable($uploadDir)) {
    http_response_code(500);
    $errorMsg = "Fehler: Upload-Verzeichnis '{$uploadDir}' ist nicht beschreibbar.";
    write_log($errorMsg);
    die($errorMsg);
}

// Behandlung von POST-Requests 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prüfen ob es ein Workflow-Management Request ist (hat 'action' Parameter)
    if (isset($_POST['action'])) {
        write_log("Workflow-Management POST-Request wird bearbeitet.");
        // Workflow-Management wird später in der HTML-Sektion behandelt
        // Hier nur weiterleiten zur normalen GET-Verarbeitung
    } else {
        // Bild-Upload (kein 'action' Parameter, rohe Bilddaten im Body)
        write_log("Bild-Upload POST-Request wird bearbeitet.");
        $imageData = file_get_contents('php://input');

        if ($imageData === false || empty($imageData)) {
            http_response_code(400);
            $errorMsg = "Fehler: Keine Bilddaten empfangen.";
            write_log($errorMsg . " Rohdatenlänge: " . (is_string($imageData) ? strlen($imageData) : 'false/null'));
            echo $errorMsg;
            exit;
        }
        write_log("Bilddaten empfangen. Größe: " . strlen($imageData) . " Bytes.");

        // Dateiname generieren (YYYYMMDD-HHMMSS_espid_wakereason_vbatXXXXmV.jpg)
        $timestampFormatted = date('Ymd-His');
        $espId = isset($_GET['esp_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['esp_id']) : 'unknownID';
        $wakeReason = isset($_GET['wake_reason']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['wake_reason']) : 'unknownReason';
        $vbat = isset($_GET['vbat']) ? (int)$_GET['vbat'] : null;

        $filename = $timestampFormatted;
        $filename .= '_' . $espId;
        $filename .= '_' . $wakeReason;
        if ($vbat !== null) {
            $filename .= '_vbat' . $vbat . 'mV';
        }
        $filename .= '.jpg';
        $filePath = $uploadDir . $filename;
        write_log("Generierter Dateipfad: " . $filePath);

        if (file_put_contents($filePath, $imageData) !== false) {
            http_response_code(200);
            $successMsg = "Bild erfolgreich hochgeladen und gespeichert als: " . $filename;
            write_log($successMsg . " Sende HTTP 200.");
            
            // Metadaten für Workflow-Verarbeitung extrahieren
            $metadata = get_metadata_from_filename($filename);
            if ($metadata) {
                write_log("Starte Workflow-Verarbeitung für Bild: " . $filename);
                process_workflows($filePath, $metadata, $db);
            }
            
            echo $successMsg;
        } else {
            http_response_code(500);
            $errorMsg = "Fehler: Bild konnte nicht gespeichert werden unter " . $filePath;
            write_log($errorMsg . " Sende HTTP 500.");
            echo $errorMsg;
        }
        exit;
    }
}

// AJAX-Endpoint für Workflow-Daten
if (isset($_GET['get_workflow'])) {
    $workflow_id = (int)$_GET['get_workflow'];
    $stmt = $db->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmt->bindValue(1, $workflow_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $workflow = $result->fetchArray(SQLITE3_ASSOC);
    
    header('Content-Type: application/json');
    if ($workflow) {
        echo json_encode(['success' => true, 'workflow' => $workflow]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Workflow nicht gefunden']);
    }
    exit;
}

write_log("GET-Request wird bearbeitet (HTML-Seite wird angezeigt).");

// Funktion zum Extrahieren von Metadaten für Filter
function get_metadata_from_filename($filename) {
    if (preg_match('/^(\d{8}-\d{6})_([a-zA-Z0-9_-]+)_([a-zA-Z0-9_-]+)(_vbat\d+mV)?\.jpg$/', basename($filename), $matches)) {
        $timestamp_short = $matches[1];
        $field2_from_regex = $matches[2];
        $field3_from_regex = $matches[3];

        $final_esp_id = $field2_from_regex;
        $final_wake_reason = $field3_from_regex;

        if (preg_match('/^([0-9a-fA-F]+)_([a-zA-Z]+)$/i', $field2_from_regex, $split_field2_matches)) {
            $final_esp_id = $split_field2_matches[1];
            $final_wake_reason = $split_field2_matches[2];
        } else {
            $valid_wake_reasons = ['TIMER', 'PIR', 'POWERON'];
            $is_valid_reason = false;
            foreach ($valid_wake_reasons as $valid_reason) {
                if (strcasecmp($final_wake_reason, $valid_reason) == 0) {
                    $is_valid_reason = true;
                    $final_wake_reason = $valid_reason;
                    break;
                }
            }

            if (!$is_valid_reason) {
                $final_wake_reason = 'UNKNOWN';
            }
        }

        return [
            'timestamp_short' => $timestamp_short,
            'esp_id' => $final_esp_id,
            'wake_reason' => $final_wake_reason,
            'date' => substr($timestamp_short, 0, 8)
        ];
    }
    return null;
}

// Get all files
$all_files = glob($uploadDir . '*.jpg');
$esp_ids = [];
$wake_reasons = [];
$calendar_data = [];

// Current view mode
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'recent';
$selected_date = isset($_GET['date']) ? $_GET['date'] : '';

// Action Handling für alle POST-Requests mit 'action' Parameter
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Workflow-Management Actions
    if ($action === 'create_workflow') {
        // Notification-Kanäle aus Checkboxen erstellen
        $notification_channels = [];
        if (!empty($_POST['notify_email'])) $notification_channels[] = 'email';
        if (!empty($_POST['notify_discord'])) $notification_channels[] = 'discord';
        if (!empty($_POST['notify_nextcloud'])) $notification_channels[] = 'nextcloud';
        $notification_channels_str = implode(',', $notification_channels);
        
        $stmt = $db->prepare("INSERT INTO workflows (name, filter_esp_id, filter_wake_reason, ollama_url, ollama_model, ai_prompt, email_recipient, notification_channels, discord_webhook_url, nextcloud_server_url, nextcloud_talk_token, nextcloud_username, nextcloud_password, attach_image_discord) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $_POST['name'], SQLITE3_TEXT);
        $stmt->bindValue(2, $_POST['filter_esp_id'], SQLITE3_TEXT);
        $stmt->bindValue(3, $_POST['filter_wake_reason'], SQLITE3_TEXT);
        $stmt->bindValue(4, $_POST['ollama_url'], SQLITE3_TEXT);
        $stmt->bindValue(5, $_POST['ollama_model'], SQLITE3_TEXT);
        $stmt->bindValue(6, $_POST['ai_prompt'], SQLITE3_TEXT);
        $stmt->bindValue(7, $_POST['email_recipient'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(8, $notification_channels_str, SQLITE3_TEXT);
        $stmt->bindValue(9, $_POST['discord_webhook_url'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(10, $_POST['nextcloud_server_url'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(11, $_POST['nextcloud_talk_token'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(12, $_POST['nextcloud_username'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(13, $_POST['nextcloud_password'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(14, !empty($_POST['attach_image_discord']) ? 1 : 0, SQLITE3_INTEGER);
        $stmt->execute();
        write_log("Neuer Workflow erstellt: " . $_POST['name'] . " (Kanäle: $notification_channels_str)");
        header("Location: ?view=workflows");
        exit;
    } elseif ($action === 'toggle_workflow') {
        $workflow_id = (int)$_POST['workflow_id'];
        $stmt = $db->prepare("UPDATE workflows SET active = NOT active WHERE id = ?");
        $stmt->bindValue(1, $workflow_id, SQLITE3_INTEGER);
        $stmt->execute();
        write_log("Workflow-Status geändert für ID: " . $workflow_id);
        header("Location: ?view=workflows");
        exit;
    } elseif ($action === 'edit_workflow') {
        $workflow_id = (int)$_POST['workflow_id'];
        
        // Notification-Kanäle aus Checkboxen erstellen
        $notification_channels = [];
        if (!empty($_POST['notify_email'])) $notification_channels[] = 'email';
        if (!empty($_POST['notify_discord'])) $notification_channels[] = 'discord';
        if (!empty($_POST['notify_nextcloud'])) $notification_channels[] = 'nextcloud';
        $notification_channels_str = implode(',', $notification_channels);
        
        $stmt = $db->prepare("UPDATE workflows SET name=?, filter_esp_id=?, filter_wake_reason=?, ollama_url=?, ollama_model=?, ai_prompt=?, email_recipient=?, notification_channels=?, discord_webhook_url=?, nextcloud_server_url=?, nextcloud_talk_token=?, nextcloud_username=?, nextcloud_password=?, attach_image_discord=? WHERE id=?");
        $stmt->bindValue(1, $_POST['name'], SQLITE3_TEXT);
        $stmt->bindValue(2, $_POST['filter_esp_id'], SQLITE3_TEXT);
        $stmt->bindValue(3, $_POST['filter_wake_reason'], SQLITE3_TEXT);
        $stmt->bindValue(4, $_POST['ollama_url'], SQLITE3_TEXT);
        $stmt->bindValue(5, $_POST['ollama_model'], SQLITE3_TEXT);
        $stmt->bindValue(6, $_POST['ai_prompt'], SQLITE3_TEXT);
        $stmt->bindValue(7, $_POST['email_recipient'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(8, $notification_channels_str, SQLITE3_TEXT);
        $stmt->bindValue(9, $_POST['discord_webhook_url'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(10, $_POST['nextcloud_server_url'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(11, $_POST['nextcloud_talk_token'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(12, $_POST['nextcloud_username'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(13, $_POST['nextcloud_password'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(14, !empty($_POST['attach_image_discord']) ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(15, $workflow_id, SQLITE3_INTEGER);
        $stmt->execute();
        write_log("Workflow bearbeitet ID: " . $workflow_id . " - " . $_POST['name'] . " (Kanäle: $notification_channels_str)");
        header("Location: ?view=workflows");
        exit;
    } elseif ($action === 'delete_workflow') {
        $workflow_id = (int)$_POST['workflow_id'];
        $stmt = $db->prepare("DELETE FROM processed_images WHERE workflow_id = ?");
        $stmt->bindValue(1, $workflow_id, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt = $db->prepare("DELETE FROM workflows WHERE id = ?");
        $stmt->bindValue(1, $workflow_id, SQLITE3_INTEGER);
        $stmt->execute();
        write_log("Workflow gelöscht ID: " . $workflow_id);
        header("Location: ?view=workflows");
        exit;
    }
    
    // Image Actions
    elseif ($action === 'delete_image') {
        $image_path = $_POST['image_path'] ?? '';
        $current_view = $_POST['current_view'] ?? 'recent';
        $current_date = $_POST['current_date'] ?? '';
        
        if (!empty($image_path) && file_exists($image_path)) {
            if (unlink($image_path)) {
                write_log("Bild gelöscht: " . $image_path);
                $success_msg = "Bild erfolgreich gelöscht.";
            } else {
                write_log("Fehler beim Löschen von Bild: " . $image_path);
                $error_msg = "Fehler beim Löschen des Bildes.";
            }
        } else {
            write_log("Versuch ein nicht existierendes Bild zu löschen: " . $image_path);
            $error_msg = "Bild nicht gefunden.";
        }
        
        // Redirect zurück zur aktuellen Ansicht
        $redirect_url = "?view=" . urlencode($current_view);
        if (!empty($current_date)) {
            $redirect_url .= "&date=" . urlencode($current_date);
        }
        if (isset($success_msg)) $redirect_url .= "&msg=" . urlencode($success_msg);
        if (isset($error_msg)) $redirect_url .= "&error=" . urlencode($error_msg);
        
        header("Location: " . $redirect_url);
        exit;
    }
    
    elseif ($action === 'clear_lock') {
        $current_view = $_POST['current_view'] ?? 'workflows';
        $current_date = $_POST['current_date'] ?? '';
        
        $lock_file = __DIR__ . '/ollama_processing.lock';
        if (file_exists($lock_file)) {
            unlink($lock_file);
            $success_msg = "Ollama-Lock wurde manuell bereinigt.";
            write_log("Ollama-Lock wurde manuell bereinigt.");
        } else {
            $error_msg = "Kein Lock-File gefunden.";
        }
        
        // Redirect zurück zur aktuellen Ansicht
        $redirect_url = "?view=" . urlencode($current_view);
        if (!empty($current_date)) {
            $redirect_url .= "&date=" . urlencode($current_date);
        }
        if (isset($success_msg)) $redirect_url .= "&msg=" . urlencode($success_msg);
        if (isset($error_msg)) $redirect_url .= "&error=" . urlencode($error_msg);
        
        header("Location: " . $redirect_url);
        exit;
    }
    
    elseif ($action === 'analyze_image') {
        $image_path = $_POST['image_path'] ?? '';
        $current_view = $_POST['current_view'] ?? 'recent';
        $current_date = $_POST['current_date'] ?? '';
        
        if (!empty($image_path) && file_exists($image_path)) {
            // Metadaten extrahieren
            $metadata = get_metadata_from_filename(basename($image_path));
            if ($metadata) {
                write_log("Manuelle KI-Analyse gestartet für: " . basename($image_path));
                process_workflows($image_path, $metadata, $db);
                $success_msg = "KI-Analyse wurde für alle aktiven Workflows gestartet.";
            } else {
                $error_msg = "Metadaten konnten nicht extrahiert werden.";
            }
        } else {
            $error_msg = "Bild nicht gefunden.";
        }
        
        // Redirect zurück zur aktuellen Ansicht
        $redirect_url = "?view=" . urlencode($current_view);
        if (!empty($current_date)) {
            $redirect_url .= "&date=" . urlencode($current_date);
        }
        if (isset($success_msg)) $redirect_url .= "&msg=" . urlencode($success_msg);
        if (isset($error_msg)) $redirect_url .= "&error=" . urlencode($error_msg);
        
        header("Location: " . $redirect_url);
        exit;
    }
}

if ($all_files) {
    foreach ($all_files as $file) {
        $metadata = get_metadata_from_filename($file);
        if ($metadata) {
            if (!in_array($metadata['esp_id'], $esp_ids)) {
                $esp_ids[] = $metadata['esp_id'];
            }
            if (!in_array($metadata['wake_reason'], $wake_reasons)) {
                $wake_reasons[] = $metadata['wake_reason'];
            }
            
            // Build calendar data
            $date = $metadata['date'];
            if (!isset($calendar_data[$date])) {
                $calendar_data[$date] = 0;
            }
            $calendar_data[$date]++;
        }
    }
    sort($esp_ids);
    sort($wake_reasons);
}

// Filter files based on view mode
$files_to_display = [];
$filter_esp_id = isset($_GET['filter_esp_id']) ? $_GET['filter_esp_id'] : '';
$filter_wake_reason = isset($_GET['filter_wake_reason']) ? $_GET['filter_wake_reason'] : '';

if ($all_files) {
    $now = time();
    $last_24_hours = $now - (24 * 60 * 60);
    
    foreach ($all_files as $file) {
        $file_time = filemtime($file);
        $metadata = get_metadata_from_filename($file);
        
        // Apply view mode filter
        $include_file = false;
        if ($view_mode === 'recent') {
            $include_file = ($file_time >= $last_24_hours);
        } elseif ($view_mode === 'date' && $selected_date && $metadata) {
            $include_file = ($metadata['date'] === $selected_date);
        }
        
        if ($include_file && $metadata) {
            $match_esp_id = empty($filter_esp_id) || $metadata['esp_id'] === $filter_esp_id;
            $match_wake_reason = empty($filter_wake_reason) || $metadata['wake_reason'] === $filter_wake_reason;

            if ($match_esp_id && $match_wake_reason) {
                $files_to_display[] = $file;
            }
        }
    }
    
    if (!empty($files_to_display)) {
        array_multisort(array_map('filemtime', $files_to_display), SORT_DESC, $files_to_display);
    }
}

// Generate calendar for last 3 months
function generate_calendar($calendar_data) {
    $months = [];
    $current_date = new DateTime();
    
    // Start from 2 months ago to get proper order (oldest to newest)
    $start_date = clone $current_date;
    $start_date->modify('-2 months');
    
    for ($i = 0; $i < 3; $i++) {
        $month_data = [];
        $month_data['name'] = $start_date->format('F Y');
        $month_data['days'] = [];
        
        $first_day = new DateTime($start_date->format('Y-m-01'));
        $last_day = new DateTime($start_date->format('Y-m-t'));
        $start_of_week = clone $first_day;
        
        // Adjust for Monday start (ISO 8601: Monday = 1, Sunday = 0)
        $day_of_week = $first_day->format('N'); // 1 = Monday, 7 = Sunday
        $start_of_week->modify('-' . ($day_of_week - 1) . ' days');
        
        for ($day = clone $start_of_week; $day <= $last_day; $day->modify('+1 day')) {
            $date_str = $day->format('Ymd');
            $is_current_month = $day->format('Y-m') === $start_date->format('Y-m');
            $image_count = isset($calendar_data[$date_str]) ? $calendar_data[$date_str] : 0;
            
            $month_data['days'][] = [
                'date' => $date_str,
                'day' => $day->format('j'),
                'is_current_month' => $is_current_month,
                'image_count' => $image_count,
                'has_images' => $image_count > 0
            ];
        }
        
        $months[] = $month_data;
        $start_date->modify('+1 month');
    }
    
    return $months;
}

$calendar_months = generate_calendar($calendar_data);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoSnapCam</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #1d1d1f;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1d1d1f;
            margin-bottom: 10px;
            letter-spacing: -0.02em;
        }
        
        .subtitle {
            font-size: 1.1rem;
            color: #86868b;
            font-weight: 400;
        }
        
        .view-toggle {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .toggle-button {
            background: rgba(255, 255, 255, 0.8);
            border: none;
            padding: 12px 24px;
            margin: 0 4px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .toggle-button.active {
            background: #007aff;
            color: white;
            box-shadow: 0 4px 15px rgba(0, 122, 255, 0.3);
        }
        
        .toggle-button:hover:not(.active) {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
        }
        
        .filters {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .filter-group {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        
        .filter-item label {
            font-size: 13px;
            font-weight: 500;
            color: #86868b;
        }
        
        .filter-item select {
            padding: 8px 16px;
            border: 1px solid #d2d2d7;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            min-width: 120px;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .month-container {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            padding: 20px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .month-title {
            font-size: 1.2rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 15px;
            color: #1d1d1f;
        }
        
        .calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
        }
        
        .day-header {
            text-align: center;
            font-size: 12px;
            font-weight: 500;
            color: #86868b;
            padding: 8px 0;
        }
        
        .day-cell {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            min-height: 45px;
        }
        
        .day-cell:hover {
            background: rgba(0, 122, 255, 0.1);
        }
        
        .day-cell.other-month {
            opacity: 0.3;
        }
        
        .day-cell.has-images {
            background: rgba(0, 122, 255, 0.1);
        }
        
        .day-cell.has-images:hover {
            background: rgba(0, 122, 255, 0.2);
            transform: scale(1.05);
        }
        
        .day-number {
            font-size: 11px;
            font-weight: 500;
            position: absolute;
            top: 3px;
            left: 6px;
            color: #1d1d1f;
        }
        
        .day-cell.other-month .day-number {
            color: #86868b;
        }
        
        .image-count {
            font-size: 16px;
            font-weight: 600;
            color: #007aff;
        }
        
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .image-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            overflow: hidden;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .image-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .image-container {
            cursor: pointer;
        }
        
        .image-container img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
        }
        
        .image-info {
            padding: 15px;
        }
        
        .image-title {
            font-size: 14px;
            font-weight: 600;
            color: #1d1d1f;
            margin-bottom: 5px;
        }
        
        .image-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 5px;
        }
        
        .image-meta {
            font-size: 12px;
            color: #86868b;
        }
        
        .image-actions {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            background: none;
            border: none;
            padding: 6px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
        }
        
        .ai-btn {
            color: #007aff;
        }
        
        .ai-btn:hover {
            background: rgba(0, 122, 255, 0.1);
        }
        
        .delete-btn {
            color: #ff3b30;
        }
        
        .delete-btn:hover {
            background: rgba(255, 59, 48, 0.1);
        }
        
        .no-images {
            text-align: center;
            padding: 60px 20px;
            color: #86868b;
            font-size: 16px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(10px);
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 90vw;
            max-height: 90vh;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .modal-caption {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 14px;
            backdrop-filter: blur(10px);
        }
        
        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            backdrop-filter: blur(10px);
            transition: all 0.2s ease;
        }
        
        .close-modal:hover {
            background: rgba(0, 0, 0, 0.9);
            transform: scale(1.1);
        }
        
        .workflow-management {
            margin-bottom: 40px;
        }
        
        .workflow-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 0 20px;
        }
        
        .workflow-header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .workflow-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #1d1d1f;
            margin: 0;
        }
        
        .btn-primary {
            background: #007aff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .workflows-list {
            display: grid;
            gap: 20px;
        }
        
        .workflow-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            padding: 20px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: all 0.3s ease;
        }
        
        .workflow-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .workflow-card.inactive {
            opacity: 0.6;
            border-left: 4px solid #ff3b30;
        }
        
        .workflow-card.active {
            border-left: 4px solid #30d158;
        }
        
        .workflow-info h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1d1d1f;
            margin: 0 0 10px 0;
        }
        
        .workflow-details {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .detail {
            font-size: 13px;
            color: #86868b;
        }
        
        .workflow-actions {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        
        .btn-toggle, .btn-delete, .btn-edit {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-toggle {
            background: #007aff;
            color: white;
        }
        
        .btn-toggle:hover {
            background: #0056b3;
        }
        
        .btn-delete {
            background: #ff3b30;
            color: white;
        }
        
        .btn-delete:hover {
            background: #d70015;
        }
        
        .btn-edit {
            background: #34c759;
            color: white;
        }
        
        .btn-edit:hover {
            background: #28a745;
        }
        
        .no-workflows {
            text-align: center;
            padding: 60px 20px;
            color: #86868b;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            backdrop-filter: blur(20px);
        }
        
        .workflow-modal {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 90vw;
        }
        
        .workflow-modal h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 25px;
            color: #1d1d1f;
        }
        
        .workflow-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group label {
            font-size: 14px;
            font-weight: 500;
            color: #1d1d1f;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            padding: 12px 16px;
            border: 1px solid #d2d2d7;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #007aff;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 10px;
        }
        
        .form-actions button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .form-actions button[type="button"] {
            background: #f2f2f7;
            color: #1d1d1f;
        }
        
        .form-actions button[type="button"]:hover {
            background: #e5e5ea;
        }
        
        /* Notification-Kanäle Styling */
        .notification-channels {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 8px;
        }
        
        .channel-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid #e5e5ea;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .channel-option:hover {
            background: rgba(0, 122, 255, 0.05);
            border-color: #007aff;
        }
        
        .channel-option input[type="checkbox"] {
            margin: 0;
            transform: scale(1.1);
        }
        
        .channel-option label {
            margin: 0;
            font-weight: 500;
            cursor: pointer;
        }
        
        .email-config, .discord-config, .nextcloud-config {
            margin-top: 15px;
            padding: 15px;
            background: rgba(0, 122, 255, 0.05);
            border-radius: 8px;
            border-left: 3px solid #007aff;
        }
        
        .discord-config {
            background: rgba(114, 137, 218, 0.05);
            border-left-color: #7289da;
        }
        
        .nextcloud-config {
            background: rgba(0, 130, 201, 0.05);
            border-left-color: #0082c9;
        }
        
        .ollama-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }
        
        .status-ready {
            background: rgba(48, 209, 88, 0.1);
            color: #30d158;
            border: 1px solid rgba(48, 209, 88, 0.3);
        }
        
        .status-busy {
            background: rgba(255, 149, 0, 0.1);
            color: #ff9500;
            border: 1px solid rgba(255, 149, 0, 0.3);
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .status-ready .status-indicator {
            background: #30d158;
        }
        
        .status-busy .status-indicator {
            background: #ff9500;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .notification {
            padding: 12px 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 14px;
            font-weight: 500;
        }
        
        .notification.success {
            background: rgba(48, 209, 88, 0.1);
            color: #30d158;
            border: 1px solid rgba(48, 209, 88, 0.3);
        }
        
        .notification.error {
            background: rgba(255, 59, 48, 0.1);
            color: #ff3b30;
            border: 1px solid rgba(255, 59, 48, 0.3);
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .title {
                font-size: 2rem;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: center;
                gap: 15px;
            }
            
            .gallery {
                grid-template-columns: 1fr;
            }
            
            .calendar-grid {
                grid-template-columns: 1fr;
            }
            
            .workflow-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .workflow-header-right {
                flex-direction: column;
                gap: 10px;
            }
            
            .workflow-card {
                flex-direction: column;
                gap: 15px;
            }
            
            .workflow-actions {
                justify-content: flex-start;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div id="myModal" class="modal">
        <button class="close-modal">&times;</button>
        <img class="modal-content" id="imgModal">
        <div id="captionModal" class="modal-caption"></div>
    </div>

    <div class="container">
        <div class="header">
            <h1 class="title">EcoSnapCam</h1>
            <p class="subtitle">Wildlife Camera Gallery</p>
        </div>

        <div class="view-toggle">
            <button class="toggle-button <?php echo $view_mode === 'recent' ? 'active' : ''; ?>" 
                    onclick="setView('recent')">Last 24 Hours</button>
            <button class="toggle-button <?php echo $view_mode === 'calendar' ? 'active' : ''; ?>" 
                    onclick="setView('calendar')">Calendar View</button>
            <button class="toggle-button <?php echo $view_mode === 'workflows' ? 'active' : ''; ?>" 
                    onclick="setView('workflows')">KI Workflows</button>
        </div>

        <?php if (isset($_GET['msg'])): ?>
        <div class="notification success">
            <?php echo htmlspecialchars($_GET['msg']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <div class="notification error">
            <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
        <?php endif; ?>

        <?php if ($view_mode === 'recent' || $view_mode === 'date'): ?>
        <div class="filters">
            <form method="GET" action="">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
                <?php if ($selected_date): ?>
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <?php endif; ?>
                
                <div class="filter-group">
                    <div class="filter-item">
                        <label for="filter_esp_id">Device ID</label>
                        <select name="filter_esp_id" id="filter_esp_id" onchange="this.form.submit()">
                            <option value="">All Devices</option>
                            <?php foreach ($esp_ids as $id): ?>
                                <option value="<?php echo htmlspecialchars($id); ?>" <?php if ($id === $filter_esp_id) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($id); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label for="filter_wake_reason">Wake Reason</label>
                        <select name="filter_wake_reason" id="filter_wake_reason" onchange="this.form.submit()">
                            <option value="">All Reasons</option>
                            <?php foreach ($wake_reasons as $reason): ?>
                                <option value="<?php echo htmlspecialchars($reason); ?>" <?php if ($reason === $filter_wake_reason) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($reason); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($view_mode === 'calendar'): ?>
        <div class="calendar-grid">
            <?php foreach ($calendar_months as $month): ?>
            <div class="month-container">
                <h3 class="month-title"><?php echo $month['name']; ?></h3>
                <div class="calendar">
                    <div class="day-header">Mon</div>
                    <div class="day-header">Tue</div>
                    <div class="day-header">Wed</div>
                    <div class="day-header">Thu</div>
                    <div class="day-header">Fri</div>
                    <div class="day-header">Sat</div>
                    <div class="day-header">Sun</div>
                    
                    <?php foreach ($month['days'] as $day): ?>
                    <div class="day-cell <?php echo !$day['is_current_month'] ? 'other-month' : ''; ?> <?php echo $day['has_images'] ? 'has-images' : ''; ?>"
                         <?php if ($day['has_images']): ?>onclick="viewDate('<?php echo $day['date']; ?>')"<?php endif; ?>>
                        <span class="day-number"><?php echo $day['day']; ?></span>
                        <?php if ($day['image_count'] > 0): ?>
                        <span class="image-count"><?php echo $day['image_count']; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($view_mode === 'recent' || $view_mode === 'date'): ?>
        <div class="gallery">
            <?php
            if (!empty($files_to_display)) {
                foreach ($files_to_display as $file) {
                    $fullFileName = basename($file);
                    $metadata = get_metadata_from_filename($fullFileName);
                    $displayFileName = $fullFileName;
                    $formattedDate = '';

                    if ($metadata) {
                        $displayFileName = $metadata['esp_id'] . ' • ' . $metadata['wake_reason'];
                        if (preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $metadata['timestamp_short'], $dateMatches)) {
                             $formattedDate = "{$dateMatches[3]}.{$dateMatches[2]}.{$dateMatches[1]} {$dateMatches[4]}:{$dateMatches[5]}:{$dateMatches[6]}";
                        }
                    } else {
                        $fileModTime = filemtime($file);
                        $formattedDate = date('d.m.Y H:i:s', $fileModTime);
                    }

                    echo '<div class="image-card">';
                    echo '<div class="image-container" onclick="openModal(this)">';
                    echo '<img src="' . htmlspecialchars($file) . '" alt="' . htmlspecialchars($fullFileName) . '" data-filename="' . htmlspecialchars($fullFileName) . '">';
                    echo '</div>';
                    echo '<div class="image-info">';
                    echo '<div class="image-title">' . htmlspecialchars($displayFileName) . '</div>';
                    echo '<div class="image-footer">';
                    echo '<div class="image-meta">' . $formattedDate . '</div>';
                    echo '<div class="image-actions">';
                    
                    // KI-Analyse Button
                    echo '<form method="post" style="display: inline;" onclick="event.stopPropagation();">';
                    echo '<input type="hidden" name="action" value="analyze_image">';
                    echo '<input type="hidden" name="image_path" value="' . htmlspecialchars($file) . '">';
                    echo '<input type="hidden" name="current_view" value="' . htmlspecialchars($view_mode) . '">';
                    if ($selected_date) echo '<input type="hidden" name="current_date" value="' . htmlspecialchars($selected_date) . '">';
                    echo '<button type="submit" class="action-btn ai-btn" title="KI-Analyse senden">';
                    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
                    echo '<path d="M9 19c-5 0-8-3-8-8s3-8 8-8 8 3 8 8-3 8-8 8"/>';
                    echo '<path d="M17 17l-5-5"/>';
                    echo '<circle cx="19" cy="5" r="2"/>';
                    echo '</svg>';
                    echo '</button>';
                    echo '</form>';
                    
                    // Löschen Button
                    echo '<form method="post" style="display: inline;" onclick="event.stopPropagation();" onsubmit="return confirm(\'Bild wirklich löschen?\');">';
                    echo '<input type="hidden" name="action" value="delete_image">';
                    echo '<input type="hidden" name="image_path" value="' . htmlspecialchars($file) . '">';
                    echo '<input type="hidden" name="current_view" value="' . htmlspecialchars($view_mode) . '">';
                    if ($selected_date) echo '<input type="hidden" name="current_date" value="' . htmlspecialchars($selected_date) . '">';
                    echo '<button type="submit" class="action-btn delete-btn" title="Bild löschen">';
                    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
                    echo '<polyline points="3,6 5,6 21,6"/>';
                    echo '<path d="M19,6v14a2,2 0 0,1-2,2H7a2,2 0 0,1-2-2V6m3,0V4a2,2 0 0,1,2-2h4a2,2 0 0,1,2,2v2"/>';
                    echo '<line x1="10" y1="11" x2="10" y2="17"/>';
                    echo '<line x1="14" y1="11" x2="14" y2="17"/>';
                    echo '</svg>';
                    echo '</button>';
                    echo '</form>';
                    
                    echo '</div>'; // image-actions
                    echo '</div>'; // image-footer
                    echo '</div>'; // image-info
                    echo '</div>'; // image-card
                }
            } else {
                $message = $view_mode === 'date' ? 'No images found for the selected date.' : 'No images from the last 24 hours.';
                echo '<div class="no-images">' . $message . '</div>';
            }
            ?>
        </div>
        <?php endif; ?>

        <?php if ($view_mode === 'workflows'): ?>
        <div class="workflow-management">
            <div class="workflow-header">
                <h2>KI-Analyse Workflows</h2>
                <div class="workflow-header-right">
                    <?php 
                    $lock_status = get_ollama_lock_status();
                    $status_class = $lock_status['locked'] ? 'status-busy' : 'status-ready';
                    ?>
                    <div class="ollama-status <?php echo $status_class; ?>">
                        <span class="status-indicator"></span>
                        <?php echo htmlspecialchars($lock_status['message']); ?>
                        <?php if ($lock_status['locked']): ?>
                            <form method="post" style="display:inline; margin-left: 10px;">
                                <input type="hidden" name="action" value="clear_lock">
                                <input type="hidden" name="current_view" value="workflows">
                                <button type="submit" class="btn-secondary" style="font-size: 12px; padding: 4px 8px;" 
                                        onclick="return confirm('Lock wirklich löschen? Dies kann laufende Verarbeitungen unterbrechen.')">
                                    Lock löschen
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <button class="btn-primary" onclick="showCreateWorkflowModal()">Neuen Workflow erstellen</button>
                </div>
            </div>

            <div class="workflows-list">
                <?php
                $stmt = $db->prepare("SELECT w.*, COUNT(pi.id) as processed_count FROM workflows w LEFT JOIN processed_images pi ON w.id = pi.workflow_id GROUP BY w.id ORDER BY w.created_at DESC");
                $result = $stmt->execute();
                
                if ($result->fetchArray(SQLITE3_NUM)) {
                    $result->reset();
                    while ($workflow = $result->fetchArray(SQLITE3_ASSOC)) {
                        $status_class = $workflow['active'] ? 'active' : 'inactive';
                        echo '<div class="workflow-card ' . $status_class . '">';
                        echo '<div class="workflow-info">';
                        echo '<h3>' . htmlspecialchars($workflow['name']) . '</h3>';
                        echo '<div class="workflow-details">';
                        echo '<span class="detail">Modell: ' . htmlspecialchars($workflow['ollama_model']) . '</span>';
                        echo '<span class="detail">Filter: ';
                        if (!empty($workflow['filter_esp_id'])) echo 'ESP-ID=' . htmlspecialchars($workflow['filter_esp_id']) . ' ';
                        if (!empty($workflow['filter_wake_reason'])) echo 'Wake=' . htmlspecialchars($workflow['filter_wake_reason']);
                        if (empty($workflow['filter_esp_id']) && empty($workflow['filter_wake_reason'])) echo 'Alle Bilder';
                        echo '</span>';
                        // Notification-Kanäle anzeigen
                        $channels = explode(',', $workflow['notification_channels'] ?? 'email');
                        $channel_icons = [
                            'email' => '📧',
                            'discord' => '💬',
                            'nextcloud' => '☁️'
                        ];
                        $channel_display = [];
                        foreach ($channels as $channel) {
                            $channel = trim($channel);
                            if (isset($channel_icons[$channel])) {
                                $channel_display[] = $channel_icons[$channel] . ' ' . ucfirst($channel);
                            }
                        }
                        echo '<span class="detail">Benachrichtigung: ' . implode(', ', $channel_display) . '</span>';
                        
                        // Details für aktive Kanäle
                        if (in_array('email', $channels) && !empty($workflow['email_recipient'])) {
                            echo '<span class="detail">E-Mail: ' . htmlspecialchars($workflow['email_recipient']) . '</span>';
                        }
                        if (in_array('discord', $channels) && !empty($workflow['discord_webhook_url'])) {
                            $discord_detail = 'Discord: Webhook konfiguriert';
                            if (!empty($workflow['attach_image_discord']) && $workflow['attach_image_discord'] == 1) {
                                $discord_detail .= ' 📸';
                            }
                            echo '<span class="detail">' . $discord_detail . '</span>';
                        }
                        if (in_array('nextcloud', $channels) && !empty($workflow['nextcloud_server_url'])) {
                            echo '<span class="detail">Nextcloud: ' . htmlspecialchars(parse_url($workflow['nextcloud_server_url'], PHP_URL_HOST)) . '</span>';
                        }
                        echo '<span class="detail">Verarbeitet: ' . $workflow['processed_count'] . ' Bilder</span>';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="workflow-actions">';
                        echo '<button type="button" class="btn-edit" onclick="showEditWorkflowModal(' . $workflow['id'] . ')">Bearbeiten</button>';
                        echo '<form method="post" style="display: inline;">';
                        echo '<input type="hidden" name="action" value="toggle_workflow">';
                        echo '<input type="hidden" name="workflow_id" value="' . $workflow['id'] . '">';
                        echo '<button type="submit" class="btn-toggle">' . ($workflow['active'] ? 'Deaktivieren' : 'Aktivieren') . '</button>';
                        echo '</form>';
                        echo '<form method="post" style="display: inline;" onsubmit="return confirm(\'Workflow wirklich löschen?\')">';
                        echo '<input type="hidden" name="action" value="delete_workflow">';
                        echo '<input type="hidden" name="workflow_id" value="' . $workflow['id'] . '">';
                        echo '<button type="submit" class="btn-delete">Löschen</button>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="no-workflows">Noch keine Workflows erstellt.</div>';
                }
                ?>
            </div>
        </div>

        <!-- Create Workflow Modal -->
        <div id="createWorkflowModal" class="modal" style="display: none;">
            <div class="modal-content workflow-modal">
                <button class="close-modal" onclick="hideCreateWorkflowModal()">&times;</button>
                <h3>Neuen Workflow erstellen</h3>
                <form method="post" class="workflow-form">
                    <input type="hidden" name="action" value="create_workflow">
                    
                    <div class="form-group">
                        <label for="name">Workflow-Name:</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="filter_esp_id">ESP-ID Filter (optional):</label>
                            <select name="filter_esp_id" id="filter_esp_id">
                                <option value="">Alle ESP-IDs</option>
                                <?php foreach ($esp_ids as $id): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($id); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="filter_wake_reason">Wake Reason Filter (optional):</label>
                            <select name="filter_wake_reason" id="filter_wake_reason">
                                <option value="">Alle Wake Reasons</option>
                                <?php foreach ($wake_reasons as $reason): ?>
                                    <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="ollama_url">Ollama URL:</label>
                            <input type="url" id="ollama_url" name="ollama_url" value="http://localhost:11434" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="ollama_model">Modell-Name:</label>
                            <input type="text" id="ollama_model" name="ollama_model" value="llava" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="ai_prompt">KI-Prompt für Bildanalyse:</label>
                        <textarea id="ai_prompt" name="ai_prompt" rows="4" required placeholder="Beschreibe was du auf diesem Bild siehst. Achte besonders auf Tiere und ihre Aktivitäten."></textarea>
                    </div>
                    
                    <!-- Notification-Kanäle -->
                    <div class="form-group">
                        <label>Benachrichtigungen senden über:</label>
                        <div class="notification-channels">
                            <div class="channel-option">
                                <input type="checkbox" id="notify_email" name="notify_email" value="1" checked onchange="toggleEmailFields()">
                                <label for="notify_email">📧 E-Mail</label>
                            </div>
                            <div class="channel-option">
                                <input type="checkbox" id="notify_discord" name="notify_discord" value="1" onchange="toggleDiscordFields()">
                                <label for="notify_discord">💬 Discord</label>
                            </div>
                            <div class="channel-option">
                                <input type="checkbox" id="notify_nextcloud" name="notify_nextcloud" value="1" onchange="toggleNextcloudFields()">
                                <label for="notify_nextcloud">☁️ Nextcloud Talk</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- E-Mail Konfiguration -->
                    <div class="form-group email-config" id="email_config">
                        <label for="email_recipient">E-Mail-Empfänger:</label>
                        <input type="email" id="email_recipient" name="email_recipient" placeholder="z.B. test@example.com">
                    </div>
                    
                    <!-- Discord Konfiguration -->
                    <div class="form-group discord-config" id="discord_config" style="display: none;">
                        <label for="discord_webhook_url">Discord Webhook-URL:</label>
                        <input type="url" id="discord_webhook_url" name="discord_webhook_url" placeholder="https://discord.com/api/webhooks/...">
                        <small>Erstelle einen Webhook in deinem Discord-Server: Server → Einstellungen → Integrationen → Webhook</small>
                        
                        <div class="form-group" style="margin-top: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="attach_image_discord" name="attach_image_discord" value="1">
                                <span>📸 Bild mit Discord-Nachricht senden</span>
                            </label>
                            <small>Das analysierte Bild wird direkt in Discord hochgeladen und angezeigt</small>
                        </div>
                    </div>
                    
                    <!-- Nextcloud Talk Konfiguration -->
                    <div class="form-group nextcloud-config" id="nextcloud_config" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nextcloud_server_url">Nextcloud Server-URL:</label>
                                <input type="url" id="nextcloud_server_url" name="nextcloud_server_url" placeholder="https://meine-nextcloud.de">
                            </div>
                            <div class="form-group">
                                <label for="nextcloud_talk_token">Talk-Raum Token:</label>
                                <input type="text" id="nextcloud_talk_token" name="nextcloud_talk_token" placeholder="Raum-Token aus Talk-URL">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nextcloud_username">Benutzername:</label>
                                <input type="text" id="nextcloud_username" name="nextcloud_username" placeholder="Nextcloud-Benutzername">
                            </div>
                            <div class="form-group">
                                <label for="nextcloud_password">Passwort/App-Token:</label>
                                <input type="password" id="nextcloud_password" name="nextcloud_password" placeholder="Passwort oder App-Token">
                            </div>
                        </div>
                        <small>Talk-Token findest du in der URL des Talk-Raums: /call/TOKEN_HIER</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" onclick="hideCreateWorkflowModal()">Abbrechen</button>
                        <button type="submit" class="btn-primary">Workflow erstellen</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Workflow Modal -->
        <div id="editWorkflowModal" class="modal" style="display: none;">
            <div class="modal-content workflow-modal">
                <button class="close-modal" onclick="hideEditWorkflowModal()">&times;</button>
                <h3>Workflow bearbeiten</h3>
                <form method="post" class="workflow-form" id="editWorkflowForm">
                    <input type="hidden" name="action" value="edit_workflow">
                    <input type="hidden" name="workflow_id" id="edit_workflow_id">
                    
                    <div class="form-group">
                        <label for="edit_name">Workflow-Name:</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_filter_esp_id">ESP-ID Filter (optional):</label>
                            <select name="filter_esp_id" id="edit_filter_esp_id">
                                <option value="">Alle ESP-IDs</option>
                                <?php foreach ($esp_ids as $id): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($id); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_filter_wake_reason">Wake Reason Filter (optional):</label>
                            <select name="filter_wake_reason" id="edit_filter_wake_reason">
                                <option value="">Alle Wake Reasons</option>
                                <?php foreach ($wake_reasons as $reason): ?>
                                    <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_ollama_url">Ollama URL:</label>
                            <input type="url" id="edit_ollama_url" name="ollama_url" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_ollama_model">Modell-Name:</label>
                            <input type="text" id="edit_ollama_model" name="ollama_model" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_ai_prompt">KI-Prompt für Bildanalyse:</label>
                        <textarea id="edit_ai_prompt" name="ai_prompt" rows="4" required></textarea>
                    </div>
                    
                    <!-- Notification-Kanäle für Edit-Modal -->
                    <div class="form-group">
                        <label>Benachrichtigungen senden über:</label>
                        <div class="notification-channels">
                            <div class="channel-option">
                                <input type="checkbox" id="edit_notify_email" name="notify_email" value="1" onchange="toggleEditEmailFields()">
                                <label for="edit_notify_email">📧 E-Mail</label>
                            </div>
                            <div class="channel-option">
                                <input type="checkbox" id="edit_notify_discord" name="notify_discord" value="1" onchange="toggleEditDiscordFields()">
                                <label for="edit_notify_discord">💬 Discord</label>
                            </div>
                            <div class="channel-option">
                                <input type="checkbox" id="edit_notify_nextcloud" name="notify_nextcloud" value="1" onchange="toggleEditNextcloudFields()">
                                <label for="edit_notify_nextcloud">☁️ Nextcloud Talk</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- E-Mail Konfiguration für Edit-Modal -->
                    <div class="form-group email-config" id="edit_email_config">
                        <label for="edit_email_recipient">E-Mail-Empfänger:</label>
                        <input type="email" id="edit_email_recipient" name="email_recipient" placeholder="z.B. test@example.com">
                    </div>
                    
                    <!-- Discord Konfiguration für Edit-Modal -->
                    <div class="form-group discord-config" id="edit_discord_config" style="display: none;">
                        <label for="edit_discord_webhook_url">Discord Webhook-URL:</label>
                        <input type="url" id="edit_discord_webhook_url" name="discord_webhook_url" placeholder="https://discord.com/api/webhooks/...">
                        <small>Erstelle einen Webhook in deinem Discord-Server: Server → Einstellungen → Integrationen → Webhook</small>
                        
                        <div class="form-group" style="margin-top: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="edit_attach_image_discord" name="attach_image_discord" value="1">
                                <span>📸 Bild mit Discord-Nachricht senden</span>
                            </label>
                            <small>Das analysierte Bild wird direkt in Discord hochgeladen und angezeigt</small>
                        </div>
                    </div>
                    
                    <!-- Nextcloud Talk Konfiguration für Edit-Modal -->
                    <div class="form-group nextcloud-config" id="edit_nextcloud_config" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_nextcloud_server_url">Nextcloud Server-URL:</label>
                                <input type="url" id="edit_nextcloud_server_url" name="nextcloud_server_url" placeholder="https://meine-nextcloud.de">
                            </div>
                            <div class="form-group">
                                <label for="edit_nextcloud_talk_token">Talk-Raum Token:</label>
                                <input type="text" id="edit_nextcloud_talk_token" name="nextcloud_talk_token" placeholder="Raum-Token aus Talk-URL">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_nextcloud_username">Benutzername:</label>
                                <input type="text" id="edit_nextcloud_username" name="nextcloud_username" placeholder="Nextcloud-Benutzername">
                            </div>
                            <div class="form-group">
                                <label for="edit_nextcloud_password">Passwort/App-Token:</label>
                                <input type="password" id="edit_nextcloud_password" name="nextcloud_password" placeholder="Passwort oder App-Token">
                            </div>
                        </div>
                        <small>Talk-Token findest du in der URL des Talk-Raums: /call/TOKEN_HIER</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" onclick="hideEditWorkflowModal()">Abbrechen</button>
                        <button type="submit" class="btn-primary">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        var modal = document.getElementById("myModal");
        var modalImg = document.getElementById("imgModal");
        var captionText = document.getElementById("captionModal");
        var closeBtn = document.querySelector(".close-modal");

        function openModal(element) {
            var img = element.querySelector('img');
            modal.style.display = "block";
            modalImg.src = img.src;
            captionText.innerHTML = img.getAttribute('data-filename') || img.alt;
        }

        closeBtn.onclick = function() {
            modal.style.display = "none";
        }

        modal.onclick = function(event) {
            if (event.target === modal) {
                modal.style.display = "none";
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape" && modal.style.display === "block") {
                modal.style.display = "none";
            }
        });

        function setView(view) {
            window.location.href = '?view=' + view;
        }

        function viewDate(date) {
            window.location.href = '?view=date&date=' + date;
        }

        function showCreateWorkflowModal() {
            document.getElementById('createWorkflowModal').style.display = 'block';
        }

        function hideCreateWorkflowModal() {
            document.getElementById('createWorkflowModal').style.display = 'none';
        }

        // Toggle-Funktionen für Create-Modal
        function toggleEmailFields() {
            const checked = document.getElementById('notify_email').checked;
            document.getElementById('email_config').style.display = checked ? 'block' : 'none';
            document.getElementById('email_recipient').required = checked;
        }

        function toggleDiscordFields() {
            const checked = document.getElementById('notify_discord').checked;
            document.getElementById('discord_config').style.display = checked ? 'block' : 'none';
        }

        function toggleNextcloudFields() {
            const checked = document.getElementById('notify_nextcloud').checked;
            document.getElementById('nextcloud_config').style.display = checked ? 'block' : 'none';
        }

        // Toggle-Funktionen für Edit-Modal
        function toggleEditEmailFields() {
            const checked = document.getElementById('edit_notify_email').checked;
            document.getElementById('edit_email_config').style.display = checked ? 'block' : 'none';
            document.getElementById('edit_email_recipient').required = checked;
        }

        function toggleEditDiscordFields() {
            const checked = document.getElementById('edit_notify_discord').checked;
            document.getElementById('edit_discord_config').style.display = checked ? 'block' : 'none';
        }

        function toggleEditNextcloudFields() {
            const checked = document.getElementById('edit_notify_nextcloud').checked;
            document.getElementById('edit_nextcloud_config').style.display = checked ? 'block' : 'none';
        }

        function showEditWorkflowModal(workflowId) {
            // Workflow-Daten per AJAX laden
            fetch('?view=workflows&get_workflow=' + workflowId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_workflow_id').value = data.workflow.id;
                        document.getElementById('edit_name').value = data.workflow.name;
                        document.getElementById('edit_filter_esp_id').value = data.workflow.filter_esp_id;
                        document.getElementById('edit_filter_wake_reason').value = data.workflow.filter_wake_reason;
                        document.getElementById('edit_ollama_url').value = data.workflow.ollama_url;
                        document.getElementById('edit_ollama_model').value = data.workflow.ollama_model;
                        document.getElementById('edit_ai_prompt').value = data.workflow.ai_prompt;
                        document.getElementById('edit_email_recipient').value = data.workflow.email_recipient;
                        
                        // Notification-Kanäle setzen
                        const channels = (data.workflow.notification_channels || 'email').split(',');
                        document.getElementById('edit_notify_email').checked = channels.includes('email');
                        document.getElementById('edit_notify_discord').checked = channels.includes('discord');
                        document.getElementById('edit_notify_nextcloud').checked = channels.includes('nextcloud');
                        
                        // Notification-spezifische Felder füllen
                        document.getElementById('edit_discord_webhook_url').value = data.workflow.discord_webhook_url || '';
                        document.getElementById('edit_attach_image_discord').checked = data.workflow.attach_image_discord == 1;
                        document.getElementById('edit_nextcloud_server_url').value = data.workflow.nextcloud_server_url || '';
                        document.getElementById('edit_nextcloud_talk_token').value = data.workflow.nextcloud_talk_token || '';
                        document.getElementById('edit_nextcloud_username').value = data.workflow.nextcloud_username || '';
                        document.getElementById('edit_nextcloud_password').value = data.workflow.nextcloud_password || '';
                        
                        // Toggle-Funktionen aufrufen um Sichtbarkeit zu aktualisieren
                        toggleEditEmailFields();
                        toggleEditDiscordFields();
                        toggleEditNextcloudFields();
                        
                        document.getElementById('editWorkflowModal').style.display = 'block';
                    } else {
                        alert('Fehler beim Laden der Workflow-Daten');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Fehler beim Laden der Workflow-Daten');
                });
        }

        function hideEditWorkflowModal() {
            document.getElementById('editWorkflowModal').style.display = 'none';
        }

        // Close workflow modals when clicking outside
        document.addEventListener('click', function(event) {
            var createModal = document.getElementById('createWorkflowModal');
            var editModal = document.getElementById('editWorkflowModal');
            
            if (event.target === createModal) {
                hideCreateWorkflowModal();
            }
            if (event.target === editModal) {
                hideEditWorkflowModal();
            }
        });
    </script>
</body>
</html>
