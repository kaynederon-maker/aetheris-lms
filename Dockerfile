# Aetheris web server: PHP 8.2 with Apache.
# The database runs in its own MySQL container (see docker-compose.yml).
FROM php:8.2-apache

# PHP's MySQL driver (fileinfo and mbstring are already built in).
RUN docker-php-ext-install pdo_mysql

# Apache serves only the public/ folder. helpers/, pages/, storage/ and the rest stay out of reach.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/hide-version.conf \
 && a2enconf hide-version

# Production PHP settings (no error details on screen), with upload limits just above the app's 10 MB.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php = Off\nupload_max_filesize = 12M\npost_max_size = 13M\n' > "$PHP_INI_DIR/conf.d/aetheris.ini"

COPY . /var/www/html
RUN mkdir -p /var/www/html/storage/uploads && chown -R www-data:www-data /var/www/html/storage
