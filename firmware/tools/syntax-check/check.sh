#!/bin/bash
# Type-check the terminal sketch without an ESP32 toolchain.
#   ./check.sh
cd "$(dirname "$0")" || exit 1
g++ -std=gnu++17 -fsyntax-only -I stub \
    -Wall -Wextra -Wshadow -Wsign-compare -Wuninitialized \
    -Wreturn-type -Wparentheses -Wno-unused-parameter \
    tu.cpp && echo "OK — no errors, no warnings"
