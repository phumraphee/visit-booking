# ลองใช้ในเครื่อง / Try it locally:  docker compose up  →  http://localhost:8080 (จะขึ้นหน้าติดตั้ง / opens the setup wizard)
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype && docker-php-ext-install gd \
 && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/* \
 && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
COPY . /var/www/html/
# ข้อมูล (config + ฐานข้อมูล) อยู่นอกโฟลเดอร์เว็บ / data lives outside the web root
RUN mkdir -p /var/www/visit-booking-data && chown -R www-data:www-data /var/www/visit-booking-data /var/www/html/cache \
 && chown www-data:www-data /var/www
VOLUME /var/www/visit-booking-data
