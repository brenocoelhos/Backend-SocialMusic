# Use a imagem oficial do PHP com Apache
FROM php:8.1-apache

# 1. Instalar dependências do sistema (git, zip) e extensões PHP
RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite headers

# 2. Instalar o Composer (o gestor de pacotes do PHP)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 3. Definir o diretório de trabalho
WORKDIR /var/www/html

# 4. Copiar os ficheiros de configuração (igual ao seu original)
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY .htaccess /var/www/html/.htaccess

# 5. Copiar os ficheiros do Composer PRIMEIRO (para otimizar o cache do Docker)
# (Assumindo que composer.json e composer.lock estão na mesma pasta que o Dockerfile)
COPY composer.json composer.lock ./

# 6. Executar o Composer
# Isto irá criar a pasta 'vendor/' dentro da imagem com o AWS SDK
RUN composer install --no-dev --optimize-autoloader

# 7. Copiar o resto do código da sua aplicação (igual ao seu original)
COPY api/ /var/www/html/api/
COPY classes/ /var/www/html/classes/
COPY config/ /var/www/html/config/
COPY database/ /var/www/html/database/
COPY index.php /var/www/html/

# 8. Criar a pasta 'temp' e ajustar permissões (igual ao seu original)
RUN mkdir -p /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/temp

# Expor porta 80
EXPOSE 80

# Comando padrão
CMD ["apache2-foreground"]