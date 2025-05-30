#!/usr/bin/env sh

CODEQL_BUNDLE_VER=2.21.3

sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive \
    apt-get install -y \
        openvpn php-codesniffer apt-cacher-ng file \
        net-tools whois iodine yq xq
sudo apt autoclean

npm install --save-dev stylelint stylelint-config-standard
npm install --save-dev stylelint-config-standard-scss
npm install --save-dev markdownlint-cli2-formatter-sarif
npm install -g @microsoft/sarif-multitool
npm install -g @devcontainers/cli

python3 -m pip install \
            flake8 flake8-sarif \
            pylint lintrunner-adapters \
            bandit bandit-sarif \
            json-spec

sudo git clone https://github.com/devcontainers/spec/ /opt/devcontainers-spec
sudo chown -R vscode:vscode /opt/devcontainers-spec/


arch="$(uname -m)"
if [ "${arch}" != "x86_64" ]; then
    echo 'Package for CodeQL tools are meant for x86_64'
    exit 0
fi

CODEQL_TARGZ="/tmp/codeql-bundle-linux64.tar.gz"
CODEQL_TARGZ_WORKSPACE="/workspaces/${localWorkspaceFolderBasename}/$(basename ${CODEQL_TARGZ})"
if [ -f "${CODEQL_TARGZ_WORKSPACE}" ]; then
    cp "${CODEQL_TARGZ_WORKSPACE}" "$(dirname ${CODEQL_TARGZ})"
    echo "CODEQL Download not needed, file in cache"
else
    wget "https://github.com/github/codeql-action/releases/download/codeql-bundle-v${CODEQL_BUNDLE_VER}/codeql-bundle-linux64.tar.gz" -O "${CODEQL_TARGZ}"
fi

sudo tar -zxf "${CODEQL_TARGZ}" --directory /opt
rm "${CODEQL_TARGZ}"
unset CODEQL_TARGZ
unset CODEQL_BUNDLE_VER

composer global require --no-cache --dev phpstan/phpstan

# Delete unneeded languages
# shellcheck disable=SC3009
sudo rm -rf /opt/codeql/{java,cpp,csharp,go,swift,ruby,Open-Source-Notices,LICENSE.md}
sudo rm -rf /opt/codeql/tools/java

# shellcheck disable=SC2016
echo 'PATH="/home/vscode/.config/composer/vendor/bin/:/opt/codeql:$PATH"' >> "${HOME}/.profile"
# shellcheck disable=SC2016
echo 'PATH="/home/vscode/.config/composer/vendor/bin/:/opt/codeql:$PATH"' >> "${HOME}/.bashrc"
# shellcheck disable=SC2016
echo 'PATH="/home/vscode/.config/composer/vendor/bin/:/opt/codeql:$PATH"' >> "${HOME}/.zshrc"
