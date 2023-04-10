PORT ?= 8000

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

lint:
	composer exec --verbose phpcs -- --standard=PSR12 public/index.php src/Connection.php

lint-fix:
	composer exec --verbose phpcbf -- --standard=PSR12 public/index.php src/Connection.php

install:
	composer install