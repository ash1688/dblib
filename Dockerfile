# dblib — PHP 8.2 + Apache image.
#
# Mirrors the XAMPP runtime (Apache + mod_rewrite + PHP with pdo_mysql/openssl)
# so the app code is unchanged; only the front-controller routing moves from the
# /dblib/ subpath to the web root (see docker/vhost.conf).
FROM php:8.2-apache

# remoteip restores the real client address behind a reverse proxy such as
# Dokploy's Traefik; which proxies to trust is decided at runtime by the
# entrypoint from DBLIB_TRUSTED_PROXIES.
# pdo_mysql is the only extension the app needs that isn't already in the base
# image (openssl ships enabled). mod_rewrite drives the front controller.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite remoteip

# Apache vhost: document root = web root, with the front-controller rewrite and
# the same source/secret denials the XAMPP .htaccess provides at /dblib/.
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Application code.
COPY . /var/www/html/

# Keep the Docker-only, env-driven config under config/ (which the vhost denies
# to the web), drop the build-only docker/ dir, and hand everything to www-data.
RUN cp /var/www/html/docker/config.docker.php /var/www/html/config/config.docker.php \
    && cp /var/www/html/docker/wait-for-db.php /usr/local/share/wait-for-db.php \
    && cp /var/www/html/docker/entrypoint.sh /usr/local/bin/dblib-entrypoint \
    && chmod +x /usr/local/bin/dblib-entrypoint \
    && rm -rf /var/www/html/docker \
    && chown -R www-data:www-data /var/www/html

ENTRYPOINT ["dblib-entrypoint"]
CMD ["apache2-foreground"]
