# Use a imagem oficial do PHP com Apache
FROM php:8.1-apache

# Instalar extensões necessárias do PHP (isso vai ser cacheado)
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite headers

# Copiar apenas arquivos de configuração primeiro (muda menos)
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY .htaccess /var/www/html/.htaccess

# Criar estrutura de diretórios
RUN mkdir -p /var/www/html/temp /var/www/html/api /var/www/html/classes /var/www/html/config /var/www/html/database

# Copiar código (isso vai invalidar cache quando código mudar)
COPY api/ /var/www/html/api/
COPY classes/ /var/www/html/classes/
COPY config/ /var/www/html/config/
COPY database/ /var/www/html/database/
COPY index.php /var/www/html/

# Ajustar permissões de uma vez
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/temp

# Expor porta 80
EXPOSE 80

# Comando padrão
CMD ["apache2-foreground"]
