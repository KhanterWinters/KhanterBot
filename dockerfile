FROM php:8.2-cli
WORKDIR /app
COPY . .
RUN apt-get update && apt-get install -y git unzip \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && composer install --no-dev --optimize-autoloader
RUN composer install --no-dev --optimize-autoloader \
 && composer dump-autoload --optimize
ENTRYPOINT ["php", "KhanterBot.php"]
