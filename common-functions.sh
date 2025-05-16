#!/bin/bash

# Color echo functions (already present)
red_echo() {
    echo -e "\033[31m$1\033[0m"
}

green_echo() {
    echo -e "\033[32m$1\033[0m"
}

yellow_echo() {
    echo -e "\033[33m$1\033[0m"
}

# ---------------------------------------------------------------------------
# Added Checkmark Functions (to unify usage across scripts)
#   These mimic the original usage in img-unmount.sh:
#     echo "$(green_check) some message"
#     echo "$(red_x) some error"
# ---------------------------------------------------------------------------

green_check() {
    # Prints a green "✓" without a trailing newline.
    echo -e "\033[32m✓\033[0m"
}

red_x() {
    # Prints a red "✗" without a trailing newline.
    echo -e "\033[31m✗\033[0m"
}
