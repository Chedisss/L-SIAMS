#pragma once
#include <cstdint>

#define MSBFIRST   1
#define SPI_MODE0  0

class SPISettings {
public:
  SPISettings(uint32_t hz = 1000000, uint8_t order = MSBFIRST, uint8_t mode = SPI_MODE0)
  { (void)hz; (void)order; (void)mode; }
};

class SPIClass { public:
  void begin(int8_t sck = -1, int8_t miso = -1, int8_t mosi = -1, int8_t ss = -1)
  { (void)sck; (void)miso; (void)mosi; (void)ss; }
  void setHwCs(bool use){ (void)use; }
  void beginTransaction(SPISettings s){ (void)s; }
  void endTransaction(){}
  uint8_t transfer(uint8_t data){ return data; }
};
extern SPIClass SPI;
