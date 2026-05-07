from flask import Blueprint, jsonify, request, session
from datetime import date, datetime, timedelta
import numpy as np
from db import get_db, q, q1, safe_float, safe_int, parse_date_params, ML_AVAILABLE

try:
    from sklearn.ensemble import GradientBoostingRegressor, RandomForestClassifier
    from sklearn.preprocessing import StandardScaler
    import xgboost as xgb
    import shap
    ML_XGB_AVAILABLE = True
except ImportError:
    ML_XGB_AVAILABLE = False

try:
    import tensorflow as tf
    ML_LSTM_AVAILABLE = True
except ImportError:
    ML_LSTM_AVAILABLE = False

dashboard_bp = Blueprint('dashboard', __name__)

# ══════════════════════════════════════════════════════════════════════════════
#  /api/dashboard  — main dashboard payload with hybrid ML ensemble
# ══════════════════════════════════════════════════════════════════════════════
@dashboard_bp.route("/api/dashboard")
def dashboard():
    today = date.today()
    ml_enabled = request.args.get('ml_enabled', 'true').lower() != 'false'

    date_from, date_to = parse_date_params(request)

    week_start  = today - timedelta(days=today.weekday())
    today_in_range = date_from <= today <= date_to

    # ── 1. Revenue metric cards ───────────────────────────────────────────────
    if today_in_range:
        rev_today = q1("""
            SELECT COALESCE(SUM(grand_total),0) AS revenue,
                   COUNT(*) AS tx_count
            FROM transactions
            WHERE DATE(transaction_date) = %s AND transaction_status = 'OK'
        """, (today,))
    else:
        rev_today = {"revenue": 0, "tx_count": 0}

    effective_week_start = max(date_from, week_start)
    effective_week_end   = min(date_to, today)
    if effective_week_start <= effective_week_end:
        rev_week = q1("""
            SELECT COALESCE(SUM(grand_total),0) AS revenue, COUNT(*) AS tx_count
            FROM transactions
            WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
              AND transaction_status = 'OK'
        """, (effective_week_start, effective_week_end))
    else:
        rev_week = {"revenue": 0, "tx_count": 0}

    rev_month = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue, COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
    """, (date_from, date_to))

    period_days = max((date_to - date_from).days, 1)
    prior_to    = date_from - timedelta(days=1)
    prior_from  = prior_to  - timedelta(days=period_days)
    rev_prior = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue
        FROM transactions
        WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
    """, (prior_from, prior_to))

    prior_rev  = safe_float(rev_prior.get("revenue"))
    cur_rev    = safe_float(rev_month.get("revenue"))
    mom_change = round(((cur_rev - prior_rev) / prior_rev * 100), 1) if prior_rev else 0.0

    # ── 2. Sparkline ─────────────────────────────────────────────────────────
    sparkline_rows = q("""
        SELECT DATE(transaction_date) AS day,
               COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
        GROUP BY DATE(transaction_date) ORDER BY day
    """, (date_from, date_to))

    day_map   = {str(r["day"]): r for r in sparkline_rows}
    sparkline = []
    total_days = (date_to - date_from).days + 1
    for i in range(total_days):
        d = str(date_from + timedelta(days=i))
        if d in day_map:
            sparkline.append({"date": d, "revenue": safe_float(day_map[d]["revenue"]), "tx": int(day_map[d]["tx_count"])})
        else:
            sparkline.append({"date": d, "revenue": 0.0, "tx": 0})

    # ── 3. Top branches ───────────────────────────────────────────────────────
    top_branches = q("""
        SELECT b.branch_name,
               COALESCE(SUM(t.grand_total),0) AS revenue,
               COUNT(t.transaction_id) AS tx_count,
               COALESCE(AVG(t.grand_total),0) AS avg_ticket
        FROM transactions t JOIN branches b ON t.branch_id = b.branch_id
        WHERE DATE(t.transaction_date) >= %s AND DATE(t.transaction_date) <= %s
          AND t.transaction_status = 'OK'
        GROUP BY b.branch_id, b.branch_name ORDER BY revenue DESC LIMIT 8
    """, (date_from, date_to))

    # ── 4. Payment breakdown ──────────────────────────────────────────────────
    payment_breakdown = q("""
        SELECT pm.method_name,
               COUNT(t.transaction_id) AS tx_count,
               COALESCE(SUM(t.grand_total),0) AS revenue
        FROM transactions t JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE DATE(t.transaction_date) >= %s AND DATE(t.transaction_date) <= %s
          AND t.transaction_status = 'OK'
        GROUP BY pm.method_id, pm.method_name ORDER BY revenue DESC
    """, (date_from, date_to))

    # ── 5. Tx trend ───────────────────────────────────────────────────────────
    tx_trend = q("""
        SELECT YEARWEEK(transaction_date, 1) AS yw,
               MIN(DATE(transaction_date)) AS week_start,
               COUNT(*) AS tx_count,
               COALESCE(AVG(grand_total), 0) AS avg_ticket
        FROM transactions
        WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
        GROUP BY yw ORDER BY yw LIMIT 52
    """, (date_from, date_to))

    # ── 6. ML Ensemble ───────────────────────────────────────────────────────
    forecast_alert  = _compute_shap_forecast(sparkline)   # legacy GBR (always on)
    lstm_forecast   = {}
    xgb_anomaly     = {}
    rf_confidence   = {}
    hybrid_alert    = {}
    anomaly_kpi     = {"today": False, "week": False, "month": False, "avg": False}

    if ml_enabled:
        lstm_forecast = _compute_lstm_forecast(sparkline, date_to)
        xgb_anomaly   = _compute_xgb_anomaly(date_from, date_to)
        anomaly_kpi   = _anomaly_kpi_flags()
        rf_confidence = _compute_rf_confidence(lstm_forecast, xgb_anomaly, date_from, date_to)
        hybrid_alert  = _build_hybrid_alert(lstm_forecast, xgb_anomaly, rf_confidence)
        _log_alert(hybrid_alert)

    # ── 7. Model status ───────────────────────────────────────────────────────
    try:
        model_status_rows = q("""
            SELECT model_name, model_type, task_type, last_trained_at,
                   accuracy, f1_score, is_active,
                   key_metric, key_metric_value
            FROM ml_model_status ORDER BY model_id
        """, ())
        model_status = [
            {
                "model_name":       r["model_name"],
                "model_type":       r.get("model_type") or "",
                "task_type":        r.get("task_type") or "",
                "last_trained_at":  r["last_trained_at"].isoformat() if r["last_trained_at"] else None,
                "accuracy":         safe_float(r["accuracy"]),
                "f1_score":         safe_float(r["f1_score"]),
                "is_active":        bool(r["is_active"]),
                "key_metric":       r.get("key_metric") or "",
                "key_metric_value": safe_float(r.get("key_metric_value")),
            }
            for r in model_status_rows
        ]
    except Exception:
        # Fallback if columns don't exist yet
        model_status_rows = q("SELECT model_name, last_trained_at, accuracy, f1_score, is_active FROM ml_model_status ORDER BY model_id", ())
        model_status = [
            {
                "model_name":      r["model_name"],
                "model_type":      "",
                "task_type":       "",
                "last_trained_at": r["last_trained_at"].isoformat() if r["last_trained_at"] else None,
                "accuracy":        safe_float(r["accuracy"]),
                "f1_score":        safe_float(r["f1_score"]),
                "is_active":       bool(r["is_active"]),
                "key_metric":      "",
                "key_metric_value": None,
            }
            for r in model_status_rows
        ]

    payload = {
        "date_range":        {"from": str(date_from), "to": str(date_to)},
        "metrics": {
            "today":          {"revenue": safe_float(rev_today.get("revenue")),  "tx": int(rev_today.get("tx_count", 0))},
            "week":           {"revenue": safe_float(rev_week.get("revenue")),   "tx": int(rev_week.get("tx_count", 0))},
            "month":          {"revenue": safe_float(rev_month.get("revenue")),  "tx": int(rev_month.get("tx_count", 0))},
            "mom_change_pct": mom_change,
        },
        "sparkline":          sparkline,
        "top_branches":       [{"name": r["branch_name"], "revenue": safe_float(r["revenue"]), "tx_count": int(r["tx_count"]), "avg_ticket": safe_float(r["avg_ticket"])} for r in top_branches],
        "payment_breakdown":  [{"method": r["method_name"], "tx_count": int(r["tx_count"]), "revenue": safe_float(r["revenue"])} for r in payment_breakdown],
        "tx_trend":           [{"week": str(r["week_start"]), "tx_count": int(r["tx_count"]), "avg_ticket": safe_float(r["avg_ticket"])} for r in tx_trend],
        "forecast_alert":     forecast_alert,
        "lstm_forecast":      lstm_forecast,
        "xgb_anomaly":        xgb_anomaly,
        "rf_confidence":      rf_confidence,
        "hybrid_alert":       hybrid_alert,
        "anomaly_kpi":        anomaly_kpi,
        "model_status":       model_status,
        "ml_enabled":         ml_enabled,
    }
    return jsonify(payload)


# ══════════════════════════════════════════════════════════════════════════════
#  LSTM Forecast
# ══════════════════════════════════════════════════════════════════════════════
def _compute_lstm_forecast(sparkline: list, predict_from: date = None) -> dict:
    import os
    from datetime import datetime, timedelta

    # Fetch history anchored to predict_from, not CURDATE()
    # Go back 90 days from the filter end date so seed data is always available
    anchor      = predict_from if predict_from else date.today()
    history_start = anchor - timedelta(days=90)

    try:
        history_rows = q("""
            SELECT DATE(transaction_date) AS day,
                   COALESCE(SUM(grand_total), 0) AS revenue
            FROM transactions
            WHERE DATE(transaction_date) >= %s
              AND DATE(transaction_date) <= %s
              AND transaction_status = 'OK'
            GROUP BY DATE(transaction_date)
            ORDER BY day
        """, (str(history_start), str(anchor)))
    except Exception as e:
        return {"available": False, "reason": f"DB error: {e}"}

    if len(history_rows) < 14:
        return {"available": False, "reason": f"Need at least 14 days of data (got {len(history_rows)})"}

    revenues = [float(r["revenue"]) for r in history_rows]
    dates    = [str(r["day"])       for r in history_rows]

    std_hist  = float(np.std(revenues[-14:]))
    roll      = list(revenues[-7:])
    last_date = datetime.strptime(dates[-1], "%Y-%m-%d")

    # ── Try trained LSTM model first ──────────────────────────────────────
    MODEL_DIR   = os.path.join(os.path.dirname(__file__), "ml_models")
    lstm_path   = os.path.join(MODEL_DIR, "lstm_model.h5")
    scaler_path = os.path.join(MODEL_DIR, "lstm_scaler.pkl")

    if ML_LSTM_AVAILABLE and os.path.exists(lstm_path) and os.path.exists(scaler_path):
        try:
            import pickle
            import tensorflow as tf
            model  = tf.keras.models.load_model(lstm_path, compile=False)
            with open(scaler_path, "rb") as f:
                scaler = pickle.load(f)

            seq_len    = model.input_shape[1]
            n_features = model.input_shape[2]

            seed_arr    = np.array(revenues[-seq_len:]).reshape(-1, 1)
            seed_scaled = scaler.transform(seed_arr)
            seq         = list(seed_scaled.flatten())

            preds = []
            for step in range(7):
                nxt  = last_date + timedelta(days=step + 1)
                x_in = np.array(seq[-seq_len:]).reshape(1, seq_len, n_features)
                p_sc = float(model.predict(x_in, verbose=0)[0][0])
                p    = float(scaler.inverse_transform([[p_sc]])[0][0])
                p    = max(p, 0.0)
                preds.append({
                    "date":      nxt.strftime("%Y-%m-%d"),
                    "predicted": round(p, 2),
                    "lower":     round(max(p - std_hist, 0), 2),
                    "upper":     round(p + std_hist, 2),
                })
                seq.append(p_sc)

            avg7       = float(np.mean(revenues[-7:]))
            change_pct = ((preds[0]["predicted"] - avg7) / avg7 * 100) if avg7 else 0.0
            _touch_model_status("lstm")
            return {
                "available":   True,
                "next_7_days": preds,
                "avg_7d":      round(avg7, 2),
                "change_pct":  round(change_pct, 1),
                "std_band":    round(std_hist, 2),
                "model_type":  "lstm",
            }
        except Exception:
            pass  # fall through to GBR proxy

    # ── GBR proxy fallback ────────────────────────────────────────────────
    try:
        X, y = [], []
        for i in range(7, len(revenues)):
            d = datetime.strptime(dates[i], "%Y-%m-%d")
            X.append([
                revenues[i-1], revenues[i-7],
                float(np.mean(revenues[i-7:i])),
                float(np.std(revenues[i-7:i]) + 1e-9),
                d.weekday(), int(d.weekday() >= 5)
            ])
            y.append(revenues[i])

        from sklearn.ensemble import GradientBoostingRegressor
        gbr = GradientBoostingRegressor(
            n_estimators=150, max_depth=3,
            learning_rate=0.06, subsample=0.8,
            random_state=42
        )
        gbr.fit(np.array(X), np.array(y))

        preds = []
        for step in range(7):
            nxt  = last_date + timedelta(days=step + 1)
            feat = np.array([[
                roll[-1], roll[0],
                float(np.mean(roll)),
                float(np.std(roll) + 1e-9),
                nxt.weekday(), int(nxt.weekday() >= 5)
            ]])
            p = max(float(gbr.predict(feat)[0]), 0.0)
            preds.append({
                "date":      nxt.strftime("%Y-%m-%d"),
                "predicted": round(p, 2),
                "lower":     round(max(p - std_hist, 0), 2),
                "upper":     round(p + std_hist, 2),
            })
            roll = roll[1:] + [p]

        avg7       = float(np.mean(revenues[-7:]))
        change_pct = ((preds[0]["predicted"] - avg7) / avg7 * 100) if avg7 else 0.0
        _touch_model_status("lstm")
        return {
            "available":   True,
            "next_7_days": preds,
            "avg_7d":      round(avg7, 2),
            "change_pct":  round(change_pct, 1),
            "std_band":    round(std_hist, 2),
            "model_type":  "gbr_proxy",
        }
    except Exception as e:
        return {"available": False, "reason": str(e)}


# ══════════════════════════════════════════════════════════════════════════════
#  XGBoost Anomaly Scoring
# ══════════════════════════════════════════════════════════════════════════════
def _compute_xgb_anomaly(date_from, date_to) -> dict:
    try:
        rows = q("""
            SELECT t.transaction_id, t.grand_total, t.branch_id,
                   t.overall_payment_method_id AS payment_method_id,
                   COALESCE(t.discount_type_id, 0) AS discount_type_id,
                   COALESCE(t.total_treatment, 0) AS total_treatment,
                   COALESCE(t.total_product, 0)   AS total_product,
                   DAYOFWEEK(t.transaction_date) - 1 AS day_of_week,
                   HOUR(t.transaction_date) AS hour_of_day
            FROM transactions t
            WHERE DATE(t.transaction_date) >= %s AND DATE(t.transaction_date) <= %s
              AND t.transaction_status = 'OK'
        """, (date_from, date_to))

        if len(rows) < 20:
            return {"available": False, "anomaly_rate": 0.0, "flagged_count": 0}

        import pandas as pd
        from sklearn.ensemble import IsolationForest

        df = pd.DataFrame(rows)
        features = ['branch_id','payment_method_id','discount_type_id',
                    'total_treatment','total_product','day_of_week','hour_of_day','grand_total']
        X = df[features].fillna(0).values

        # IsolationForest as XGBoost proxy for anomaly scoring (no labels needed)
        iso = IsolationForest(contamination=0.05, random_state=42, n_estimators=100)
        iso.fit(X)
        scores_raw = iso.score_samples(X)
        # Normalise to [0, 1] where 1 = most anomalous
        s_min, s_max = scores_raw.min(), scores_raw.max()
        anomaly_scores = 1 - (scores_raw - s_min) / (s_max - s_min + 1e-9)

        df['anomaly_score'] = anomaly_scores
        df['anomaly_flag']  = (anomaly_scores > 0.7).astype(int)

        # Write back to DB in batch
        conn = get_db()
        cur  = conn.cursor()
        for _, row in df.iterrows():
            cur.execute(
                "UPDATE transactions SET anomaly_score=%s, anomaly_flag=%s WHERE transaction_id=%s",
                (float(row['anomaly_score']), int(row['anomaly_flag']), int(row['transaction_id']))
            )
        conn.commit()
        cur.close()

        flagged_count = int(df['anomaly_flag'].sum())
        anomaly_rate  = round(flagged_count / len(df), 4)

        _touch_model_status("xgboost")

        return {
            "available":    True,
            "anomaly_rate": anomaly_rate,
            "flagged_count": flagged_count,
            "total_scored": len(df),
        }
    except Exception as e:
        return {"available": False, "anomaly_rate": 0.0, "flagged_count": 0, "error": str(e)}


def _anomaly_kpi_flags() -> dict:
    """Return which KPI cards have anomalous transactions in the last 24h."""
    try:
        since = datetime.now() - timedelta(hours=24)
        rows = q("""
            SELECT COUNT(*) AS cnt FROM transactions
            WHERE transaction_date >= %s AND anomaly_score > 0.7
        """, (since,))
        has_anomaly = int(rows[0]["cnt"]) > 0 if rows else False
        # Simplified: flag all cards if any anomaly exists in last 24h
        return {"today": has_anomaly, "week": has_anomaly, "month": has_anomaly, "avg": has_anomaly}
    except:
        return {"today": False, "week": False, "month": False, "avg": False}


# ══════════════════════════════════════════════════════════════════════════════
#  Random Forest Confidence
# ══════════════════════════════════════════════════════════════════════════════
def _compute_rf_confidence(lstm_result: dict, xgb_result: dict, date_from, date_to) -> dict:
    try:
        from sklearn.ensemble import RandomForestClassifier
        import math

        lstm_delta   = lstm_result.get("change_pct", 0.0)
        anomaly_rate = xgb_result.get("anomaly_rate", 0.0)

        branch_count = q1("""
            SELECT COUNT(*) AS cnt FROM transactions
            WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
              AND transaction_status = 'OK'
        """, (date_from, date_to))
        tx_count_7d = int(branch_count.get("cnt", 0))

        payment_rows = q("""
            SELECT overall_payment_method_id, COUNT(*) AS cnt
            FROM transactions
            WHERE DATE(transaction_date) >= %s AND DATE(transaction_date) <= %s
              AND transaction_status = 'OK'
            GROUP BY overall_payment_method_id
        """, (date_from, date_to))
        total_pm = sum(r["cnt"] for r in payment_rows) or 1
        entropy  = -sum((r["cnt"]/total_pm) * math.log(r["cnt"]/total_pm + 1e-9) for r in payment_rows)

        # Synthetic training data for RF (rule-based ground truth)
        import numpy as np
        np.random.seed(42)
        n = 300
        X_train = np.column_stack([
            np.random.uniform(-40, 40, n),
            np.random.uniform(0, 1, n),
            np.random.randint(10, 5000, n),
            np.random.uniform(0, 3, n),
        ])
        def label(row):
            d, a = abs(row[0]), row[1]
            if d > 20 and a > 0.3: return 2   # HIGH
            if d > 10 or a > 0.2:  return 1   # MEDIUM
            return 0                            # LOW
        y_train = np.array([label(r) for r in X_train])

        clf = RandomForestClassifier(n_estimators=80, random_state=42, max_depth=5)
        clf.fit(X_train, y_train)

        X_pred = np.array([[lstm_delta, anomaly_rate, tx_count_7d, entropy]])
        proba  = clf.predict_proba(X_pred)[0]
        pred   = int(clf.predict(X_pred)[0])
        labels = ['LOW', 'MEDIUM', 'HIGH']

        _touch_model_status("random_forest")

        return {
            "available":   True,
            "label":       labels[pred],
            "probability": round(float(proba[pred]), 4),
            "proba_all":   {labels[i]: round(float(proba[i]), 4) for i in range(len(labels))},
        }
    except Exception as e:
        return {"available": False, "label": "LOW", "probability": 0.5, "error": str(e)}


# ══════════════════════════════════════════════════════════════════════════════
#  Hybrid Ensemble Alert
# ══════════════════════════════════════════════════════════════════════════════
def _build_hybrid_alert(lstm: dict, xgb: dict, rf: dict) -> dict:
    lstm_ok  = lstm.get("available", False)
    xgb_ok   = xgb.get("available", False)
    rf_label = rf.get("label", "LOW")
    rf_prob  = rf.get("probability", 0.5)

    change_pct   = lstm.get("change_pct", 0.0) if lstm_ok else 0.0
    anomaly_rate = xgb.get("anomaly_rate", 0.0) if xgb_ok else 0.0

    if change_pct >= 15:
        direction = "surge"
    elif change_pct <= -15:
        direction = "dip"
    else:
        direction = "stable"

    # Confidence override logic
    if lstm_ok and xgb_ok and abs(change_pct) >= 15 and anomaly_rate > 0.3:
        confidence = "HIGH"
        models_used = "lstm,xgboost,random_forest"
    elif lstm_ok or xgb_ok:
        confidence = rf_label if rf.get("available") else "MEDIUM"
        models_used = ",".join(filter(None, [
            "lstm" if lstm_ok else "",
            "xgboost" if xgb_ok else "",
            "random_forest" if rf.get("available") else "",
        ]))
    else:
        confidence  = "LOW"
        models_used = "heuristic"

    magnitude = abs(round(change_pct, 1))
    predicted = lstm.get("next_7_days", [{}])[0].get("predicted", 0) if lstm_ok else 0

    return {
        "direction":    direction,
        "confidence":   confidence,
        "probability":  rf_prob,
        "magnitude_pct": magnitude,
        "predicted_tomorrow": predicted,
        "models_used":  models_used,
        "anomaly_rate": anomaly_rate,
    }


def _log_alert(hybrid: dict):
    if not hybrid:
        return
    try:
        conn = get_db()
        cur  = conn.cursor()
        cur.execute("""
            INSERT INTO alert_logs (confidence_label, confidence_prob, models_used, alert_type, predicted_value)
            VALUES (%s, %s, %s, %s, %s)
        """, (
            hybrid.get("confidence", "LOW"),
            hybrid.get("probability", 0.0),
            hybrid.get("models_used", ""),
            hybrid.get("direction", "stable"),
            hybrid.get("predicted_tomorrow", 0),
        ))
        conn.commit()
        cur.close()
    except:
        pass


def _touch_model_status(name: str):
    try:
        conn = get_db()
        cur  = conn.cursor()
        cur.execute("UPDATE ml_model_status SET last_trained_at=%s WHERE model_name=%s", (datetime.now(), name))
        conn.commit()
        cur.close()
    except:
        pass


# ══════════════════════════════════════════════════════════════════════════════
#  Legacy GBR SHAP Forecast (keep for backward compat)
# ══════════════════════════════════════════════════════════════════════════════
def _compute_shap_forecast(sparkline: list) -> dict:
    revenues = [d["revenue"] for d in sparkline]
    dates    = [d["date"]    for d in sparkline]

    if not ML_AVAILABLE or len(revenues) < 14:
        return _simple_forecast_alert(revenues)

    try:
        X, y = [], []
        for i in range(7, len(revenues)):
            d = datetime.strptime(dates[i], "%Y-%m-%d")
            X.append([revenues[i-1], revenues[i-7],
                      float(np.mean(revenues[i-7:i])),
                      float(np.std(revenues[i-7:i]) + 1e-9),
                      d.weekday(), int(d.weekday() >= 5)])
            y.append(revenues[i])

        X = np.array(X, dtype=float)
        y = np.array(y, dtype=float)
        feature_names = ["lag_1d","lag_7d","rolling_mean_7d","rolling_std_7d","day_of_week","is_weekend"]

        from sklearn.ensemble import GradientBoostingRegressor
        model = GradientBoostingRegressor(n_estimators=60, max_depth=3, learning_rate=0.1, random_state=42)
        model.fit(X, y)

        last_date    = datetime.strptime(dates[-1], "%Y-%m-%d")
        tomorrow     = last_date + timedelta(days=1)
        roll_mean_t  = float(np.mean(revenues[-7:]))
        roll_std_t   = float(np.std(revenues[-7:]) + 1e-9)
        X_pred    = np.array([[revenues[-1], revenues[-7], roll_mean_t,
                                roll_std_t, tomorrow.weekday(), int(tomorrow.weekday() >= 5)]])
        predicted = float(model.predict(X_pred)[0])

        import shap
        explainer   = shap.TreeExplainer(model)
        shap_values = explainer.shap_values(X_pred)[0]
        shap_features = sorted(
            [{"feature": feature_names[i], "shap_value": round(float(shap_values[i]), 2)} for i in range(len(feature_names))],
            key=lambda x: abs(x["shap_value"]), reverse=True
        )

        avg_7d     = float(np.mean(revenues[-7:]))
        change_pct = ((predicted - avg_7d) / avg_7d * 100) if avg_7d else 0.0

        if change_pct >= 15:   alert_type, message = "surge", f"Revenue surge likely tomorrow — predicted ₱{predicted:,.0f} (+{change_pct:.1f}% vs 7-day avg)"
        elif change_pct <= -15: alert_type, message = "dip",   f"Revenue dip expected tomorrow — predicted ₱{predicted:,.0f} ({change_pct:.1f}% vs 7-day avg)"
        else:                   alert_type, message = "stable", f"Revenue expected to be stable tomorrow — predicted ₱{predicted:,.0f} ({change_pct:+.1f}% vs 7-day avg)"

        return {"alert_type": alert_type, "message": message, "predicted": round(predicted, 2),
                "avg_7d": round(avg_7d, 2), "change_pct": round(change_pct, 1),
                "tomorrow_date": tomorrow.strftime("%Y-%m-%d"), "shap_features": shap_features, "ml_powered": True}

    except Exception as e:
        return {**_simple_forecast_alert(revenues), "error": str(e)}


def _simple_forecast_alert(revenues: list) -> dict:
    if len(revenues) < 7:
        return {"alert_type": "stable", "message": "Insufficient data for forecast.", "ml_powered": False}
    avg7       = float(np.mean(revenues[-7:]))
    avg14      = float(np.mean(revenues[-14:])) if len(revenues) >= 14 else avg7
    change_pct = ((avg7 - avg14) / avg14 * 100) if avg14 else 0.0
    if change_pct >= 15:    alert_type, msg = "surge", f"Trend indicates a revenue surge (7-day avg ₱{avg7:,.0f}, up {change_pct:.1f}% vs prior 7d)"
    elif change_pct <= -15: alert_type, msg = "dip",   f"Trend indicates a revenue dip (7-day avg ₱{avg7:,.0f}, down {abs(change_pct):.1f}% vs prior 7d)"
    else:                   alert_type, msg = "stable", f"Revenue trend is stable (7-day avg ₱{avg7:,.0f})"
    return {"alert_type": alert_type, "message": msg, "predicted": round(avg7, 2),
            "avg_7d": round(avg7, 2), "change_pct": round(change_pct, 1), "shap_features": [], "ml_powered": False}


# ══════════════════════════════════════════════════════════════════════════════
#  /api/health
# ══════════════════════════════════════════════════════════════════════════════
@dashboard_bp.route("/api/health")
def health():
    try:
        conn = get_db(); conn.close(); db_ok = True
    except: db_ok = False
    return jsonify({"status": "ok" if db_ok else "db_error", "ml_available": ML_AVAILABLE, "timestamp": datetime.now().isoformat()})