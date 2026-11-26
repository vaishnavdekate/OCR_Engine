FROM python:3.14-slim
WORKDIR /app
COPY pyproject.toml ./
COPY receipt_ocr ./receipt_ocr
RUN pip install --no-cache-dir . && useradd --create-home receipt
USER receipt
EXPOSE 8000
CMD ["python", "-m", "uvicorn", "receipt_ocr.api:app", "--host", "0.0.0.0", "--port", "8000"]

