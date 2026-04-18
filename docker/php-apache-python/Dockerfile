FROM webdevops/php-apache:8.2

USER root

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    python3 \
    python3-venv \
    python3-pip \
    python3-dev \
    build-essential \
    tesseract-ocr \
    libgl1 \
    libglib2.0-0 \
 && rm -rf /var/lib/apt/lists/*

# Install pipeline runtime dependencies into an isolated venv to avoid
# system-python pip policy issues on Debian/Ubuntu images.
COPY pipeline/requirements.txt /tmp/pipeline-requirements.txt
RUN python3 -m venv /opt/ils-v3-py \
 && /opt/ils-v3-py/bin/pip install --no-cache-dir --upgrade pip wheel setuptools \
 && /opt/ils-v3-py/bin/pip install --no-cache-dir -r /tmp/pipeline-requirements.txt \
 && /opt/ils-v3-py/bin/pip install --no-cache-dir pymupdf \
 && rm -f /tmp/pipeline-requirements.txt

ENV PATH="/opt/ils-v3-py/bin:${PATH}"
