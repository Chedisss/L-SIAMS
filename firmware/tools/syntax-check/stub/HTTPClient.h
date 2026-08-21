#pragma once
#include "Arduino.h"
#include "WiFi.h"
#include "WiFiClientSecure.h"
class HTTPClient {
public:
  bool begin(const String&){return true;}
  bool begin(WiFiClient&, const String&){return true;}
  bool begin(WiFiClientSecure&, const String&){return true;}
  void addHeader(const String&, const String&){}
  void setTimeout(uint16_t){}
  int GET(){return 200;}
  int POST(const String&){return 200;}
  String getString(){return String();}
  void end(){}
};
