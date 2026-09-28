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
    uint16_t getUShort(const char*, uint16_t defaultValue = 0) { return defaultValue; }
    size_t   putUShort(const char*, uint16_t) { return 2; }
    size_t   getBytesLength(const char*) { return 0; }
    size_t   getBytes(const char*, void*, size_t) { return 0; }
    size_t   putBytes(const char*, const void*, size_t len) { return len; }
    bool     remove(const char*) { return true; }
};
