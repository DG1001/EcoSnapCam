// ────────────────────────────────────────────────────────────────
//  EcoSnapCam – optimiert für minimalen Stromverbrauch
//  Board: AI‑Thinker ESP32‑CAM  |  Framework: Arduino (PlatformIO)
// ────────────────────────────────────────────────────────────────

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include <HTTPClient.h>
#include "esp_camera.h"
#include "esp_sleep.h"
#include "esp_wifi.h"        // Für WiFi Power Management
#include "esp_bt.h"          // Für Bluetooth deaktivieren
#include "driver/adc.h"      // Für ADC Power Management
#include "config.h"

// Brown‑Out‑Detector und RTC deaktivieren
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"
#include "soc/timer_group_struct.h"
#include "soc/timer_group_reg.h"

// Deep‑Sleep‑Timer (15 min) in µs
constexpr uint64_t SLEEP_USEC = 15ULL * 60ULL * 1000000ULL;
// PIR‑Sensor (RTC‑fähiger Pin)
static constexpr gpio_num_t PIR_PIN  = GPIO_NUM_13;
// Batteriespannung (ADC2_CH6 an GPIO14)
static constexpr int        VBAT_PIN = 14;

// Globale Flags für saubere Deinitialisierung
static bool camera_initialized = false;
static bool bt_initialized = false;

#if USE_ESP_NOW
// ESP-NOW spezifische Definitionen (unverändert)
esp_now_peer_info_t peerInfo;
volatile bool espNowSendSuccess = false;

#define ESP_NOW_MAX_DATA_PER_CHUNK (250 - 15) 

typedef struct __attribute__((packed)) esp_now_image_chunk_t {
    uint32_t image_id;
    uint32_t total_size;
    uint16_t chunk_index;
    uint16_t total_chunks;
    uint8_t  data_len;
    uint8_t  vbat_mv_high;
    uint8_t  vbat_mv_low;
    uint8_t  data[ESP_NOW_MAX_DATA_PER_CHUNK];
} esp_now_image_chunk_t;

static void OnDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {
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

// ───────── Power Management Funktionen ─────────
static void disablePeripherals() {
  Serial.println(F("[Power] Deaktiviere Peripherie..."));
  Serial.flush(); // Sicherstellen dass Output gesendet wird
  
  // Bluetooth sicher deaktivieren
  if (bt_initialized) {
    esp_err_t bt_err = esp_bt_controller_deinit();
    if (bt_err == ESP_OK) {
      esp_bt_mem_release(ESP_BT_MODE_BTDM);
      bt_initialized = false;
      Serial.println(F("[Power] Bluetooth deaktiviert"));
      Serial.flush();
    } else {
      Serial.printf("[Power] BT Deinit Fehler: %s\n", esp_err_to_name(bt_err));
      Serial.flush();
    }
  }
  
  Serial.println(F("[Power] Peripherie deaktiviert"));
  Serial.flush();
}

static void enableLowPowerMode() {
  // CPU Frequenz reduzieren für Upload-Phase
  setCpuFrequencyMhz(80);  // Von 240MHz auf 80MHz
  
  // WiFi Power Save aktivieren (falls WiFi verwendet wird)
  esp_wifi_set_ps(WIFI_PS_MAX_MODEM);
}

// ───────── Hilfsfunktionen ─────────
static void printWakeReason() {
  switch (esp_sleep_get_wakeup_cause()) {
    case ESP_SLEEP_WAKEUP_TIMER:  Serial.println(F("[Wake] Timer")); break;
    case ESP_SLEEP_WAKEUP_EXT0:   Serial.println(F("[Wake] PIR"));   break;
    default:                      Serial.println(F("[Wake] Power‑On"));
  }
}

static float readVBat() {
  // ADC-Dämpfung auf 11dB setzen (für 0-3.3V Bereich)
  analogSetPinAttenuation(VBAT_PIN, ADC_11db);
  analogReadResolution(12); // 12-Bit Auflösung (0-4095)
  
  // Mehrere Messungen für bessere Genauigkeit
  const int samples = 5;
  uint32_t sum = 0;
  
  for (int i = 0; i < samples; i++) {
    sum += analogRead(VBAT_PIN);
    delay(5); // Kurze Pause zwischen Messungen
  }
  
  uint16_t raw = sum / samples;
  float v_adc = raw * 3.3f / 4095.0f * 2.0f; // 2.0f für Spannungsteiler 100kohm/100kohm
  
  // ADC nicht sofort deaktivieren - wird in disablePeripherals() gemacht
  
  Serial.printf("[Power] ADC Rohwert: %u, Spannung: %.3fV\n", raw, v_adc);
  
  return v_adc;
}

static bool initCamera() {
  Serial.println(F("[Cam] Initialisierung..."));
  
  camera_config_t cfg{};
  cfg.ledc_channel = LEDC_CHANNEL_0;
  cfg.ledc_timer   = LEDC_TIMER_0;
  cfg.pin_d0 = Y2_GPIO_NUM; cfg.pin_d1 = Y3_GPIO_NUM; cfg.pin_d2 = Y4_GPIO_NUM;
  cfg.pin_d3 = Y5_GPIO_NUM; cfg.pin_d4 = Y6_GPIO_NUM; cfg.pin_d5 = Y7_GPIO_NUM;
  cfg.pin_d6 = Y8_GPIO_NUM; cfg.pin_d7 = Y9_GPIO_NUM; cfg.pin_xclk = XCLK_GPIO_NUM;
  cfg.pin_pclk = PCLK_GPIO_NUM; cfg.pin_vsync = VSYNC_GPIO_NUM; cfg.pin_href = HREF_GPIO_NUM;
  cfg.pin_sscb_sda = SIOD_GPIO_NUM; cfg.pin_sscb_scl = SIOC_GPIO_NUM;
  cfg.pin_pwdn = PWDN_GPIO_NUM; cfg.pin_reset = RESET_GPIO_NUM;
  
  cfg.xclk_freq_hz = 10000000;          // Reduziert von 20MHz auf 10MHz (spart Strom)
  cfg.frame_size   = FRAMESIZE_SVGA;
  cfg.pixel_format = PIXFORMAT_JPEG;
  cfg.jpeg_quality = 12;                // Leicht erhöht für bessere Kompression
  cfg.fb_count     = 1;
  cfg.fb_location  = CAMERA_FB_IN_PSRAM; // PSRAM nutzen falls verfügbar
  cfg.grab_mode    = CAMERA_GRAB_LATEST; // Neuestes Frame nehmen

  esp_err_t err = esp_camera_init(&cfg);
  if (err != ESP_OK) {
    Serial.printf("[Cam] Init Fehler: %s\n", esp_err_to_name(err));
    return false;
  }
  
  camera_initialized = true;
  Serial.println(F("[Cam] Initialisierung erfolgreich"));
  
  // Standard Kameraeinstellungen (Automatik)
  sensor_t *s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_exposure_ctrl(s, 1); // Auto-Exposure EIN
    s->set_aec2(s, 1);          // Automatic Exposure Control 2 EIN
    s->set_ae_level(s, 0);      // Neutrale Belichtungskorrektur
    s->set_gain_ctrl(s, 1);     // Auto Gain Control EIN
    s->set_agc_gain(s, 0);      // AGC Gain (0-30), wird automatisch angepasst
    s->set_whitebal(s, 1);      // Auto White Balance EIN
    s->set_awb_gain(s, 1);      // AWB Gain EIN
    s->set_brightness(s, 0);    // Neutrale Helligkeit
    s->set_contrast(s, 0);      // Neutraler Kontrast
    s->set_saturation(s, 0);    // Neutrale Sättigung
    s->set_wb_mode(s, 0);       // Weißabgleich: Auto
    s->set_special_effect(s, 0);// Kein Spezialeffekt
    s->set_hmirror(s, 0);       // Horizontal mirror AUS
    s->set_vflip(s, 0);         // Vertical flip AUS
    s->set_lenc(s, 1);          // Lens correction EIN
    s->set_bpc(s, 1);           // Black pixel cancel EIN
    s->set_wpc(s, 1);           // White pixel cancel EIN
    s->set_raw_gma(s, 1);       // Gamma correction EIN
    Serial.println(F("[Cam] Standard-Kameraeinstellungen (Automatik) gesetzt."));
  }
  
  return true;
}

static bool wifiConnect() {
  // WiFi Power Management vor Connect setzen
  esp_wifi_set_ps(WIFI_PS_MAX_MODEM);
  
  WiFi.begin(ssid, password);
  Serial.print(F("Wi‑Fi"));
  for (uint32_t t0 = millis(); WiFi.status()!=WL_CONNECTED && millis()-t0<10000; ) {
    delay(250); Serial.print('.');
  }
  Serial.println();
  return WiFi.status() == WL_CONNECTED;
}

#if USE_ESP_NOW
static bool initEspNow() {
  WiFi.mode(WIFI_STA);
  esp_wifi_set_ps(WIFI_PS_MAX_MODEM); // Power Save auch für ESP-NOW

  if (esp_now_init() != ESP_OK) {
    Serial.println(F("[ESP-NOW] Fehler bei der Initialisierung"));
    return false;
  }
  esp_now_register_send_cb(OnDataSent);

  memcpy(peerInfo.peer_addr, espNowReceiverMac, 6);
  peerInfo.channel = ESP_NOW_CHANNEL; 
  peerInfo.encrypt = false;

  if (esp_now_add_peer(&peerInfo) != ESP_OK) {
    Serial.println(F("[ESP-NOW] Fehler beim Hinzufügen des Peers"));
    esp_now_deinit();
    return false;
  }
  Serial.println(F("[ESP-NOW] Initialisierung erfolgreich, Peer hinzugefügt."));
  return true;
}

static bool sendJpegEspNow(uint8_t* buf, size_t len, uint32_t imageId, uint16_t v_bat_mv) {
  if (len == 0) {
    Serial.println(F("[ESP-NOW] Keine Daten zum Senden."));
    return false;
  }

  if (len > 50000) {
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
      unsigned long startTime = millis();
      while (!espNowSendSuccess && (millis() - startTime < 3000)) {
        delay(10);
      }

      if (!espNowSendSuccess) {
        Serial.printf("[ESP-NOW] Timeout bei Chunk %u/%u\n", i + 1, totalChunks);
        return false; 
      }

      if (i < totalChunks - 1) {
        delay(20);
      }
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

static bool sendJpeg(uint8_t* buf, size_t len, const char* url) {
  HTTPClient http;
  bool ok = http.begin(url);
  if (!ok) { 
    Serial.println(F("http.begin() fehlgeschlagen")); 
    return false; 
  }

  http.useHTTP10(true);
  http.addHeader("Content-Type", "image/jpeg");
  http.setTimeout(8000); // Timeout reduziert
  
  Serial.printf("Sende %u Bytes an: %s\n", len, url);
  int rc = http.POST(buf, len);
  Serial.printf("HTTP rc=%d (%s)\n", rc, http.errorToString(rc).c_str());
  
  if (rc > 0) Serial.println(http.getString());
  http.end();
  
  return rc >= 200 && rc < 300;
}

// Flash-LED Pin
#define FLASH_LED_PIN 4

static void goDeepSleep() {
  Serial.println(F("Deep‑Sleep Vorbereitung..."));
  Serial.flush();
  
  // Kamera sicher deinitialisieren
  if (camera_initialized) {
    esp_err_t err = esp_camera_deinit();
    if (err == ESP_OK) {
      camera_initialized = false;
      Serial.println(F("[Power] Kamera deinitialisiert"));
      Serial.flush();
    } else {
      Serial.printf("[Power] Kamera Deinit Fehler: %s\n", esp_err_to_name(err));
      Serial.flush();
    }
  }
  
  // Alle nicht benötigten Peripherie ausschalten
  disablePeripherals();
  
  // Kamera PWDN Pin auf HIGH setzen (Power Down)
  pinMode(PWDN_GPIO_NUM, OUTPUT);
  digitalWrite(PWDN_GPIO_NUM, HIGH);
  Serial.println(F("[Power] Kamera PWDN auf HIGH gesetzt"));
  
  // Flash-LED Pin auf LOW setzen (aus)
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW);
  Serial.println(F("[Power] Flash-LED auf LOW gesetzt"));
  
  // Wakeup-Quellen konfigurieren
  esp_sleep_enable_timer_wakeup(SLEEP_USEC);
  esp_sleep_enable_ext0_wakeup(PIR_PIN, 1);
  
  Serial.println(F("Deep‑Sleep starten..."));
  Serial.flush();
  delay(100); // Etwas mehr Zeit für Serial Output
  
  esp_deep_sleep_start();
}

// ───────── setup() ─────────
void setup() {
  Serial.begin(115200);
  delay(200); // Reduziert von 300ms
  
  // Brown‑Out‑Detector deaktivieren
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);
  
  // Initialisierungsflags zurücksetzen
  camera_initialized = false;
  bt_initialized = false;
  
  // Bluetooth Status prüfen und Flag setzen
  if (esp_bt_controller_get_status() != ESP_BT_CONTROLLER_STATUS_IDLE) {
    bt_initialized = true;
  }
  
  // Sofort Power Management aktivieren; scheint instabil zu sein, deswegen aktuell deaktiviert
  //enableLowPowerMode();

  printWakeReason();
  pinMode(PIR_PIN, INPUT_PULLDOWN);

  // Batteriespannung messen
  float vbat = readVBat();
  uint16_t v_mV = static_cast<uint16_t>(vbat * 1000 + 0.5f);
  Serial.printf("VBAT %.2f V\n", vbat);
  

  if (!initCamera()) {
    Serial.println(F("Cam init fail – Sleep"));
    goDeepSleep();
  }

  bool uploadSuccess = false;
  uint32_t imageIdForEspNow = millis();

  // Kamera ist bereits im Automatikmodus initialisiert.
  // Die Dummy-Aufnahmen im HTTP-Upload-Pfad helfen dem Sensor, sich einzustellen.
  // Für ESP-NOW könnten ähnliche Dummy-Aufnahmen sinnvoll sein, falls Belichtungsprobleme auftreten.
  // Aktuell wird für ESP-NOW direkt das erste Bild nach der Initialisierung genommen.

#if USE_ESP_NOW
  Serial.println(F("[Main] ESP-NOW Upload ausgewählt."));
  if (!initEspNow()) {
    Serial.println(F("[Main] ESP-NOW Init fail – Sleep"));
  } else {
    if (camera_fb_t* fb = esp_camera_fb_get()) {
      uploadSuccess = sendJpegEspNow(fb->buf, fb->len, imageIdForEspNow, v_mV);
      esp_camera_fb_return(fb);
    } else {
      Serial.println(F("Foto capture fehlgeschlagen"));
    }
    esp_now_deinit();
    Serial.println(F("[ESP-NOW] Deinitialisiert."));
  }
#else
  Serial.println(F("[Main] HTTP Upload ausgewählt."));
  camera_fb_t* fb = esp_camera_fb_get(); // Erste Aufnahme vor WiFi-Verbindung

  if (!fb) {
    Serial.println(F("Initial camera capture fehlgeschlagen"));
    // uploadSuccess bleibt false
  } else {
    // Dummy-Aufnahmen zur Sensoranpassung
    Serial.println(F("[Cam] Mache Dummy-Aufnahmen zur Belichtungsanpassung..."));
    for (int i = 0; i < 3; i++) {
      esp_camera_fb_return(fb); // Vorherigen Framebuffer freigeben
      fb = esp_camera_fb_get();
      if (!fb) {
        Serial.printf("[Cam] Dummy capture %d fehlgeschlagen\n", i + 1);
        break; 
      }
      delay(50); // Kurze Pause zwischen Dummy-Aufnahmen
    }

    if (!fb) {
      Serial.println(F("[Cam] Finale Aufnahme nach Dummies fehlgeschlagen"));
      // uploadSuccess bleibt false, fb ist bereits NULL oder wurde freigegeben
    } else {
      Serial.println(F("[Cam] Finale Aufnahme erfolgreich erstellt."));
      if (!wifiConnect()) {
        Serial.println(F("WiFi fail – Sleep"));
        esp_camera_fb_return(fb); // Framebuffer freigeben, da nicht gesendet
        // uploadSuccess bleibt false
      } else {
        char url_buffer[256];
        snprintf(url_buffer, sizeof(url_buffer), "%s?vbat=%u", serverURL, v_mV);
        uploadSuccess = sendJpeg(fb->buf, fb->len, url_buffer);
        esp_camera_fb_return(fb); // Framebuffer nach dem Senden freigeben
        
        WiFi.disconnect(true);
        Serial.println(F("WiFi getrennt."));
      }
    }
  }
#endif

  if (uploadSuccess) {
    Serial.println(F("Bild-Upload erfolgreich abgeschlossen."));
  } else {
    Serial.println(F("Bild-Upload fehlgeschlagen."));
  }
  
  // WiFi komplett ausschalten
  WiFi.mode(WIFI_OFF);
  esp_wifi_stop();
  esp_wifi_deinit();
  
  goDeepSleep();
}

void loop() {
  /* nie erreicht */
}
