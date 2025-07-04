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

write_log("Request erhalten. Methode: " . $_SERVER['REQUEST_METHOD']);

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

// Behandlung von POST-Requests (Bild-Upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    write_log("POST-Request wird bearbeitet.");
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
        echo $successMsg;
    } else {
        http_response_code(500);
        $errorMsg = "Fehler: Bild konnte nicht gespeichert werden unter " . $filePath;
        write_log($errorMsg . " Sende HTTP 500.");
        echo $errorMsg;
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
            cursor: pointer;
        }
        
        .image-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .image-card img {
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
        
        .image-meta {
            font-size: 12px;
            color: #86868b;
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
        </div>

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

                    echo '<div class="image-card" onclick="openModal(this)">';
                    echo '<img src="' . htmlspecialchars($file) . '" alt="' . htmlspecialchars($fullFileName) . '" data-filename="' . htmlspecialchars($fullFileName) . '">';
                    echo '<div class="image-info">';
                    echo '<div class="image-title">' . htmlspecialchars($displayFileName) . '</div>';
                    echo '<div class="image-meta">' . $formattedDate . '</div>';
                    echo '</div>';
                    echo '</div>';
                }
            } else {
                $message = $view_mode === 'date' ? 'No images found for the selected date.' : 'No images from the last 24 hours.';
                echo '<div class="no-images">' . $message . '</div>';
            }
            ?>
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
    </script>
</body>
</html>
