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

# Install pipeline runtime dependencies into the image so mapping tools work
# without manual pip installs after each container update.
COPY pipeline/requirements.txt /tmp/pipeline-requirements.txt
RUN python3 -m pip install --no-cache-dir --upgrade pip \
 && python3 -m pip install --no-cache-dir -r /tmp/pipeline-requirements.txt \
 && python3 -m pip install --no-cache-dir pymupdf \
 && rm -f /tmp/pipeline-requirements.txt
