<?php
// Einfache Logging-Funktion
function write_log($message) {
    $logFile = __DIR__ . '/upload_log.txt'; // Log-Datei im selben Verzeichnis wie das Skript
    $timestamp = date('Y-m-d H:i:s');
    // Erstelle die Log-Nachricht, füge GET-Parameter hinzu, wenn vorhanden
    $logMessage = "[{$timestamp}] {$message}";
    if (!empty($_GET)) {
        $logMessage .= " | GET: " . http_build_query($_GET);
    }
    if (!empty($_POST)) { // Obwohl wir php://input verwenden, könnten POST-Parameter für andere Zwecke nützlich sein
        $logMessage .= " | POST: " . http_build_query($_POST);
    }
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

write_log("Request erhalten. Methode: " . $_SERVER['REQUEST_METHOD']);

// Verzeichnis für die Uploads
$uploadDir = 'uploads/';
// Sicherstellen, dass das Upload-Verzeichnis existiert und beschreibbar ist
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
    $timestampFormatted = date('Ymd-His'); // Format: YYYYMMDD-HHMMSS
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
        http_response_code(200); // OK
        $successMsg = "Bild erfolgreich hochgeladen und gespeichert als: " . $filename;
        write_log($successMsg . " Sende HTTP 200.");
        echo $successMsg;
    } else {
        http_response_code(500);
        $errorMsg = "Fehler: Bild konnte nicht gespeichert werden unter " . $filePath;
        write_log($errorMsg . " Sende HTTP 500.");
        echo $errorMsg;
    }
    exit; // Wichtig, um nicht die HTML-Anzeige nach dem POST auszugeben
}

write_log("GET-Request wird bearbeitet (HTML-Seite wird angezeigt).");

// Funktion zum Extrahieren von Metadaten für Filter
function get_metadata_from_filename($filename) {
    // Original regex: YYYYMMDD-HHMMSS_espid_wakereason_vbatXXXXmV.jpg
    // $matches[1]: timestamp, $matches[2]: potenziell esp_id, $matches[3]: potenziell wake_reason, $matches[4]: optionaler vbat_teil
    if (preg_match('/^(\d{8}-\d{6})_([a-zA-Z0-9_-]+)_([a-zA-Z0-9_-]+)(_vbat\d+mV)?\.jpg$/', basename($filename), $matches)) {
        $timestamp_short = $matches[1];
        $field2_from_regex = $matches[2]; // Das, was Regex als ESP-ID interpretiert
        $field3_from_regex = $matches[3]; // Das, was Regex als Aufwachgrund interpretiert

        $final_esp_id = $field2_from_regex;
        $final_wake_reason = $field3_from_regex; // Standardmäßig die ursprüngliche Interpretation

        // Szenario 1: field2_from_regex ist im Format "HEXID_GRUNDTEXT" (z.B. "AABBCC_TIMER")
        // Dies würde bedeuten, dass die Geräte-ID und der Aufwachgrund fälschlicherweise zusammengefasst wurden.
        if (preg_match('/^([0-9a-fA-F]+)_([a-zA-Z]+)$/i', $field2_from_regex, $split_field2_matches)) {
            // $field2_from_regex war tatsächlich "HEXID_GRUNDTEXT"
            $final_esp_id = $split_field2_matches[1];    // Der korrekte HEXID-Teil
            $final_wake_reason = $split_field2_matches[2]; // Der korrekte GRUNDTEXT-Teil
            // In diesem Fall ist $field3_from_regex (der ursprüngliche Aufwachgrund-Match) wahrscheinlich irrelevant oder fehlerhaft
            // und wird durch den Grund aus dem Split ersetzt.
        } else {
            // Szenario 2: field2_from_regex ist eine einfache ID (kein Underscore im kritischen Format).
            // Prüfe nun, ob $field3_from_regex (der ursprüngliche Aufwachgrund-Match) gültig ist.
            $valid_wake_reasons = ['TIMER', 'PIR', 'POWERON'];
            // Vergleiche case-insensitiv, falls die Gründe mal klein geschrieben wurden.
            $is_valid_reason = false;
            foreach ($valid_wake_reasons as $valid_reason) {
                if (strcasecmp($final_wake_reason, $valid_reason) == 0) {
                    $is_valid_reason = true;
                    $final_wake_reason = $valid_reason; // Normalisiere auf Großbuchstaben falls nötig
                    break;
                }
            }

            if (!$is_valid_reason) {
                // $final_wake_reason (ursprünglich $field3_from_regex) ist kein Standard-Aufwachgrund.
                // Dies könnte der Fall sein, wenn hier z.B. "vbatXXXXmV" steht.
                // Setze für Filterzwecke auf einen speziellen Wert.
                $final_wake_reason = 'UNKNOWN';
            }
        }

        return [
            'timestamp_short' => $timestamp_short,
            'esp_id' => $final_esp_id,
            'wake_reason' => $final_wake_reason
        ];
    }
    return null;
}

$all_files = glob($uploadDir . '*.jpg');
$esp_ids = [];
$wake_reasons = [];

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
        }
    }
    sort($esp_ids);
    sort($wake_reasons);
}

// Filterwerte aus GET-Parametern holen
$filter_esp_id = isset($_GET['filter_esp_id']) ? $_GET['filter_esp_id'] : '';
$filter_wake_reason = isset($_GET['filter_wake_reason']) ? $_GET['filter_wake_reason'] : '';

$files_to_display = [];
if ($all_files) {
    foreach ($all_files as $file) {
        $metadata = get_metadata_from_filename($file);
        if ($metadata) {
            $match_esp_id = empty($filter_esp_id) || $metadata['esp_id'] === $filter_esp_id;
            $match_wake_reason = empty($filter_wake_reason) || $metadata['wake_reason'] === $filter_wake_reason;

            if ($match_esp_id && $match_wake_reason) {
                $files_to_display[] = $file;
            }
        } else { // Fallback für alte Dateinamen oder nicht passende Muster
            if(empty($filter_esp_id) && empty($filter_wake_reason)) {
                 $files_to_display[] = $file; // Nur anzeigen, wenn keine Filter aktiv sind
            }
        }
    }
    // Sortiere gefilterte Dateien nach Änderungsdatum (neueste zuerst)
    if (!empty($files_to_display)) {
        array_multisort(array_map('filemtime', $files_to_display), SORT_DESC, $files_to_display);
    }
}


// Behandlung von GET-Requests (Bilder anzeigen)
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoSnapCam Bilder</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; }
        h1 { text-align: center; color: #333; }
        .gallery { display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; }
        .gallery img {
            border: 3px solid #ddd;
            border-radius: 5px;
            max-width: 320px; /* Maximale Breite der Vorschaubilder */
            height: auto;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s ease-in-out;
        }
        .gallery img:hover {
            transform: scale(1.05);
            border-color: #777;
        }
        .no-images { text-align: center; color: #777; font-size: 1.2em; }
        .image-container { text-align: center; margin-bottom: 15px; }
        .image-container p { font-size: 0.9em; color: #555; margin-top: 5px; }
        .image-container .timestamp { font-size: 0.8em; color: #888; }

        /* Modal Overlay Stile */
        .modal {
            display: none; /* Standardmäßig ausgeblendet */
            position: fixed; /* Bleibt an Ort und Stelle */
            z-index: 1000; /* Über allem anderen */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto; /* Scrollen, falls Bild zu groß */
            background-color: rgba(0,0,0,0.85); /* Schwarzer Hintergrund mit Transparenz */
            cursor: pointer; /* Klick zum Schließen */
        }
        .modal-content {
            margin: auto;
            display: block;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 90%;
            max-height: 90%;
        }
        .modal-caption {
            margin: auto;
            display: block;
            width: 80%;
            max-width: 700px;
            text-align: center;
            color: #ccc;
            padding: 10px 0;
            height: 50px; /* Feste Höhe für den Untertitel */
            position: absolute;
            bottom: 15px; /* Am unteren Rand positionieren */
            left: 50%;
            transform: translateX(-50%);
        }
        .close-modal {
            position: absolute;
            top: 20px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
        }
        .close-modal:hover,
        .close-modal:focus {
            color: #bbb;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div id="myModal" class="modal">
        <span class="close-modal">&times;</span>
        <img class="modal-content" id="imgModal">
        <div id="captionModal" class="modal-caption"></div>
    </div>
    <h1>EcoSnapCam - Gespeicherte Bilder</h1>

    <form method="GET" action="" style="text-align: center; margin-bottom: 20px;">
        <label for="filter_esp_id">Geräte-ID:</label>
        <select name="filter_esp_id" id="filter_esp_id" onchange="this.form.submit()">
            <option value="">Alle Geräte</option>
            <?php foreach ($esp_ids as $id): ?>
                <option value="<?php echo htmlspecialchars($id); ?>" <?php if ($id === $filter_esp_id) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($id); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="filter_wake_reason" style="margin-left: 15px;">Aufwachgrund:</label>
        <select name="filter_wake_reason" id="filter_wake_reason" onchange="this.form.submit()">
            <option value="">Alle Gründe</option>
            <?php foreach ($wake_reasons as $reason): ?>
                <option value="<?php echo htmlspecialchars($reason); ?>" <?php if ($reason === $filter_wake_reason) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($reason); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" style="margin-left: 10px;">Filtern</button></noscript>
    </form>

    <div class="gallery">
        <?php
        if (!empty($files_to_display)) {
            foreach ($files_to_display as $file) {
                $fullFileName = basename($file);
                $metadata = get_metadata_from_filename($fullFileName);
                $displayFileName = $fullFileName; // Fallback
                $formattedDate = '';

                if ($metadata) {
                    // Gekürzter Dateiname: ESP-ID + Aufwachgrund
                    $displayFileName = $metadata['esp_id'] . '_' . $metadata['wake_reason'];
                    // Formatieren des Datums für die Anzeige (aus dem Zeitstempel-Teil des Metadatensatzes)
                    if (preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $metadata['timestamp_short'], $dateMatches)) {
                         $formattedDate = "{$dateMatches[3]}.{$dateMatches[2]}.{$dateMatches[1]} {$dateMatches[4]}:{$dateMatches[5]}:{$dateMatches[6]}";
                    }
                } else {
                     // Fallback für alte Dateinamen oder nicht passende Muster
                    $fileModTime = filemtime($file);
                    $formattedDate = date('d.m.Y H:i:s', $fileModTime);
                }

                echo '<div class="image-container">';
                // Der 'alt'-Tag enthält nun den vollen Dateinamen für das Modal
                echo '<img src="' . htmlspecialchars($file) . '" alt="' . htmlspecialchars($fullFileName) . '" title="Klicken zum Vergrößern: ' . htmlspecialchars($fullFileName) . '" onclick="openModal(this)">';
                // Angezeigt wird der gekürzte Dateiname
                echo '<p title="' . htmlspecialchars($fullFileName) . '">' . htmlspecialchars($displayFileName) . '</p>';
                echo '<p class="timestamp">' . $formattedDate . '</p>';
                echo '</div>';
            }
        } else {
            echo '<p class="no-images">Keine Bilder für die aktuellen Filterkriterien vorhanden oder noch keine Bilder hochgeladen.</p>';
        }
        ?>
    </div>

    <script>
        // Modal Elemente holen
        var modal = document.getElementById("myModal");
        var modalImg = document.getElementById("imgModal");
        var captionText = document.getElementById("captionModal");
        var span = document.getElementsByClassName("close-modal")[0];

        // Funktion zum Öffnen des Modals
        function openModal(imgElement) {
            modal.style.display = "block";
            modalImg.src = imgElement.src;
            captionText.innerHTML = imgElement.alt; // Oder imgElement.title für den längeren Text
        }

        // Klick auf das Schließen-Symbol (x)
        span.onclick = function() {
            modal.style.display = "none";
        }

        // Klick irgendwo außerhalb des Bildes (auf den modalen Hintergrund) schließt es auch
        modal.onclick = function(event) {
            if (event.target == modal) { // Nur wenn direkt auf den Hintergrund geklickt wird, nicht auf das Bild selbst
                modal.style.display = "none";
            }
        }

        // Optional: Schließen mit Escape-Taste
        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape" && modal.style.display === "block") {
                modal.style.display = "none";
            }
        });
    </script>
</body>
</html>
