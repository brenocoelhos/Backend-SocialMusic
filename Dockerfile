# Use a imagem oficial do PHP com Apache
FROM php:8.1-apache

# Instalar extensões necessárias do PHP
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Habilitar extensão curl (já vem habilitada por padrão no PHP 8.1)
RUN apt-get update && apt-get install -y \
    curl \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite do Apache (útil para URLs amigáveis)
RUN a2enmod rewrite

# Copiar código para o diretório padrão do Apache
COPY . /var/www/html/

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Criar diretório temp se não existir
RUN mkdir -p /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html/temp \
    && chmod -R 777 /var/www/html/temp

# Expor porta 80
EXPOSE 80

# Comando padrão (Apache em foreground)
CMD ["apache2-foreground"]
