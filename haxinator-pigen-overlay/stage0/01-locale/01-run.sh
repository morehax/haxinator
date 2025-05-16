#!/bin/bash
echo "en_US.UTF-8 UTF-8" > /etc/locale.gen
locale-gen en_US.UTF-8
update-locale LANG=en_US.UTF-8
