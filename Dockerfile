# Container image for the transliteration app.
#
# The app itself has no third-party packages — this image only needs to supply
# PHP with pdo_mysql, mbstring and curl, plus Apache.
#
# PHP_VERSION is a build argument so the same image can verify the PHP 7.4
# compatibility that CLAUDE.md section 3 requires:
#
#   docker build --build-arg PHP_VERSION=7.4 -t translit:php74 .
#
# 8.2 is the default because 7.4 is end-of-life and unpatched. The application
# code is written to 7.4 syntax and runs unchanged on both.
ARG PHP_VERSION=8.2

FROM php:${PHP_VERSION}-apache

# mbstring and curl need their headers; pdo_mysql is bundled but not enabled.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libonig-dev \
      libcurl4-openssl-dev \
      default-mysql-client \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring curl \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*

# Devanagari renders from the browser's font stack, so no fonts are needed in
# the image. mPDF would need them, but it is optional and not installed here.

WORKDIR /var/www/html
COPY . /var/www/html/

# Apache runs as www-data; it only ever needs to read these files.
RUN chown -R www-data:www-data /var/www/html

# Production defaults. Override at run time with -e.
ENV TRANSLIT_DEBUG=0 \
    TRANSLIT_DB_HOST=db \
    TRANSLIT_DB_PORT=3306 \
    TRANSLIT_DB_NAME=translit_demo \
    TRANSLIT_DB_USER=translit \
    TRANSLIT_DB_PASS=translit

# Hosts such as Railway and Render inject the port to listen on. Apache's
# default is 80; the entrypoint rewrites it when $PORT says otherwise.
ENV PORT=80
EXPOSE 80

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT}/index.php" > /dev/null || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
