import time
from typing import Dict, List, Optional
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from model import FraudModel

app = FastAPI(
    title="TransactIQ AI Fraud Detection Microservice",
    description="Machine Learning transaction anomaly detection engine powered by scikit-learn Isolation Forest.",
    version="1.0.0",
)

# Allow CORS for development and cross-origin dashboard requests
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

START_TIME = time.time()
fraud_model = FraudModel()


class FraudPredictionRequest(BaseModel):
    amount: float = Field(..., description="Transaction amount in minor units (e.g. kobo/cents)", example=150000)
    velocity_1m: int = Field(0, description="Payment attempts by customer/IP in rolling 60 seconds", example=1)
    velocity_1h: int = Field(0, description="Payment attempts by customer/IP in rolling 60 minutes", example=2)
    consecutive_failures: int = Field(0, description="Consecutive failed payments for customer profile", example=0)
    is_night_transaction: Optional[int] = Field(0, description="1 if transaction occurs between 00:00 and 05:00 UTC, else 0", example=0)
    card_bin_risk: Optional[float] = Field(0.1, description="Card issuer BIN historical risk index [0.0 - 1.0]", example=0.15)
    customer_tenure_days: Optional[int] = Field(30, description="Customer tenure in days", example=45)
    merchant_id: Optional[str] = Field(None, description="Optional merchant identifier")
    currency: Optional[str] = Field("NGN", description="Currency code", example="NGN")


class FraudPredictionResponse(BaseModel):
    status: str
    anomaly_score: float
    is_anomaly: bool
    risk_level: str
    anomaly_factors: List[str]
    model_version: str
    inference_latency_ms: float
    features_analyzed: Dict


@app.get("/")
def root():
    return {
        "service": "TransactIQ AI Fraud Detection Microservice",
        "version": "1.0.0",
        "status": "online",
        "docs_url": "/docs",
        "health_url": "/health",
    }


@app.get("/health")
def health():
    uptime_seconds = round(time.time() - START_TIME, 2)
    return {
        "status": "healthy",
        "service": "TransactIQ AI Fraud Detection Engine",
        "model_version": fraud_model.version,
        "algorithm": "Unsupervised Isolation Forest Anomaly Detection",
        "uptime_seconds": uptime_seconds,
    }


@app.post("/predict/fraud", response_model=FraudPredictionResponse)
def predict_fraud(req: FraudPredictionRequest):
    try:
        result = fraud_model.predict(req.dict())
        return {
            "status": "success",
            **result,
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"ML Inference error: {str(e)}")


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8001, reload=False)
