# Dockerfile
FROM php:8.3-apache

RUN apt-get update -y
RUN apt-get upgrade -y

RUN apt update -y
RUN apt-get install -y libzip-dev
RUN apt install sudo -y
RUN apt-get install lsb-release -y
RUN apt-get install gnupg -y
RUN apt-get install wget -y
RUN apt-get install -y cron
RUN apt-get install vim -y
RUN apt-get install telnet -y
RUN apt-get install sendmail -y
RUN sudo apt install rsync -y
RUN sudo apt install net-tools -y



# Instala as extensões que você precisar
RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-install zip
RUN docker-php-ext-install sockets


RUN curl -sS https://getcomposer.org/installer -o composer-setup.php

RUN sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
RUN rm -fr composer-setup.php

# Habilita o módulo de reescrita do Apache (útil para frameworks PHP)
RUN a2enmod rewrite

COPY laravel/ /var/www/html/

WORKDIR /var/www/html


# Expõe a porta 80
EXPOSE 80
