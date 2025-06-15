// ────────────────────────────────────────────────────────────────
//  EcoSnapCam – vollständiger Sketch ohne goto (main.ino)
//  Board: AI‑Thinker ESP32‑CAM  |  Framework: Arduino (PlatformIO)
// ────────────────────────────────────────────────────────────────
//  Wake‑Up‑Quellen:
//     • 5‑Minuten‑Timer
//     • PIR‑Sensor an GPIO 13
//  Ablauf nach Wake‑Up:
//     1. Batteriespannung messen (GPIO14)
//     2. Foto (SVGA) aufnehmen
//     3. JPEG via HTTP/HTTPS hochladen (HTTP/1.0, fester Length)
//     4. Deep‑Sleep – geschätzte Laufzeit >200 Tage mit 2×AA
// ────────────────────────────────────────────────────────────────
//  SSID / PW / SERVER_URL stehen in  config.h
// ────────────────────────────────────────────────────────────────

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h> // Für ESP-NOW
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include "esp_camera.h"
#include "esp_sleep.h"
#include "config.h"

// Brown‑Out‑Detector deaktivieren
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"

// ───────── TLS‑Optionen ─────────
#define USE_INSECURE_TLS 1           // 1 = TLS‑Zertifikat ignorieren (nur internes Netz)
#if !USE_INSECURE_TLS
static const char root_CA[] PROGMEM = R"EOF(
-----BEGIN CERTIFICATE-----
... Root‑CA hier einfügen ...
-----END CERTIFICATE-----
)EOF";
#endif

// Deep‑Sleep‑Timer (5 min) in µs
constexpr uint64_t SLEEP_USEC = 5ULL * 60ULL * 1000000ULL;
// PIR‑Sensor (RTC‑fähiger Pin)
static constexpr gpio_num_t PIR_PIN  = GPIO_NUM_13;
// Batteriespannung (ADC2_CH6 an GPIO14)
static constexpr int        VBAT_PIN = 14;

#if USE_ESP_NOW
// ESP-NOW spezifische Definitionen
esp_now_peer_info_t peerInfo;
volatile bool espNowSendSuccess = false; // Wird vom Callback gesetzt

// Maximale Datenmenge pro ESP-NOW Chunk (250 Bytes max ESP-NOW Payload - Größe unseres Headers)
// Unser Header: image_id (4), total_size (4), chunk_index (2), total_chunks (2), data_len (1), vbat_mv_high (1), vbat_mv_low (1) = 15 Bytes
#define ESP_NOW_MAX_DATA_PER_CHUNK (250 - 15) 

typedef struct __attribute__((packed)) esp_now_image_chunk_t {
    uint32_t image_id;     // Eindeutige ID für das Bild (z.B. millis() beim Start des Sendevorgangs)
    uint32_t total_size;   // Gesamtgröße des Bildes in Bytes
    uint16_t chunk_index;  // Aktueller Chunk-Index (0-basiert)
    uint16_t total_chunks; // Gesamtzahl der Chunks für dieses Bild
    uint8_t  data_len;     // Länge der Bilddaten in diesem Chunk (kann beim letzten Chunk kleiner sein)
    uint8_t  vbat_mv_high; // Batteriespannung in mV (oberes Byte)
    uint8_t  vbat_mv_low;  // Batteriespannung in mV (unteres Byte)
    uint8_t  data[ESP_NOW_MAX_DATA_PER_CHUNK]; // Die eigentlichen Bilddaten des Chunks
} esp_now_image_chunk_t;

// Callback-Funktion, die nach dem Senden von ESP-NOW Daten aufgerufen wird
static void OnDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {
  // Serial.printf("[ESP-NOW] Sendestatus an %02X:%02X:%02X:%02X:%02X:%02X: %s\n",
  //               mac_addr[0], mac_addr[1], mac_addr[2], mac_addr[3], mac_addr[4], mac_addr[5],
  //               status == ESP_NOW_SEND_SUCCESS ? "Erfolg" : "Fehler");
  espNowSendSuccess = (status == ESP_NOW_SEND_SUCCESS);
}
#endif

// ───────── Kamera‑Pinout (AI‑Thinker ESP32‑CAM) ─────────
#define PWDN_GPIO_NUM 32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM 0
#define SIOD_GPIO_NUM 26
#define SIOC_GPIO_NUM 27
#define Y9_GPIO_NUM 35
#define Y8_GPIO_NUM 34
#define Y7_GPIO_NUM 39
#define Y6_GPIO_NUM 36
#define Y5_GPIO_NUM 21
#define Y4_GPIO_NUM 19
#define Y3_GPIO_NUM 18
#define Y2_GPIO_NUM 5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM 23
#define PCLK_GPIO_NUM 22

// ───────── Hilfsfunktionen ─────────
static void printWakeReason() {
  switch (esp_sleep_get_wakeup_cause()) {
    case ESP_SLEEP_WAKEUP_TIMER:  Serial.println(F("[Wake] Timer")); break;
    case ESP_SLEEP_WAKEUP_EXT0:   Serial.println(F("[Wake] PIR"));   break;
    default:                      Serial.println(F("[Wake] Power‑On"));
  }
}

static float readVBat() {
  // Annahme: Batterie direkt am ADC-Pin (ggf. über einen Strombegrenzungswiderstand).
  // Die gemessene Spannung am ADC-Pin entspricht der Batteriespannung.
  // Die Referenzspannung des ADC wird hier mit 3.3V angenommen.
  // Für höhere Genauigkeit könnte die ADC-Kalibrierung verwendet werden.
  analogSetPinAttenuation(VBAT_PIN, ADC_11db);  // Messbereich ca. 0–3.6 V am ADC-Pin
  uint16_t raw = analogRead(VBAT_PIN);          // Rohwert 0–4095
  float v_adc = raw * 3.3f / 4095.0f;           // Spannung am ADC-Pin (entspricht Batteriespannung)
  return v_adc;                                 // Tatsächliche Batteriespannung
}

static bool initCamera() {
  camera_config_t cfg{};
  cfg.ledc_channel = LEDC_CHANNEL_0;
  cfg.ledc_timer   = LEDC_TIMER_0;
  cfg.pin_d0 = Y2_GPIO_NUM; cfg.pin_d1 = Y3_GPIO_NUM; cfg.pin_d2 = Y4_GPIO_NUM;
  cfg.pin_d3 = Y5_GPIO_NUM; cfg.pin_d4 = Y6_GPIO_NUM; cfg.pin_d5 = Y7_GPIO_NUM;
  cfg.pin_d6 = Y8_GPIO_NUM; cfg.pin_d7 = Y9_GPIO_NUM; cfg.pin_xclk = XCLK_GPIO_NUM;
  cfg.pin_pclk = PCLK_GPIO_NUM; cfg.pin_vsync = VSYNC_GPIO_NUM; cfg.pin_href = HREF_GPIO_NUM;
  cfg.pin_sscb_sda = SIOD_GPIO_NUM; cfg.pin_sscb_scl = SIOC_GPIO_NUM;
  cfg.pin_pwdn = PWDN_GPIO_NUM; cfg.pin_reset = RESET_GPIO_NUM;
  cfg.xclk_freq_hz = 20000000;          // 20 MHz
  cfg.frame_size   = FRAMESIZE_QVGA;
  cfg.pixel_format = PIXFORMAT_JPEG;
  cfg.jpeg_quality = 10;                // 0–63 (niedriger = besser)
  cfg.fb_count     = 1;
  return esp_camera_init(&cfg) == ESP_OK;
}

static bool wifiConnect() {
  WiFi.begin(ssid, password);
  Serial.print(F("Wi‑Fi"));
  for (uint32_t t0 = millis(); WiFi.status()!=WL_CONNECTED && millis()-t0<15000; ) {
    delay(250); Serial.print('.');
  }
  Serial.println();
  return WiFi.status() == WL_CONNECTED;
}

#if USE_ESP_NOW
static bool initEspNow() {
  WiFi.mode(WIFI_STA); // ESP-NOW benötigt den STA-Modus
  // Optional: WiFi.disconnect(); // Um sicherzustellen, dass keine alte Verbindung besteht.
  // Der Kanal wird in peerInfo.channel gesetzt, ESP-NOW kümmert sich darum.
  // Es ist nicht notwendig, WiFi.begin() für den ESP-NOW Client-Modus aufzurufen, wenn keine AP-Verbindung benötigt wird.
  // Wenn ESP_NOW_CHANNEL 0 ist, wird der aktuelle STA-Kanal verwendet.
  // Wenn ein spezifischer Kanal gesetzt ist, muss der ESP32 ggf. darauf wechseln.
  // esp_wifi_set_channel(ESP_NOW_CHANNEL, WIFI_SECOND_CHAN_NONE); // Falls fester Kanal benötigt wird und nicht 0

  if (esp_now_init() != ESP_OK) {
    Serial.println(F("[ESP-NOW] Fehler bei der Initialisierung"));
    return false;
  }
  esp_now_register_send_cb(OnDataSent);

  memcpy(peerInfo.peer_addr, espNowReceiverMac, 6);
  peerInfo.channel = ESP_NOW_CHANNEL; 
  peerInfo.encrypt = false; // Keine Verschlüsselung für dieses Beispiel
  // peerInfo.ifidx = WIFI_IF_STA; // Standardmäßig WIFI_IF_STA

  if (esp_now_add_peer(&peerInfo) != ESP_OK) {
    Serial.println(F("[ESP-NOW] Fehler beim Hinzufügen des Peers"));
    esp_now_deinit(); // Aufräumen
    return false;
  }
  Serial.println(F("[ESP-NOW] Initialisierung erfolgreich, Peer hinzugefügt."));
  return true;
}


// ────────────────────────────────────────────────────────────────
//  Korrigierte sendJpegEspNow Funktion
//  Behebt ESP-NOW Buffer-Overflow Probleme
// ────────────────────────────────────────────────────────────────
static bool sendJpegEspNow(uint8_t* buf, size_t len, uint32_t imageId, uint16_t v_bat_mv) {
  if (len == 0) {
    Serial.println(F("[ESP-NOW] Keine Daten zum Senden."));
    return false;
  }

  // Bildgröße für ESP-NOW begrenzen (wegen 250-Byte Chunks)
  if (len > 50000) {  // 50KB Limit für ESP-NOW
    Serial.printf("[ESP-NOW] Bild zu groß für ESP-NOW: %u Bytes. Maximum: 50KB\n", len);
    return false;
  }

  esp_now_image_chunk_t chunk_message;
  chunk_message.image_id = imageId;
  chunk_message.total_size = len;
  chunk_message.vbat_mv_high = (v_bat_mv >> 8) & 0xFF;
  chunk_message.vbat_mv_low = v_bat_mv & 0xFF;

  uint16_t totalChunks = (len + ESP_NOW_MAX_DATA_PER_CHUNK - 1) / ESP_NOW_MAX_DATA_PER_CHUNK;
  chunk_message.total_chunks = totalChunks;

  Serial.printf("[ESP-NOW] Sende Bild (ID: %u, Größe: %u Bytes, Chunks: %u)\n", 
                imageId, len, totalChunks);

  for (uint16_t i = 0; i < totalChunks; ++i) {
    chunk_message.chunk_index = i;
    size_t offset = i * ESP_NOW_MAX_DATA_PER_CHUNK;
    size_t currentChunkDataSize = (i == totalChunks - 1) ? 
                                  (len - offset) : ESP_NOW_MAX_DATA_PER_CHUNK;
    chunk_message.data_len = currentChunkDataSize;
    memcpy(chunk_message.data, buf + offset, currentChunkDataSize);

    espNowSendSuccess = false;

    size_t messageSize = offsetof(esp_now_image_chunk_t, data) + currentChunkDataSize;
    esp_err_t result = esp_now_send(espNowReceiverMac, (uint8_t*)&chunk_message, messageSize);

    if (result == ESP_OK) {
      // Warte auf Callback mit Timeout
      unsigned long startTime = millis();
      while (!espNowSendSuccess && (millis() - startTime < 3000)) {
        delay(10);
      }

      if (!espNowSendSuccess) {
        Serial.printf("[ESP-NOW] Timeout bei Chunk %u/%u\n", i + 1, totalChunks);
        return false; 
      }

      // KRITISCH: Pause zwischen Chunks um Buffer-Overflow zu vermeiden
      if (i < totalChunks - 1) {  // Nicht nach dem letzten Chunk
        delay(20);  // 20ms Pause - verhindert ESP_ERR_ESPNOW_NO_MEM
      }

      // Serial.printf("[ESP-NOW] Chunk %u/%u gesendet\n", i + 1, totalChunks);
    } else {
      Serial.printf("[ESP-NOW] Fehler bei Chunk %u: %s\n", 
                    i + 1, esp_err_to_name(result));
      return false; 
    }
  }

  Serial.println(F("[ESP-NOW] Übertragung komplett"));
  return true;
}


#endif

// JPEG‑Upload – HTTP oder HTTPS (HTTP/1.0, fester Content‑Length)
static bool sendJpeg(uint8_t* buf, size_t len, const char* url) {
  HTTPClient http;
  bool ok;
  if (String(url).startsWith("https://")) {
      static WiFiClientSecure tls;
    #if USE_INSECURE_TLS
      tls.setInsecure();
    #else
      tls.setCACert(root_CA);
    #endif
      ok = http.begin(tls, url);
  } else {
      ok = http.begin(url);
  }
  if (!ok) { Serial.println(F("http.begin() fehlgeschlagen")); return false; }

  http.useHTTP10(true);
  http.addHeader("Content-Type", "image/jpeg");
  http.setTimeout(10000); // Timeout auf 10 Sekunden setzen
  Serial.printf("Sende %u Bytes an: %s\n", len, url); // Zusätzliche Debug-Ausgabe
  Serial.printf("[HTTP] Freier Heap vor POST: %u Bytes\n", ESP.getFreeHeap());
  int rc = http.POST(buf, len);
  Serial.printf("[HTTP] Freier Heap nach POST: %u Bytes\n", ESP.getFreeHeap());
  Serial.printf("HTTP rc=%d (%s)\n", rc, http.errorToString(rc).c_str());
  if (rc > 0) Serial.println(http.getString()); // Server-Antwort ausgeben (nützlich für Debugging)
  http.end();
  // Nur HTTP-Statuscodes 200-299 als Erfolg werten
  return rc >= 200 && rc < 300;
}

static void goDeepSleep() {
  Serial.println(F("Deep‑Sleep"));
  delay(50);
  esp_sleep_enable_timer_wakeup(SLEEP_USEC);
  esp_sleep_enable_ext0_wakeup(PIR_PIN, 1);
  esp_deep_sleep_start();
}

// ───────── setup() ─────────
void setup() {
  Serial.begin(115200);
  delay(300);
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0); // Brown‑Out OFF

  printWakeReason();
  pinMode(PIR_PIN, INPUT_PULLDOWN);

  float vbat = readVBat();
  uint16_t v_mV = static_cast<uint16_t>(vbat * 1000 + 0.5f);
  Serial.printf("VBAT %.2f V\n", vbat);

  if (!initCamera()) {
    Serial.println(F("Cam init fail – Sleep"));
    goDeepSleep();
  }

  bool uploadSuccess = false;
  uint32_t imageIdForEspNow = millis(); // Eindeutige ID für dieses Bild bei ESP-NOW

#if USE_ESP_NOW
  Serial.println(F("[Main] ESP-NOW Upload ausgewählt."));
  if (!initEspNow()) {
    Serial.println(F("[Main] ESP-NOW Init fail – Sleep"));
    // WiFi.mode(WIFI_OFF) wird unten generell aufgerufen
  } else {
    if (camera_fb_t* fb = esp_camera_fb_get()) {
      uploadSuccess = sendJpegEspNow(fb->buf, fb->len, imageIdForEspNow, v_mV);
      esp_camera_fb_return(fb);
    } else {
      Serial.println(F("Foto capture fehlgeschlagen"));
    }
    esp_now_deinit(); // ESP-NOW nach Gebrauch deaktivieren
    Serial.println(F("[ESP-NOW] Deinitialisiert."));
  }
#else
  Serial.println(F("[Main] HTTP/S Upload ausgewählt."));
  if (!wifiConnect()) {
    Serial.println(F("WiFi fail – Sleep"));
    // WiFi.mode(WIFI_OFF) wird unten generell aufgerufen
  } else {
    if (camera_fb_t* fb = esp_camera_fb_get()) {
        char url_buffer[256]; // Puffer für die URL, Größe ggf. anpassen
        snprintf(url_buffer, sizeof(url_buffer), "%s?vbat=%u", serverURL, v_mV);
        uploadSuccess = sendJpeg(fb->buf, fb->len, url_buffer);
        esp_camera_fb_return(fb);
    } else {
        Serial.println(F("Foto capture fehlgeschlagen"));
    }
    WiFi.disconnect(true); // WLAN trennen nach HTTP Upload
    Serial.println(F("WiFi getrennt."));
  }
#endif

  if (uploadSuccess) {
    Serial.println(F("Bild-Upload erfolgreich abgeschlossen."));
  } else {
    Serial.println(F("Bild-Upload fehlgeschlagen."));
  }
  
  WiFi.mode(WIFI_OFF); // WLAN Modul komplett ausschalten vor Deep Sleep
  Serial.println(F("WiFi Modul AUS."));

  goDeepSleep();
}

void loop() {
  /* nie erreicht */
}
