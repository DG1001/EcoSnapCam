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

    // Dateiname generieren (Zeitstempel + optional vbat)
    $timestamp = time();
    $vbat = isset($_GET['vbat']) ? (int)$_GET['vbat'] : null;
    $filename = $timestamp;
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
        .image-container { text-align: center; }
        .image-container p { font-size: 0.9em; color: #555; margin-top: 5px; }
    </style>
</head>
<body>
    <h1>EcoSnapCam - Gespeicherte Bilder</h1>
    <div class="gallery">
        <?php
        $files = glob($uploadDir . '*.jpg');
        if ($files && count($files) > 0) {
            // Sortiere Dateien nach Änderungsdatum (neueste zuerst)
            array_multisort(array_map('filemtime', $files), SORT_DESC, $files);
            foreach ($files as $file) {
                $fileName = basename($file);
                echo '<div class="image-container">';
                echo '<a href="' . htmlspecialchars($file) . '" target="_blank">';
                echo '<img src="' . htmlspecialchars($file) . '" alt="' . htmlspecialchars($fileName) . '" title="' . htmlspecialchars($fileName) . '">';
                echo '</a>';
                echo '<p>' . htmlspecialchars($fileName) . '</p>';
                echo '</div>';
            }
        } else {
            echo '<p class="no-images">Noch keine Bilder vorhanden.</p>';
        }
        ?>
    </div>
</body>
</html>
