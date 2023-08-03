#!/usr/bin/env bash

set -e

# curl -L https://raw.githubusercontent.com/tarosky/workflow/main/setup.sh | bash
# Run PHP
curl -L https://raw.githubusercontent.com/tarosky/workflow/main/setup.php | php

# If package.json exists, install dependencies.
if [ -f package.json ]; then
	npm install --save-dev @wordpress/env @wordpress/scripts @wordpress/stylelint-config
fi

# If composer.json exists, install dependencies.
if [ -f composer.json ]; then
	composer require --dev phpunit/phpunit squizlabs/php_codesniffer wp-coding-standards/wpcs yoast/phpunit-polyfills phpcompatibility/php-compatibility dealerdirect/phpcodesniffer-composer-installer
fi
