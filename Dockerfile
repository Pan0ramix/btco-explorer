FROM php:8.4-apache
RUN a2enmod rewrite headers \
    && printf '%s\n' '<Directory /var/www/html>' 'AllowOverride All' 'Options -Indexes' '</Directory>' > /etc/apache2/conf-available/explorer.conf \
    && a2enconf explorer \
    && sed -i "s/^Listen 80$/Listen 127.0.0.1:8080/" /etc/apache2/ports.conf \
    && sed -i "s/<VirtualHost \*:80>/<VirtualHost 127.0.0.1:8080>/" /etc/apache2/sites-available/000-default.conf
COPY index.php config.example.php .htaccess manifest.webmanifest offline.html robots.txt sw.js /var/www/html/
COPY assets /var/www/html/assets
COPY lib /var/www/html/lib
COPY views /var/www/html/views
RUN cp /var/www/html/config.example.php /var/www/html/config.php \
    && install -d -o www-data -g www-data /var/www/html/db
