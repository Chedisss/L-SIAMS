#pragma once
#include "Arduino.h"

/* Just enough of the ESP32 core's Preferences (NVS) API for the type check:
   the sketch stores one value, the last time it knew for certain, so a power
   cut cannot leave it back at its build date and unable to accept a renewed
   certificate. */
class Preferences {
public:
    bool     begin(const char*, bool = false) { return true; }
    void     end() {}
    uint32_t getULong(const char*, uint32_t defaultValue = 0) { return defaultValue; }
    size_t   putULong(const char*, uint32_t) { return 4; }
};
