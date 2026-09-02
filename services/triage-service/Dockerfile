# triage-service (CDC_05 §5.5.4/§7.2/§8). P10c-3-i : XGBoost+SHAP+MLflow, donc libgomp1 (runtime
# OpenMP requis par XGBoost — précédent exact et déjà résolu dans fraud-detection/Dockerfile).
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt pyproject.toml ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY tests ./tests

# G3 : lint + tests unitaires (contrat, dégradation).
RUN ruff check app tests \
    && python -m pytest

RUN useradd -r -u 1001 app && chown -R app:app /app
USER app

EXPOSE 8095
ENTRYPOINT ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8095"]
