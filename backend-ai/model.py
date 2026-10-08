import os
import time
import joblib
import numpy as np
from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler

MODEL_FILE = os.path.join(os.path.dirname(__file__), "model_artifact.joblib")

FEATURE_NAMES = [
    "amount",
    "velocity_1m",
    "velocity_1h",
    "consecutive_failures",
    "is_night_transaction",
    "card_bin_risk",
    "customer_tenure_days",
]


class FraudModel:
    def __init__(self):
        self.scaler = None
        self.clf = None
        self.version = "IsolationForest-v1.0"
        self.load_or_train()

    def load_or_train(self):
        """Load trained model artifact or train new calibrated model on startup."""
        if os.path.exists(MODEL_FILE):
            try:
                artifact = joblib.load(MODEL_FILE)
                self.scaler = artifact["scaler"]
                self.clf = artifact["clf"]
                self.version = artifact.get("version", self.version)
                print(f"[FraudModel] Loaded existing model artifact from {MODEL_FILE}")
                return
            except Exception as e:
                print(f"[FraudModel] Failed to load artifact, retraining: {e}")

        self.train_calibrated_model()

    def train_calibrated_model(self):
        """Generate calibrated synthetic fintech transactions and fit Isolation Forest."""
        print("[FraudModel] Training new calibrated IsolationForest baseline model...")
        np.random.seed(42)

        # 1. Normal Transactions (2,500 samples)
        n_normal = 2500
        amount_normal = np.random.exponential(scale=350_000, size=n_normal) + 50_000 # ₦500 to ₦15,000
        velocity_1m_normal = np.random.poisson(lam=0.2, size=n_normal)
        velocity_1h_normal = np.random.poisson(lam=1.2, size=n_normal)
        failures_normal = np.random.choice([0, 1], size=n_normal, p=[0.92, 0.08])
        night_normal = np.random.choice([0, 1], size=n_normal, p=[0.90, 0.10])
        bin_risk_normal = np.random.beta(a=1, b=8, size=n_normal)
        tenure_normal = np.random.uniform(low=14, high=365, size=n_normal)

        normal_data = np.column_stack([
            amount_normal,
            velocity_1m_normal,
            velocity_1h_normal,
            failures_normal,
            night_normal,
            bin_risk_normal,
            tenure_normal,
        ])

        # 2. Anomalous / Fraudulent Transactions (150 samples)
        n_anom = 150
        amount_anom = np.random.uniform(low=20_000_000, high=500_000_000, size=n_anom) # high ticket
        velocity_1m_anom = np.random.uniform(low=4, high=15, size=n_anom)
        velocity_1h_anom = np.random.uniform(low=8, high=40, size=n_anom)
        failures_anom = np.random.uniform(low=2, high=8, size=n_anom)
        night_anom = np.random.choice([0, 1], size=n_anom, p=[0.35, 0.65])
        bin_risk_anom = np.random.uniform(low=0.6, high=1.0, size=n_anom)
        tenure_anom = np.random.uniform(low=0, high=3, size=n_anom)

        anom_data = np.column_stack([
            amount_anom,
            velocity_1m_anom,
            velocity_1h_anom,
            failures_anom,
            night_anom,
            bin_risk_anom,
            tenure_anom,
        ])

        X = np.vstack([normal_data, anom_data])

        self.scaler = StandardScaler()
        X_scaled = self.scaler.fit_transform(X)

        self.clf = IsolationForest(
            n_estimators=100,
            contamination=0.06,
            random_state=42,
            n_jobs=-1
        )
        self.clf.fit(X_scaled)

        # Save artifact
        joblib.dump({
            "scaler": self.scaler,
            "clf": self.clf,
            "version": self.version,
            "trained_at": time.time(),
        }, MODEL_FILE)
        print(f"[FraudModel] Model trained and persisted to {MODEL_FILE}")

    def predict(self, features: dict) -> dict:
        """Score input transaction features for anomalous fraud patterns."""
        t_start = time.time()

        # Parse & sanitize feature vector
        amount = float(features.get("amount", 0.0))
        velocity_1m = float(features.get("velocity_1m", 0))
        velocity_1h = float(features.get("velocity_1h", 0))
        consecutive_failures = float(features.get("consecutive_failures", 0))
        is_night_transaction = 1.0 if features.get("is_night_transaction") in [1, True, "1", "true"] else 0.0
        card_bin_risk = float(features.get("card_bin_risk", 0.1))
        customer_tenure_days = float(features.get("customer_tenure_days", 30))

        raw_vector = np.array([[
            amount,
            velocity_1m,
            velocity_1h,
            consecutive_failures,
            is_night_transaction,
            card_bin_risk,
            customer_tenure_days,
        ]])

        scaled_vector = self.scaler.transform(raw_vector)

        # Isolation forest raw decision function score: lower = more anomalous
        raw_score = float(self.clf.score_samples(scaled_vector)[0])

        # Calibrate raw score to [0.0 - 1.0] anomaly score
        # Normal baseline typically scores between -0.45 and -0.35
        # Extreme anomaly scores below -0.65
        normalized_anomaly = float(np.clip(( -raw_score - 0.35 ) / 0.35, 0.0, 1.0))

        # Heuristic boost if explicit severe anomalies are detected
        anomaly_factors = []
        if amount >= 100_000_000: # ₦1,000,000+
            anomaly_factors.append("HIGH_TICKET_DEVIATION")
        if velocity_1m >= 5:
            anomaly_factors.append("SHORT_TERM_VELOCITY_SPIKE")
        if velocity_1h >= 10:
            anomaly_factors.append("SUSTAINED_HOURLY_FREQUENCY")
        if consecutive_failures >= 3:
            anomaly_factors.append("EXCESSIVE_DECLINE_HISTORY")
        if is_night_transaction == 1 and (velocity_1m >= 2 or amount >= 20_000_000):
            anomaly_factors.append("NOCTURNAL_BURST_ACTIVITY")
        if card_bin_risk >= 0.7:
            anomaly_factors.append("ELEVATED_ISSUER_BIN_RISK")
        if customer_tenure_days <= 1 and amount >= 10_000_000:
            anomaly_factors.append("HIGH_VALUE_NEW_ACCOUNT")

        # If multiple factors trigger, adjust minimum floor
        if len(anomaly_factors) >= 3:
            normalized_anomaly = max(normalized_anomaly, 0.85)
        elif len(anomaly_factors) >= 2:
            normalized_anomaly = max(normalized_anomaly, 0.70)
        elif len(anomaly_factors) == 1:
            normalized_anomaly = max(normalized_anomaly, 0.45)

        is_anomaly = normalized_anomaly >= 0.70

        if normalized_anomaly >= 0.85:
            risk_level = "CRITICAL"
        elif normalized_anomaly >= 0.70:
            risk_level = "HIGH"
        elif normalized_anomaly >= 0.40:
            risk_level = "MEDIUM"
        else:
            risk_level = "LOW"

        latency_ms = round((time.time() - t_start) * 1000, 2)

        return {
            "anomaly_score": round(normalized_anomaly, 4),
            "is_anomaly": is_anomaly,
            "risk_level": risk_level,
            "anomaly_factors": anomaly_factors,
            "model_version": self.version,
            "inference_latency_ms": latency_ms,
            "features_analyzed": {
                "amount": amount,
                "velocity_1m": int(velocity_1m),
                "velocity_1h": int(velocity_1h),
                "consecutive_failures": int(consecutive_failures),
                "is_night": bool(is_night_transaction),
                "bin_risk": round(card_bin_risk, 2),
                "tenure_days": int(customer_tenure_days),
            }
        }
