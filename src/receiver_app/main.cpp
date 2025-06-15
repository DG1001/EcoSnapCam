#include <Arduino.h>
#include <esp_now.h>
#include <WiFi.h>
#include <esp_wifi.h>
// User_Setup.h muss vor TFT_eSPI.h eingebunden werden, 
// damit die Makros wie TFT_WIDTH korrekt definiert sind.
#include "User_Setup.h" 
#include <TFT_eSPI.h>
#include <TJpg_Decoder.h>

// WLAN-Kanal für ESP-NOW (muss mit dem Sender übereinstimmen)
// WICHTIG: Passen Sie ESP_NOW_CHANNEL in der config.h des Senders an diesen Wert an!
#define ESP_NOW_RECEIVER_CHANNEL 1

// Definition der Chunk-Struktur (muss mit der Sender-Struktur übereinstimmen)
// Header: image_id (4), total_size (4), chunk_index (2), total_chunks (2), data_len (1), vbat_mv_high (1), vbat_mv_low (1) = 15 Bytes
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

TFT_eSPI tft = TFT_eSPI(); // TFT_eSPI Objekt initialisieren

// Puffer für das zusammengesetzte Bild
uint8_t* imageDataBuffer = nullptr;
uint32_t imageBufferSize = 0;
uint32_t currentImageId = 0;
uint32_t receivedImageBytes = 0;
uint16_t lastVBat_mV = 0;

volatile bool imageReceiveInProgress = false;
volatile bool newImageReadyToDisplay = false;

// Callback-Funktion für TJpg_Decoder, um Pixeldaten auf das Display zu schreiben
bool tft_output(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t* bitmap) {
  if (y >= tft.height()) return false; // Stoppt, wenn das Bild über den Bildschirmrand hinausgeht
  tft.pushImage(x, y, w, h, bitmap);
  return true; // Weiter mit dem nächsten Block
}

// Callback-Funktion für den Empfang von ESP-NOW Daten
void OnDataRecv(const uint8_t * mac, const uint8_t *incomingData, int len) {
  if (len < sizeof(esp_now_image_chunk_t) - ESP_NOW_MAX_DATA_PER_CHUNK) { // Mindestgröße eines Chunks (Header)
    Serial.println("Empfangenes Paket zu klein.");
    return;
  }

  esp_now_image_chunk_t chunk;
  memcpy(&chunk, incomingData, sizeof(esp_now_image_chunk_t)); // Kopiere den vollen Chunk, auch wenn data nicht voll ist

  // Erster Chunk eines neuen Bildes oder Bild-ID hat sich geändert
  if (chunk.chunk_index == 0 || (imageReceiveInProgress && chunk.image_id != currentImageId) ) {
    if (imageDataBuffer != nullptr) {
      free(imageDataBuffer);
      imageDataBuffer = nullptr;
    }
    imageBufferSize = chunk.total_size;
    imageDataBuffer = (uint8_t*)malloc(imageBufferSize);

    if (imageDataBuffer == nullptr) {
      Serial.printf("Fehler: Konnte nicht %u Bytes für Bild ID %u reservieren.\n", imageBufferSize, chunk.image_id);
      tft.fillScreen(TFT_RED);
      tft.setCursor(10, 10);
      tft.setTextSize(2);
      tft.setTextColor(TFT_WHITE);
      tft.println("Speicherfehler!");
      imageReceiveInProgress = false;
      return;
    }
    Serial.printf("Empfange neues Bild ID: %u, Groesse: %u Bytes, Chunks: %u\n", chunk.image_id, imageBufferSize, chunk.total_chunks);
    currentImageId = chunk.image_id;
    receivedImageBytes = 0;
    imageReceiveInProgress = true;
    newImageReadyToDisplay = false;

    // Info auf Display
    tft.fillScreen(TFT_BLACK);
    tft.setCursor(5, 10);
    tft.setTextSize(2);
    tft.setTextColor(TFT_GREEN, TFT_BLACK);
    tft.printf("Empfange Bild ID %u\n", currentImageId);
    tft.printf("Groesse: %u Bytes\n", imageBufferSize);
    tft.printf("Chunks: %u\n", chunk.total_chunks);

  } else if (!imageReceiveInProgress || chunk.image_id != currentImageId) {
    Serial.printf("Verwerfe Chunk für Bild ID %u, erwarte ID %u oder keinen Empfang.\n", chunk.image_id, currentImageId);
    return; // Chunk gehört nicht zum aktuellen Bild oder kein Empfang aktiv
  }

  // Daten in den Puffer kopieren
  uint32_t offset = chunk.chunk_index * ESP_NOW_MAX_DATA_PER_CHUNK;
  if (offset + chunk.data_len <= imageBufferSize) {
    memcpy(imageDataBuffer + offset, chunk.data, chunk.data_len);
    receivedImageBytes += chunk.data_len;
  } else {
    Serial.println("Fehler: Chunk-Daten würden Puffer überlaufen.");
    free(imageDataBuffer);
    imageDataBuffer = nullptr;
    imageReceiveInProgress = false;
    return;
  }
  
  lastVBat_mV = (chunk.vbat_mv_high << 8) | chunk.vbat_mv_low;

  // Fortschritt anzeigen (optional, kann bei vielen Chunks flackern)
  tft.fillRect(5, 80, tft.width() - 10, 20, TFT_BLACK); // Alten Fortschritt löschen
  tft.setCursor(5, 80);
  tft.setTextSize(2);
  tft.setTextColor(TFT_CYAN, TFT_BLACK);
  tft.printf("Chunk %u/%u", chunk.chunk_index + 1, chunk.total_chunks);
  Serial.printf("Chunk %u/%u (ID %u) empfangen. %u/%u Bytes.\n", chunk.chunk_index + 1, chunk.total_chunks, chunk.image_id, receivedImageBytes, imageBufferSize);

  // Prüfen, ob alle Chunks empfangen wurden
  if (receivedImageBytes >= imageBufferSize && chunk.chunk_index == chunk.total_chunks - 1) {
    Serial.println("Bild vollstaendig empfangen.");
    newImageReadyToDisplay = true;
    imageReceiveInProgress = false; // Empfang für dieses Bild abgeschlossen
  }
}

void setup() {
  Serial.begin(115200);
  Serial.println("ESP-NOW Empfaenger gestartet.");

  // TFT initialisieren
  tft.begin();
  tft.setRotation(1); // Querformat für CYD (320x240). Rotation 1 oder 3.
                      // Wenn User_Setup.h TFT_WIDTH=320, TFT_HEIGHT=240 hat, ist Rotation 0 oder 2 korrekt.
                      // Da User_Setup.h TFT_WIDTH=320, TFT_HEIGHT=240 hat, ist Rotation 0 korrekt.
                      // Ich lasse es bei 1, da dies oft für Landscape bei ILI9341 verwendet wird, wenn H > B in der Definition ist.
                      // Korrektur: User_Setup.h hat WIDTH 320, HEIGHT 240. Rotation 0 ist korrekt.
  tft.setRotation(0); // Korrekte Rotation für Landscape, wenn TFT_WIDTH=320, TFT_HEIGHT=240
  tft.fillScreen(TFT_BLACK);
  tft.setTextSize(2);
  tft.setTextColor(TFT_WHITE, TFT_BLACK);
  tft.setCursor(10, 10);
  tft.println("EcoSnapCam Empfaenger");
  tft.println("Warte auf Bilder...");

  // Hintergrundbeleuchtung einschalten (Pin ist in User_Setup.h als TFT_BL definiert)
  #ifdef TFT_BL
    pinMode(TFT_BL, OUTPUT);
    digitalWrite(TFT_BL, LOW); // Testweise auf LOW ändern, falls die Logik invertiert ist
  #endif

  // TJpg_Decoder konfigurieren
  TJpgDec.setJpgScale(1);      // Keine Skalierung
  TJpgDec.setSwapBytes(true);  // Byte-Reihenfolge für Farben korrigieren (oft nötig)
  TJpgDec.setCallback(tft_output);

  // ESP-NOW initialisieren
  WiFi.mode(WIFI_STA);
  // Wichtig: Kanal für ESP-NOW festlegen. Muss mit Sender übereinstimmen.
  // esp_wifi_set_promiscuous(true); // Nicht unbedingt nötig für reinen Empfang auf festem Kanal
  if (esp_wifi_set_channel(ESP_NOW_RECEIVER_CHANNEL, WIFI_SECOND_CHAN_NONE) != ESP_OK) {
    Serial.printf("Fehler beim Setzen des Kanals auf %d\n", ESP_NOW_RECEIVER_CHANNEL);
    tft.println("Kanal Fehler!");
    return;
  }
  // esp_wifi_set_promiscuous(false);

  if (esp_now_init() != ESP_OK) {
    Serial.println("Fehler bei der Initialisierung von ESP-NOW.");
    tft.println("ESP-NOW Init Fehler!");
    return;
  }

  esp_now_register_recv_cb(OnDataRecv);
  Serial.printf("ESP-NOW initialisiert. Lausche auf Kanal %d.\n", ESP_NOW_RECEIVER_CHANNEL);
}

void loop() {
  if (newImageReadyToDisplay) {
    newImageReadyToDisplay = false; // Flag zurücksetzen

    if (imageDataBuffer != nullptr && imageBufferSize > 0) {
      Serial.printf("Zeige Bild ID %u (%u Bytes) an...\n", currentImageId, imageBufferSize);
      tft.fillScreen(TFT_BLACK); // Bildschirm leeren

      // TJpg_Decoder aufrufen, um das Bild zu zeichnen
      // Die x,y Koordinaten sind die obere linke Ecke des Bildes auf dem Display
      JRESULT result = TJpgDec.drawJpg(0, 0, imageDataBuffer, imageBufferSize);

      if (result == JDR_OK) {
        Serial.println("Bild erfolgreich angezeigt.");
        tft.setCursor(5, tft.height() - 40); // Unten auf dem Display
        tft.setTextSize(1);
        tft.setTextColor(TFT_YELLOW, TFT_BLACK);
        tft.printf("Bild ID: %u | VBat: %.2fV", currentImageId, lastVBat_mV / 1000.0f);
      } else {
        Serial.printf("Fehler beim Dekodieren/Anzeigen des JPEGs: %d\n", result);
        tft.fillScreen(TFT_RED);
        tft.setCursor(10,10);
        tft.setTextSize(2);
        tft.setTextColor(TFT_WHITE);
        tft.println("JPEG Fehler!");
        tft.printf("Code: %d", result);
      }
      // Speicher für das aktuelle Bild freigeben
      free(imageDataBuffer);
      imageDataBuffer = nullptr;
      imageBufferSize = 0;
      receivedImageBytes = 0; // Zurücksetzen für das nächste Bild
      // currentImageId bleibt für die Anzeige, wird beim nächsten Empfang überschrieben
    } else {
      Serial.println("Keine gültigen Bilddaten zum Anzeigen vorhanden.");
    }
     // Nach Anzeige wieder auf neue Bilder warten
    if (!imageReceiveInProgress) { // Nur wenn nicht schon ein neues Bild empfangen wird
        tft.setCursor(10, tft.height() / 2); // Zentrierter
        tft.setTextSize(2);
        tft.setTextColor(TFT_WHITE, TFT_BLACK);
        // tft.println("Warte auf Bilder..."); // Kann die VBat Anzeige überdecken
    }
  }
  delay(10); // Kurze Pause
}
