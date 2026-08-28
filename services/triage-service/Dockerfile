# triage-service (CDC_05 §5.5.4, socle P10c-2-i). Image légère : aucune dépendance ML (F5) — pas
# de libgomp1, pas d'entraînement au build. Deps + qualité (G3) exécutées au build.
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

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
