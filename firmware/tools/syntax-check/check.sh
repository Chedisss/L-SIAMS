#!/bin/bash
# Type-check the terminal sketch without an ESP32 toolchain.
#   ./check.sh
cd "$(dirname "$0")" || exit 1
FLAGS="-std=gnu++17 -fsyntax-only -I stub -Wall -Wextra -Wshadow
       -Wsign-compare -Wuninitialized -Wreturn-type -Wparentheses
       -Wno-unused-parameter"

fail=0
for unit in tu-terminal.cpp tu.cpp; do
    name=$(sed -n 's|.*/\(L_SIAMS_[A-Za-z]*\)/.*|\1|p' "$unit")
    printf '%-18s ' "$name"
    if g++ $FLAGS "$unit"; then
        echo "OK — no errors, no warnings"
    else
        fail=1
    fi
done
exit $fail
