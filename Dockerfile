FROM php:8.2-apache

# Bat mod_rewrite va AllowOverride cho .htaccess
RUN a2enmod rewrite headers \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Cai dat cac tien ich mo rong can thiet cho PHP (GD xu ly anh, zip)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

# Toi uu cau hinh PHP cho bai thi va upload de thi
RUN echo "upload_max_filesize = 64M\npost_max_size = 64M\nmemory_limit = 256M\nmax_execution_time = 300" > /usr/local/etc/php/conf.d/uploads.ini

# Thu muc web goc
WORKDIR /var/www/html

# Copy ma nguon vao container
COPY . /var/www/html/

# Cap toan quyen ghi file cho web server de luu file JSON va uploads
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html

EXPOSE 80

CMD sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf && sed -i "s/:80/:${PORT:-80}/" /etc/apache2/sites-available/000-default.conf && apache2-foreground
