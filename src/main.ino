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
  analogSetPinAttenuation(VBAT_PIN, ADC_11db);  // 0–3.6 V
  uint16_t raw = analogRead(VBAT_PIN);          // 0–4095
  return raw * 3.3f / 4095.0f;                  // V
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
  cfg.frame_size   = FRAMESIZE_SVGA;    // 800×600
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
  int rc = http.POST(buf, len);
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

  if (!wifiConnect()) {
    Serial.println(F("WiFi fail – Sleep"));
    goDeepSleep();
  }

  if (camera_fb_t* fb = esp_camera_fb_get()) {
      String url = String(serverURL) + "?vbat=" + v_mV;
      sendJpeg(fb->buf, fb->len, url.c_str());
      esp_camera_fb_return(fb);
  } else {
      Serial.println(F("Foto capture fehlgeschlagen"));
  }

  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);

  goDeepSleep();
}

void loop() {
  /* nie erreicht */
}
