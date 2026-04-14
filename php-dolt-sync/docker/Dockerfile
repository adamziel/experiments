FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev unzip less curl ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install intl zip \
 && a2enmod rewrite

RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
 && chmod +x /usr/local/bin/wp

ENV WP_VERSION=6.5.2
ENV SQLITE_PLUGIN_VERSION=2.1.13

WORKDIR /var/www/html

RUN curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar xz --strip-components=1 \
 && curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip" -o /tmp/sqlite.zip \
 && unzip -q /tmp/sqlite.zip -d wp-content/plugins/ \
 && rm /tmp/sqlite.zip \
 && cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php \
 && sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/var/www/html/wp-content/plugins/sqlite-database-integration#g" wp-content/db.php \
 && sed -i "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" wp-content/db.php \
 && mkdir -p wp-content/database wpsync-data \
 && chown -R www-data:www-data /var/www/html

COPY wp-config.php /var/www/html/wp-config.php
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
