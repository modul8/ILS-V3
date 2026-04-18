FROM webdevops/php-apache:8.2

USER root

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    python3 \
    python3-venv \
    python3-pip \
    python3-dev \
    build-essential \
 && rm -rf /var/lib/apt/lists/*

