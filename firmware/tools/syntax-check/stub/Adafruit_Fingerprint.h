#pragma once
#include "Arduino.h"
#define FINGERPRINT_OK          0x00
#define FINGERPRINT_NOFINGER    0x02
#define FINGERPRINT_NOTFOUND    0x09
class Adafruit_Fingerprint {
public:
  uint16_t fingerID, confidence, templateCount, capacity;
  uint8_t  security_level;
  Adafruit_Fingerprint(HardwareSerial*, uint32_t=0){}
  bool verifyPassword(){return true;}
  uint8_t getParameters(){return FINGERPRINT_OK;}
  uint8_t getImage(){return FINGERPRINT_OK;}
  uint8_t image2Tz(uint8_t=1){return FINGERPRINT_OK;}
  uint8_t createModel(){return FINGERPRINT_OK;}
  uint8_t storeModel(uint16_t){return FINGERPRINT_OK;}
  uint8_t loadModel(uint16_t){return FINGERPRINT_OK;}
  uint8_t deleteModel(uint16_t){return FINGERPRINT_OK;}
  uint8_t emptyDatabase(){return FINGERPRINT_OK;}
  uint8_t fingerFastSearch(){return FINGERPRINT_OK;}
  uint8_t fingerSearch(uint8_t=1){return FINGERPRINT_OK;}
  uint8_t getTemplateCount(){return FINGERPRINT_OK;}
};
