# Only for Dave-approved system-dependent capability tests; ordinary suites are native.
FROM php:8.3-cli-bookworm@sha256:177529735599a8244b2c903522f029839dce1c2ac4be122fdc00ada4b45a20e4
RUN apt-get update && apt-get install -y --no-install-recommends git unzip zlib1g-dev default-mysql-client \
    && docker-php-ext-install mysqli \
    && pecl install xhprof-2.3.10 opentelemetry-1.4.1 \
    && git clone --branch v0.4.22 --depth 1 https://github.com/NoiseByNorthwest/php-spx.git /usr/src/php-spx \
    && cd /usr/src/php-spx && phpize && ./configure && make -j2 && make install
# Optional extensions stay disabled by default; each row enables them explicitly.
RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp
WORKDIR /var/www/html
CMD ["sleep", "infinity"]
