# EcoSnapCam

![EcoSnapCam Logo](ecosnapcam-logo.png)

ESP32-CAM basierte Wildkamera mit Deep Sleep für energieeffizienten Betrieb.
Unterstützt Bildübertragung via HTTP/HTTPS oder ESP-NOW an einen dedizierten ESP32-Empfänger mit Display (z.B. Cheap Yellow Display - CYD).

## Funktionen

- Nimmt regelmäßig Fotos auf (standardmäßig alle 5 Minuten)
- Sendet Bilder per HTTP POST an einen Server oder per ESP-NOW an einen Empfänger
- Energieeffizient durch Deep Sleep zwischen Aufnahmen
- Einfache Konfiguration des Senders über `config.h` Datei
- Unterstützt verschiedene Kameraeinstellungen
- Anzeige des empfangenen Bildes auf einem am Empfänger angeschlossenen Display (z.B. TFT_eSPI-kompatibles Display wie das CYD)

## Hardware Voraussetzungen

**Sender:**
- ESP32-CAM Modul (AI-Thinker)
- FTDI Programmer oder ähnliches für USB-Serial
- Stromversorgung (z.B. Batterie oder Solar)
- Optional: PIR-Sensor für bewegungsaktivierte Aufnahmen (Standard: GPIO13)
- Optional: Anschluss für Batteriespannungsmessung (Standard: GPIO14)

**Empfänger (optional, bei Verwendung von ESP-NOW):**
- ESP32 Entwicklungsboard (z.B. ESP32 Dev Kit C, Wemos D1 Mini ESP32)
- TFT Display (z.B. "Cheap Yellow Display" - ESP32-2432S028R oder ein anderes von TFT_eSPI unterstütztes Display)
- USB-Kabel für Stromversorgung und Programmierung des Empfängers

## Installation

1.  Repository klonen.
2.  **Sender Setup:**
    a.  Kopieren Sie `src/sender_app/config_sample.h` zu `src/sender_app/config.h`.
    b.  Passen Sie `src/sender_app/config.h` mit Ihren WLAN-Daten an. Für den HTTP-Upload passen Sie die `serverURL` an die Adresse Ihres PHP-Servers an (siehe Punkt 3).
    c.  Mit PlatformIO auf das ESP32-CAM Modul flashen (Umgebung `ecosnapcam_sender`):
        ```bash
        pio run -e ecosnapcam_sender -t upload
        ```
3.  **Server Setup (für HTTP-Upload):**
    a.  Das Projekt enthält ein PHP-Skript (`upload.php`) zum Empfangen und Anzeigen der Bilder.
    b.  Um dieses Skript einfach bereitzustellen, können Sie einen Docker-Container verwenden. Kopieren Sie `upload.php` in ein Verzeichnis (z.B. `my_php_server`) und führen Sie folgenden Befehl im übergeordneten Verzeichnis aus (stellen Sie sicher, dass `upload.php` sich in `my_php_server/upload.php` befindet):
        ```bash
        docker run -d -p 8080:80 --name ecosnapcam-server -v ./my_php_server:/var/www/html php:8-apache
        ```
        Die Bildergalerie ist dann unter `http://localhost:8080/upload.php` erreichbar. Der Upload-Endpunkt für den ESP32-Sender ist `http://<IP_DES_DOCKER_HOSTS>:8080/upload.php` (ersetzen Sie `<IP_DES_DOCKER_HOSTS>` mit der IP-Adresse des Rechners, auf dem Docker läuft, wenn Sie von einem anderen Gerät im Netzwerk darauf zugreifen).
    c.  Passen Sie die `serverURL` in `src/sender_app/config.h` entsprechend an.
4.  **Empfänger Setup (optional, bei Verwendung von ESP-NOW):**
    a.  Die Konfiguration des Empfängers (Display-Pins, ESP-NOW Kanal) erfolgt direkt in `platformio.ini` (für das Display) und `src/receiver_app/main.cpp` (für den ESP-NOW Kanal).
    b.  Stellen Sie sicher, dass der `ESP_NOW_RECEIVER_CHANNEL` in `src/receiver_app/main.cpp` mit dem `ESP_NOW_CHANNEL` in der `config.h` des Senders übereinstimmt.
    c.  Mit PlatformIO auf das ESP32-Empfängerboard flashen (Umgebung `espnow_receiver`):
        ```bash
        pio run -e espnow_receiver -t upload
        ```

## Konfiguration

Die Konfiguration des **Senders** erfolgt in `src/sender_app/config.h` (eine Kopie von `src/sender_app/config_sample.h`).

**Für HTTP/HTTPS Upload:**
Stellen Sie sicher, dass `USE_ESP_NOW` auf `false` gesetzt ist.
```cpp
// WiFi Zugangsdaten
const char* ssid = "DEIN_WLAN_SSID";
const char* password = "DEIN_WLAN_PASSWORT";

// Server URL für HTTP/HTTPS Upload
const char* serverURL = "https://DEIN_SERVER.DE/DEIN_UPLOAD_PFAD/upload.php";

// ESP-NOW deaktivieren
#define USE_ESP_NOW false
```

**Für ESP-NOW Upload:**
Stellen Sie sicher, dass `USE_ESP_NOW` auf `true` gesetzt ist.
```cpp
// ESP-NOW aktivieren
#define USE_ESP_NOW true

// Pin-Definitionen für optionale Hardware (relevant für den Sender)
// #define PIR_PIN GPIO_NUM_13 // Standardmäßig in main.cpp definiert, hier zur Info
// #define VBAT_PIN 14         // Standardmäßig in main.cpp definiert, hier zur Info


#if USE_ESP_NOW
// MAC-Adresse des ESP-NOW Empfänger-Boards
// {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF} für Broadcast an alle Geräte auf dem Kanal.
// Für eine direkte Verbindung die spezifische MAC-Adresse des Empfängers eintragen.
static uint8_t espNowReceiverMac[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};

// WLAN-Kanal für ESP-NOW (muss mit dem Empfänger übereinstimmen, z.B. 1)
#define ESP_NOW_CHANNEL 1
#endif
```
Die Konfiguration des **Empfängers** (Display-Typ, Pins) erfolgt über die `build_flags` in der `platformio.ini` für die `espnow_receiver`-Umgebung. Der ESP-NOW Empfangskanal ist in `src/receiver_app/main.cpp` definiert (`ESP_NOW_RECEIVER_CHANNEL`).

### Hinweise zur Sender-Hardware

-   **PIR-Sensor:** Der Sender-Code ist so konfiguriert, dass er auf ein Signal vom PIR-Sensor an `GPIO13` reagiert, um zusätzlich zum Timer-basierten Aufwachen eine Aufnahme auszulösen. Der Pin ist im Code (`src/sender_app/main.cpp`) als `PIR_PIN` definiert.
-   **Batteriespannungsmessung:** Der Sender misst die Batteriespannung an `GPIO14` (im Code als `VBAT_PIN` definiert). Diese Information wird bei HTTP/S-Uploads als GET-Parameter (`?vbat=XXXX`) an die Server-URL angehängt und bei ESP-NOW-Uploads als Teil der Nachricht an den Empfänger gesendet und dort angezeigt. Stellen Sie sicher, dass Ihre Batterieschaltung für die Messung an diesem Pin geeignet ist (ggf. Spannungsteiler verwenden, falls die Batteriespannung die maximale Eingangsspannung des ADC übersteigt).

## Lizenz

MIT License - siehe [LICENSE](LICENSE) Datei für Details.
