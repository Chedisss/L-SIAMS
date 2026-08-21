/* Stub. Just enough of the Arduino core to type-check the sketch on a PC. */
#pragma once
#include <cstdint>
#include <cstring>
#include <cstdio>
#include <cstdlib>
#include <string>
#include <ctime>
typedef uint8_t byte;
class String {
  std::string s;
public:
  String() {}
  String(const char*c):s(c?c:""){}
  String(const std::string&x):s(x){}
  String(int v){char b[32];snprintf(b,32,"%d",v);s=b;}
  String(unsigned v){char b[32];snprintf(b,32,"%u",v);s=b;}
  String(long v){char b[32];snprintf(b,32,"%ld",v);s=b;}
  String(unsigned long v){char b[32];snprintf(b,32,"%lu",v);s=b;}
  String(char c){s=std::string(1,c);}
  const char* c_str() const {return s.c_str();}
  unsigned length() const {return (unsigned)s.size();}
  void reserve(unsigned){}
  void trim(){}
  void toLowerCase(){}
  void toUpperCase(){}
  int indexOf(const char*n) const {auto p=s.find(n);return p==std::string::npos?-1:(int)p;}
  int indexOf(char n) const {auto p=s.find(n);return p==std::string::npos?-1:(int)p;}
  String substring(unsigned a) const {return String(s.substr(a));}
  String substring(unsigned a,unsigned b) const {return String(s.substr(a,b-a));}
  bool startsWith(const char*p) const {return s.rfind(p,0)==0;}
  int toInt() const {return atoi(s.c_str());}
  String& operator+=(const String&o){s+=o.s;return *this;}
  String& operator+=(const char*o){s+=o;return *this;}
  String& operator+=(char o){s+=o;return *this;}
  friend String operator+(String a,const String&b){a.s+=b.s;return a;}
  friend String operator+(String a,const char*b){a.s+=b;return a;}
  bool operator==(const String&o) const {return s==o.s;}
  bool operator==(const char*o) const {return s==o;}
  bool operator!=(const String&o) const {return s!=o.s;}
  char operator[](unsigned i) const {return s[i];}
};
inline String operator+(const char*a,const String&b){return String(a)+b;}
class Print {
public:
  void print(const char*){} void print(const String&){} void print(int){} void print(char){}
  void println(const char*){} void println(const String&){} void println(int){} void println(){}
  template<class T> void print(const T&){}
  template<class T> void println(const T&){}
  int printf(const char*,...){return 0;}
  size_t write(uint8_t){return 1;}
  void flush(){}
};
class Stream : public Print {
public:
  int available(){return 0;}
  int read(){return -1;}
  void setTimeout(unsigned long){}
  String readStringUntil(char){return String();}
};
class HardwareSerial : public Stream {
public:
  HardwareSerial(int){}
  void begin(unsigned long, uint32_t=0, int=-1, int=-1){}
  void end(){}
};
extern HardwareSerial Serial;
#define SERIAL_8N1 0x800001c
inline unsigned long millis(){return 0;}
inline void delay(unsigned long){}
inline uint32_t esp_random(){return 0;}
class EspClass { public: uint32_t getFreeHeap(){return 0;} };
extern EspClass ESP;
