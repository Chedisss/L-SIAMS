#pragma once
#include "Arduino.h"
class MFRC522 {
public:
  enum PCD_Register { VersionReg=0x37<<1, TxControlReg=0x14<<1 };
  struct Uid { byte size; byte uidByte[10]; byte sak; };
  Uid uid;
  MFRC522(byte, byte){}
  void PCD_Init(){}
  byte PCD_ReadRegister(PCD_Register){return 0x92;}
  void PCD_WriteRegister(PCD_Register, byte){}
  bool PCD_PerformSelfTest(){return true;}
  void PCD_AntennaOn(){}
  void PCD_AntennaOff(){}
  bool PICC_IsNewCardPresent(){return false;}
  bool PICC_ReadCardSerial(){return false;}
  void PICC_HaltA(){}
  void PCD_StopCrypto1(){}
};
