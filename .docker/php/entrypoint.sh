#!/bin/sh
# Dev only: the CLI (`docker compose run php ...`, root) and php-fpm
# (www-data) both write to the bind-mounted var/. Files created by one must
# stay writable by the other.
umask 000
exec docker-php-entrypoint "$@"
