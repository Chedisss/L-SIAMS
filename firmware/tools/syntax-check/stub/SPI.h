#pragma once
class SPIClass { public:
  void begin(int8_t sck = -1, int8_t miso = -1, int8_t mosi = -1, int8_t ss = -1)
  { (void)sck; (void)miso; (void)mosi; (void)ss; }
  void setHwCs(bool use){ (void)use; }
};
extern SPIClass SPI;
