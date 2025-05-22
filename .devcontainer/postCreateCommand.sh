#!/usr/bin/env sh

sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive \
    apt-get install -y \
        openvpn php-codesniffer apt-cacher-ng file \
        net-tools whois iodine
sudo apt autoclean

npm install --save-dev stylelint stylelint-config-standard
npm install --save-dev stylelint-config-standard-scss

pipx install json-spec
python3 -m pip install \
            flake8 flake8-sarif \
            pylint lintrunner-adapters \
            bandit bandit-sarif \
            json-spec

sudo git clone https://github.com/devcontainers/spec/ /opt/devcontainers-spec
sudo chown -R vscode:vscode /opt/devcontainers-spec/
