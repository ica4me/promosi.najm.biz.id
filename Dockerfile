# Menggunakan base image PHP 8.2 FPM (Lebih ringan dari Apache)
FROM php:8.2-fpm

# Install Python 3 dan venv
RUN apt-get update && apt-get install -y \
    python3 python3-pip python3-venv \
    && rm -rf /var/lib/apt/lists/*

# Konfigurasi PHP untuk upload 512MB
RUN echo "file_uploads = On\n" \
         "upload_max_filesize = 512M\n" \
         "post_max_size = 512M\n" \
         "max_file_uploads = 20\n" \
         "disable_functions =\n" > /usr/local/etc/php/conf.d/uploads.ini

# Setup Python Virtual Environment
RUN python3 -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"
RUN pip install --no-cache-dir --upgrade pip && \
    pip install --no-cache-dir curl_cffi beautifulsoup4

WORKDIR /var/www/html
COPY . /var/www/html/

# Setup folder dan permission
RUN mkdir -p temp_uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 777 temp_uploads

# Buka port 9000 untuk jalur komunikasi Nginx ke PHP-FPM
EXPOSE 9000
CMD ["php-fpm"]
