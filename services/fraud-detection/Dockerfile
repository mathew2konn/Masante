# fraud-detection-service (CDC_05). Image unique : deps ML + qualité (G3) exécutée au build,
# puis entraînement du modèle sur données SYNTHÉTIQUES (artefact + MLflow fichier baked-in).
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

# libgomp1 : runtime OpenMP requis par XGBoost.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt pyproject.toml ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY tests ./tests

# G3 : lint + tests unitaires purs (règles/features/fusion) et API en mode dégradé (sans modèle).
RUN ruff check app tests \
    && python -m pytest

# Entraîne le modèle sur données SYNTHÉTIQUES (écrit models/ + mlruns/). NON validé cliniquement.
RUN python -m app.ml.entrainement

RUN useradd -r -u 1001 app && chown -R app:app /app
USER app

EXPOSE 8090
ENTRYPOINT ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8090"]
