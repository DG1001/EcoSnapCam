// Diese Datei wird von TFT_eSPI verwendet, wenn USER_SETUP_LOADED in platformio.ini definiert ist.
// Konfiguration für das "Cheap Yellow Display" (CYD) ESP32-2432S028R

#ifndef USER_SETUP_LOADED // Verhindert Mehrfachdefinitionen
#define USER_SETUP_LOADED

#define ILI9341_DRIVER

// Display-Auflösung des CYD
#define TFT_WIDTH  320
#define TFT_HEIGHT 240

// Pin-Definitionen für das CYD
#define TFT_MOSI 13
#define TFT_SCLK 14
#define TFT_CS   15
#define TFT_DC    2
#define TFT_RST   4  // RST-Pin des Displays. -1 wenn nicht verbunden.
#define TFT_BL   21  // Pin für die Hintergrundbeleuchtung (Backlight)

// Optional: Touchscreen Pins (werden hier nicht verwendet)
// #define TOUCH_CS 33
// #define TOUCH_IRQ 32 // Nicht immer verbunden oder genutzt

// Zu ladende Fonts
#define LOAD_GLCD  // Font 1. Original Adafruit 8 pixel font needs ~1820 bytes in FLASH
#define LOAD_FONT2 // Font 2. Small 16 pixel high font, needs ~3534 bytes in FLASH, 96 characters
#define LOAD_FONT4 // Font 4. Medium 26 pixel high font, needs ~5848 bytes in FLASH, 96 characters
#define LOAD_FONT6 // Font 6. Large 48 pixel font, needs ~2666 bytes in FLASH, only characters 1234567890:-.apm
#define LOAD_FONT7 // Font 7. 7 segment 48 pixel font, needs ~2438 bytes in FLASH, only characters 1234567890:-.
#define LOAD_FONT8 // Font 8. Large 75 pixel font needs ~3256 bytes in FLASH, only characters 1234567890:-.
// #define LOAD_GFXFF // FreeFonts. Include access to the 48 Adafruit_GFX free fonts FF1 to FF48 and custom fonts

#define SMOOTH_FONT

// SPI-Frequenz
#define SPI_FREQUENCY         40000000
// #define SPI_READ_FREQUENCY  20000000 // Optional für Lesen vom Display

#endif // USER_SETUP_LOADED
