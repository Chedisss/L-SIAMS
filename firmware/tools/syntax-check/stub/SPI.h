#pragma once
class SPIClass { public:
  void begin(){}
  void begin(int8_t sck, int8_t miso, int8_t mosi, int8_t ss = -1){ (void)sck;(void)miso;(void)mosi;(void)ss; }
};
extern SPIClass SPI;
