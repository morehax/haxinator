#!/usr/bin/env sh

CODEQL_BUNDLE_VER=2.21.3

sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive \
    apt-get install -y \
        openvpn php-codesniffer apt-cacher-ng file \
        net-tools whois iodine
sudo apt autoclean

npm install --save-dev stylelint stylelint-config-standard
npm install --save-dev stylelint-config-standard-scss
npm install --save-dev markdownlint-cli2-formatter-sarif
npm install -g @microsoft/sarif-multitool


python3 -m pip install \
            flake8 flake8-sarif \
            pylint lintrunner-adapters \
            bandit bandit-sarif \
            json-spec

sudo git clone https://github.com/devcontainers/spec/ /opt/devcontainers-spec
sudo chown -R vscode:vscode /opt/devcontainers-spec/

wget "https://github.com/github/codeql-action/releases/download/codeql-bundle-v${CODEQL_BUNDLE_VER}/codeql-bundle-linux64.tar.gz" -O /tmp/codeql-bundle-linux64.tar.gz
sudo tar -zxf /tmp//tmp/codeql-bundle-linux64.tar.gz --directory /opt
rm /tmp//tmp/codeql-bundle-linux64.tar.gz

# Delete unneeded languages
# shellcheck disable=SC3009
sudo rm -rf /opt/codeql/{java,cpp,csharp,go,swift,ruby,Open-Source-Notices,LICENSE.md}
sudo rm -rf /opt/codeql/tools/java

# shellcheck disable=SC2016
echo 'PATH="/opt/codeql:$PATH"' >> "${HOME}/.profile"
# shellcheck disable=SC2016
echo 'PATH="/opt/codeql:$PATH"' >> "${HOME}/.bashrc"
# shellcheck disable=SC2016
echo 'PATH="/opt/codeql:$PATH"' >> "${HOME}/.zshrc"
