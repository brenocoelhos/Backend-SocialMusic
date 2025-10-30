# Use a imagem oficial do PHP com Apache
FROM php:8.1-apache

# Instalar extensões necessárias do PHP em uma única camada
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Habilitar mod_rewrite do Apache
RUN a2enmod rewrite headers

# Copiar configuração do Apache
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Copiar código para o diretório padrão do Apache
COPY . /var/www/html/

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/temp \
    && chmod -R 777 /var/www/html/temp

# Expor porta 80
EXPOSE 80

# Configurar variável de ambiente para porta
ENV PORT=80

# Comando padrão (Apache em foreground)
CMD ["apache2-foreground"]
