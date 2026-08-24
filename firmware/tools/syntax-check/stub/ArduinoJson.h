/* Stub. Permissive on purpose: it exists so the surrounding sketch logic can
   be type-checked, not to model ArduinoJson faithfully. */
#pragma once
#include "Arduino.h"
#define ARDUINOJSON_VERSION_MAJOR 7

struct JsonVariant;
struct JsonVariantConst;

struct JsonVariantConst {
  bool isNull() const { return false; }
  template<class T> T as() const { return T(); }
  operator const char*() const { return ""; }
  operator int() const { return 0; }
  operator bool() const { return false; }
  JsonVariantConst operator[](const char*) const { return JsonVariantConst(); }
  template<class T> T operator|(T fallback) const { return fallback; }
  const char* operator|(const char* f) const { return f; }
};

struct JsonArrayConst {
  struct It {
    JsonVariantConst v;
    bool operator!=(const It&) const { return false; }
    void operator++() {}
    JsonVariantConst operator*() const { return v; }
  };
  It begin() const { return It(); }
  It end() const { return It(); }
  bool isNull() const { return false; }
  unsigned size() const { return 0; }
  JsonVariantConst operator[](int) const { return JsonVariantConst(); }
};

struct JsonVariant {
  bool isNull() const { return false; }
  template<class T> T as() const { return T(); }
  operator const char*() const { return ""; }
  operator int() const { return 0; }
  operator bool() const { return false; }
  JsonVariant operator[](const char*) const { return JsonVariant(); }
  template<class T> JsonVariant& operator=(T) { return *this; }
  template<class T> T to() { return T(); }
  template<class T> T operator|(T fallback) const { return fallback; }
  const char* operator|(const char* f) const { return f; }
  operator JsonArrayConst() const { return JsonArrayConst(); }
  operator JsonVariantConst() const { return JsonVariantConst(); }
};

struct JsonObject {
  template<class T> JsonVariant operator[](T) { return JsonVariant(); }
};

struct JsonArray {
  JsonObject createNestedObject() { return JsonObject(); }
  template<class T> T add() { return T(); }
};

struct JsonDocument {
  JsonVariant operator[](const char*) { return JsonVariant(); }
  JsonArray createNestedArray(const char*) { return JsonArray(); }
  JsonVariantConst operator[](const char*) const { return JsonVariantConst(); }
  void clear() {}
};

struct DeserializationError {
  operator bool() const { return false; }
  const char* c_str() const { return ""; }
};

inline unsigned serializeJson(const JsonDocument&, String& out) { out = String("{}"); return 2; }
inline DeserializationError deserializeJson(JsonDocument&, const String&) { return DeserializationError(); }
