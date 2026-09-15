FROM ubuntu:20.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Prague

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-php7.4 \
        php7.4 \
        php7.4-cli \
        php7.4-common \
        php7.4-mysql \
        php7.4-zip \
        php7.4-curl \
        php7.4-mbstring \
        php7.4-xml \
        php7.4-gd \
        curl \
        git \
        unzip \
        ca-certificates \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Povolení .htaccess / mod_rewrite
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    Options Indexes FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/ev-dashboard.conf \
    && a2enconf ev-dashboard

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Apache při běhu v Dockeru jinak vypisuje warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apachectl", "-D", "FOREGROUND"]