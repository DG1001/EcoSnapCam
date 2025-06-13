# EcoSnapCam

![EcoSnapCam Logo](ecosnapcam-logo.png)

ESP32-CAM basierte Wildkamera mit Deep Sleep für energieeffiziente Betrieb.

## Funktionen

- Nimmt regelmäßig Fotos auf (standardmäßig alle 5 Minuten)
- Sendet Bilder per HTTP POST an einen Server
- Energieeffizient durch Deep Sleep zwischen Aufnahmen
- Einfache Konfiguration über `config.h` Datei
- Unterstützt verschiedene Kameraeinstellungen

## Hardware Voraussetzungen

- ESP32-CAM Modul (AI-Thinker)
- FTDI Programmer oder ähnliches für USB-Serial
- Stromversorgung (z.B. Batterie oder Solar)

## Installation

1. Repository klonen
2. `config.h` mit eigenen WLAN und Server Daten anpassen
3. Mit PlatformIO auf ESP32-CAM flashen:

```bash
pio run -t upload
```

## Konfiguration

Die wichtigsten Einstellungen in `config.h`:

```cpp
const char* ssid = "WLAN_NAME";
const char* password = "WLAN_PASSWORT";
const char* serverURL = "https://dein.server.de/upload"; // HTTPS mit deaktivierter Zertifikatsprüfung
```

## Lizenz

MIT License - siehe [LICENSE](LICENSE) Datei für Details.
