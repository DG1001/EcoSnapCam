#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include "esp_sleep.h"
#include "driver/rtc_io.h"

// WLAN Konfiguration
const char* ssid = "DEIN_WLAN_NAME";
const char* password = "DEIN_WLAN_PASSWORT";

// Server URL für Bildupload (anpassen nach Bedarf)
const char* serverURL = "http://dein-server.com/upload";

// ESP32-CAM AI-Thinker Pin Definition
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

// Sleep Zeit in Mikrosekunden (5 Minuten = 300 Sekunden)
#define SLEEP_TIME_US 300000000ULL

void setup() {
  Serial.begin(115200);
  Serial.println("ESP32-CAM Foto Timer gestartet");

  // Kamera initialisieren
  if (!initCamera()) {
    Serial.println("Kamera Initialisierung fehlgeschlagen!");
    esp_deep_sleep_start();
  }

  // WLAN verbinden
  if (!connectWiFi()) {
    Serial.println("WLAN Verbindung fehlgeschlagen!");
    esp_deep_sleep_start();
  }

  // Foto aufnehmen und senden
  takeAndSendPhoto();

  // Deep Sleep für 5 Minuten
  Serial.println("Gehe in Deep Sleep für 5 Minuten...");
  esp_sleep_enable_timer_wakeup(SLEEP_TIME_US);
  esp_deep_sleep_start();
}

void loop() {
  // Loop wird nie erreicht da ESP in Deep Sleep geht
}

bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // 800x600 Auflösung einstellen
  config.frame_size = FRAMESIZE_SVGA; // 800x600
  config.jpeg_quality = 10; // Niedrigerer Wert = bessere Qualität
  config.fb_count = 1;

  // Kamera initialisieren
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Kamera init Fehler: 0x%x", err);
    return false;
  }

  // Sensor Einstellungen optimieren
  sensor_t* s = esp_camera_sensor_get();
  if (s != NULL) {
    s->set_brightness(s, 0);     // -2 bis 2
    s->set_contrast(s, 0);       // -2 bis 2
    s->set_saturation(s, 0);     // -2 bis 2
    s->set_special_effect(s, 0); // 0 bis 6 (0=Normal)
    s->set_whitebal(s, 1);       // Weißabgleich an
    s->set_awb_gain(s, 1);       // Auto Weißabgleich Gain
    s->set_wb_mode(s, 0);        // 0 bis 4
    s->set_exposure_ctrl(s, 1);  // Auto Belichtung
    s->set_aec2(s, 0);           // AEC DSP
    s->set_ae_level(s, 0);       // -2 bis 2
    s->set_aec_value(s, 300);    // 0 bis 1200
    s->set_gain_ctrl(s, 1);      // Auto Gain
    s->set_agc_gain(s, 0);       // 0 bis 30
    s->set_gainceiling(s, (gainceiling_t)0); // 0 bis 6
    s->set_bpc(s, 0);            // Bad Pixel Correction
    s->set_wpc(s, 1);            // White Pixel Correction
    s->set_raw_gma(s, 1);        // Gamma Correction
    s->set_lenc(s, 1);           // Lens Correction
    s->set_hmirror(s, 0);        // Horizontal spiegeln
    s->set_vflip(s, 0);          // Vertikal spiegeln
    s->set_dcw(s, 1);            // DCW (Downsize EN)
    s->set_colorbar(s, 0);       // Testbild aus
  }

  Serial.println("Kamera erfolgreich initialisiert");
  return true;
}

bool connectWiFi() {
  WiFi.begin(ssid, password);
  Serial.print("Verbinde mit WLAN");
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println();
    Serial.print("WLAN verbunden! IP: ");
    Serial.println(WiFi.localIP());
    return true;
  } else {
    Serial.println();
    Serial.println("WLAN Verbindung timeout");
    return false;
  }
}

void takeAndSendPhoto() {
  Serial.println("Nehme Foto auf...");
  
  // Foto aufnehmen
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("Foto Aufnahme fehlgeschlagen");
    return;
  }

  Serial.printf("Foto aufgenommen: %dx%d, Größe: %u bytes\n", 
                fb->width, fb->height, fb->len);

  // Foto per HTTP POST senden
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(serverURL);
    http.addHeader("Content-Type", "image/jpeg");
    
    // Optional: Weitere Header hinzufügen
    http.addHeader("X-Device-ID", "ESP32-CAM-001");
    http.addHeader("X-Timestamp", String(millis()));
    
    Serial.println("Sende Foto...");
    int httpResponseCode = http.POST(fb->buf, fb->len);
    
    if (httpResponseCode > 0) {
      String response = http.getString();
      Serial.printf("HTTP Response: %d\n", httpResponseCode);
      Serial.println("Server Antwort: " + response);
    } else {
      Serial.printf("HTTP Fehler: %d\n", httpResponseCode);
    }
    
    http.end();
  }

  // Foto Puffer freigeben
  esp_camera_fb_return(fb);
  Serial.println("Foto gesendet und Puffer freigegeben");
}

// Alternative Funktion für FTP Upload (optional)
void sendPhotoFTP(camera_fb_t* fb) {
  // FTP Implementation hier einfügen falls gewünscht
  // Benötigt zusätzliche FTP Client Bibliothek
}