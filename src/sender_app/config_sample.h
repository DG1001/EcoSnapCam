#ifndef CONFIG_SAMPLE_H
#define CONFIG_SAMPLE_H

// ---------------- Kamera-Einstellungen ----------------
// Belichtungsmodus für die Kamera
// 0 = Automatisch (basierend auf Umgebungshelligkeit)
// 1 = Immer dunkel (Innenraum-Einstellungen)
// 2 = Immer hell (Tageslicht-Einstellungen)
// 3 = Immer sehr hell (Sonnenlicht-Einstellungen)
#define EXPOSURE_MODE 0

// ---------------- HTTP/HTTPS Upload (Standard) ----------------
// WiFi Zugangsdaten
const char* ssid = "DEIN_WLAN_SSID";          // Tragen Sie hier Ihren WLAN-Namen ein
const char* password = "DEIN_WLAN_PASSWORT";  // Tragen Sie hier Ihr WLAN-Passwort ein

// Server URL für HTTP Upload
const char* serverURL = "http://DEIN_SERVER.DE/DEIN_UPLOAD_PFAD/upload.php"; // Tragen Sie hier Ihre HTTP Server-URL ein

// ---------------- ESP-NOW Upload (Optional) ----------------
// Auf true setzen, um ESP-NOW anstelle von HTTP/HTTPS für den Bild-Upload zu verwenden.
// ACHTUNG: Erfordert einen ESP-NOW Empfänger auf der Gegenseite.
#define USE_ESP_NOW false // true oder false

#if USE_ESP_NOW
// MAC-Adresse des ESP-NOW Empfänger-Boards
// Ersetzen Sie dies mit der tatsächlichen MAC-Adresse Ihres Empfängers.
// Beispiel: {0x1A, 0x2B, 0x3C, 0x4D, 0x5E, 0x6F}
static uint8_t espNowReceiverMac[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};

// WLAN-Kanal für ESP-NOW (0 für automatisch/aktuellen Kanal, sonst 1-13)
// Sender und Empfänger müssen auf demselben Kanal sein für zuverlässige Kommunikation.
// Wenn der Empfänger auf einem festen Kanal lauscht, hier denselben Kanal eintragen.
#define ESP_NOW_CHANNEL 1 // Fest auf Kanal 1 setzen, passend zum Empfänger
#endif

#endif // CONFIG_SAMPLE_H
