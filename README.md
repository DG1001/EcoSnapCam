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

**Empfänger (optional, bei Verwendung von ESP-NOW):**
- ESP32 Entwicklungsboard (z.B. ESP32 Dev Kit C, Wemos D1 Mini ESP32)
- TFT Display (z.B. "Cheap Yellow Display" - ESP32-2432S028R oder ein anderes von TFT_eSPI unterstütztes Display)
- USB-Kabel für Stromversorgung und Programmierung des Empfängers

## Installation

1.  Repository klonen.
2.  **Sender Setup:**
    a.  Kopieren Sie `src/sender_app/config_sample.h` zu `src/sender_app/config.h`.
    b.  Passen Sie `src/sender_app/config.h` mit Ihren WLAN- und Server-Daten (für HTTP/S-Upload) oder ESP-NOW Einstellungen an.
    c.  Mit PlatformIO auf das ESP32-CAM Modul flashen (Umgebung `ecosnapcam_sender`):
        ```bash
        pio run -e ecosnapcam_sender -t upload
        ```
3.  **Empfänger Setup (optional, bei Verwendung von ESP-NOW):**
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

## Lizenz

MIT License - siehe [LICENSE](LICENSE) Datei für Details.
