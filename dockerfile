FROM php:8.2-cli
WORKDIR /app

# Copiar todo el repo
COPY . .

# Instalar utilidades y Composer
RUN apt-get update \
 && apt-get install -y git unzip \
 && curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer \
 && composer install --no-dev --optimize-autoloader \
 && composer dump-autoload --optimize

# Decirle a Render que habrá un puerto
EXPOSE 10000

# Arrancar el servidor HTTP que “mantiene vivo” el contenedor
ENTRYPOINT ["php", "public/index.php"]
