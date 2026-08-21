/* Translation unit: defines the globals the stubs declare, then pulls the
   sketch in verbatim so g++ sees exactly what the Arduino IDE would. */
#include "Arduino.h"
HardwareSerial Serial(0);
EspClass ESP;
#include "WiFi.h"
WiFiClass WiFi;
#include "SPI.h"
SPIClass SPI;
#include "esp_system.h"
esp_reset_reason_t esp_reset_reason(){ return ESP_RST_POWERON; }
int esp_read_mac(uint8_t* m, esp_mac_type_t){ for(int i=0;i<6;i++) m[i]=0; return 0; }
#include "mbedtls/md.h"
void mbedtls_md_init(mbedtls_md_context_t*){}
void mbedtls_md_free(mbedtls_md_context_t*){}
const mbedtls_md_info_t* mbedtls_md_info_from_type(mbedtls_md_type_t){return nullptr;}
int mbedtls_md_setup(mbedtls_md_context_t*,const mbedtls_md_info_t*,int){return 0;}
int mbedtls_md_starts(mbedtls_md_context_t*){return 0;}
int mbedtls_md_update(mbedtls_md_context_t*,const unsigned char*,size_t){return 0;}
int mbedtls_md_finish(mbedtls_md_context_t*,unsigned char*){return 0;}
int mbedtls_md_hmac_starts(mbedtls_md_context_t*,const unsigned char*,size_t){return 0;}
int mbedtls_md_hmac_update(mbedtls_md_context_t*,const unsigned char*,size_t){return 0;}
int mbedtls_md_hmac_finish(mbedtls_md_context_t*,unsigned char*){return 0;}
#include "../../L_SIAMS_Bench/L_SIAMS_Bench.ino"
int main(){ setup(); loop(); return 0; }
