#pragma once
#include "Arduino.h"
#define WIFI_STA 1
#define WL_IDLE_STATUS      0
#define WL_NO_SSID_AVAIL    1
#define WL_CONNECTED        3
#define WL_CONNECT_FAILED   4
#define WL_CONNECTION_LOST  5
#define WL_DISCONNECTED     6
class WiFiClient { public: void stop(){} };
class IPAddress {
  uint8_t o[4] = {0,0,0,0};
public:
  IPAddress(){}
  String toString() const { return String(); }
  bool fromString(const String&) { return true; }
  bool fromString(const char*) { return true; }
  uint8_t operator[](int i) const { return o[i]; }
  uint8_t& operator[](int i) { return o[i]; }
};
class WiFiClass {
public:
  void mode(int){}
  void begin(const char*,const char*){}
  void disconnect(bool=false){}
  int status(){return WL_CONNECTED;}
  IPAddress localIP(){return IPAddress();}
  String macAddress(){return String();}
  int scanNetworks(){return 0;}
  String SSID(int=0){return String();}
  int RSSI(int=0){return 0;}
  int channel(int=0){return 0;}
};
extern WiFiClass WiFi;
