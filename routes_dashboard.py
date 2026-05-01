from flask import Blueprint, jsonify, request
from datetime import date, datetime, timedelta
import numpy as np
from db import get_db, q, q1, safe_float, safe_int, parse_date_params, ML_AVAILABLE

try:
    from sklearn.ensemble import GradientBoostingRegressor
    import shap
except ImportError:
    pass

dashboard_bp = Blueprint('dashboard', __name__)

# ══════════════════════════════════════════════════════════════════════════════
#  /api/dashboard  — main dashboard payload with SHAP forecast alert
# ══════════════════════════════════════════════════════════════════════════════
@dashboard_bp.route("/api/dashboard")
def dashboard():
    today = date.today()

    # ── Parse date filter (custom or default to this month) ───────────────────
    date_from, date_to = parse_date_params(request)

    # "Today" and "This Week" always reflect real calendar time,
    # but only show if they fall within the selected range
    week_start  = today - timedelta(days=today.weekday())
    month_start = today.replace(day=1)

    today_in_range     = date_from <= today     <= date_to
    week_start_in_range = date_from <= week_start
    week_end_in_range   = week_start <= date_to

    # ── 1. Revenue metric cards ───────────────────────────────────────────────
    # Today card: only count today if today is within the selected range
    if today_in_range:
        rev_today = q1("""
            SELECT COALESCE(SUM(grand_total),0) AS revenue,
                   COUNT(*) AS tx_count
            FROM transactions
            WHERE DATE(transaction_date) = %s
              AND transaction_status = 'OK'
        """, (today,))
    else:
        rev_today = {"revenue": 0, "tx_count": 0}

    # Week card: clamp to selected range
    effective_week_start = max(date_from, week_start)
    effective_week_end   = min(date_to, today)
    if effective_week_start <= effective_week_end:
        rev_week = q1("""
            SELECT COALESCE(SUM(grand_total),0) AS revenue,
                   COUNT(*) AS tx_count
            FROM transactions
            WHERE DATE(transaction_date) >= %s
              AND DATE(transaction_date) <= %s
              AND transaction_status = 'OK'
        """, (effective_week_start, effective_week_end))
    else:
        rev_week = {"revenue": 0, "tx_count": 0}

    # "This Month" card → shows total for selected range
    rev_month = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
    """, (date_from, date_to))

    # Prior equal-length period for MoM % change
    period_days = max((date_to - date_from).days, 1)
    prior_to    = date_from - timedelta(days=1)
    prior_from  = prior_to  - timedelta(days=period_days)
    rev_prior = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
    """, (prior_from, prior_to))

    prior_rev  = safe_float(rev_prior.get("revenue"))
    cur_rev    = safe_float(rev_month.get("revenue"))
    mom_change = round(((cur_rev - prior_rev) / prior_rev * 100), 1) if prior_rev else 0.0

    # ── 2. Sparkline — daily revenue for selected range ───────────────────────
    sparkline_rows = q("""
        SELECT DATE(transaction_date) AS day,
               COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
        GROUP BY DATE(transaction_date)
        ORDER BY day
    """, (date_from, date_to))

    day_map   = {str(r["day"]): r for r in sparkline_rows}
    sparkline = []
    total_days = (date_to - date_from).days + 1
    for i in range(total_days):
        d = str(date_from + timedelta(days=i))
        if d in day_map:
            sparkline.append({
                "date":    d,
                "revenue": safe_float(day_map[d]["revenue"]),
                "tx":      int(day_map[d]["tx_count"]),
            })
        else:
            sparkline.append({"date": d, "revenue": 0.0, "tx": 0})

    # ── 3. Top branches for selected range ────────────────────────────────────
    top_branches = q("""
        SELECT b.branch_name,
               COALESCE(SUM(t.grand_total),0) AS revenue,
               COUNT(t.transaction_id)         AS tx_count,
               COALESCE(AVG(t.grand_total),0)  AS avg_ticket
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE DATE(t.transaction_date) >= %s
          AND DATE(t.transaction_date) <= %s
          AND t.transaction_status = 'OK'
        GROUP BY b.branch_id, b.branch_name
        ORDER BY revenue DESC
        LIMIT 8
    """, (date_from, date_to))

    # ── 4. Payment method breakdown for selected range ────────────────────────
    payment_breakdown = q("""
        SELECT pm.method_name,
               COUNT(t.transaction_id)        AS tx_count,
               COALESCE(SUM(t.grand_total),0) AS revenue
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE DATE(t.transaction_date) >= %s
          AND DATE(t.transaction_date) <= %s
          AND t.transaction_status = 'OK'
        GROUP BY pm.method_id, pm.method_name
        ORDER BY revenue DESC
    """, (date_from, date_to))

    # ── 5. Tx count vs avg ticket trend for selected range ────────────────────
    tx_trend = q("""
        SELECT YEARWEEK(transaction_date, 1)  AS yw,
               MIN(DATE(transaction_date))    AS week_start,
               COUNT(*)                       AS tx_count,
               COALESCE(AVG(grand_total), 0)  AS avg_ticket
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
        GROUP BY yw
        ORDER BY yw
        LIMIT 52
    """, (date_from, date_to))

    # ── 6. SHAP forecast alert (always uses the sparkline data) ───────────────
    shap_result = _compute_shap_forecast(sparkline)

    # ── Assemble ──────────────────────────────────────────────────────────────
    payload = {
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "metrics": {
            "today":          {"revenue": safe_float(rev_today.get("revenue")),  "tx": int(rev_today.get("tx_count", 0))},
            "week":           {"revenue": safe_float(rev_week.get("revenue")),   "tx": int(rev_week.get("tx_count", 0))},
            "month":          {"revenue": safe_float(rev_month.get("revenue")),  "tx": int(rev_month.get("tx_count", 0))},
            "mom_change_pct": mom_change,
        },
        "sparkline":         sparkline,
        "top_branches": [
            {
                "name":       r["branch_name"],
                "revenue":    safe_float(r["revenue"]),
                "tx_count":   int(r["tx_count"]),
                "avg_ticket": safe_float(r["avg_ticket"]),
            }
            for r in top_branches
        ],
        "payment_breakdown": [
            {
                "method":   r["method_name"],
                "tx_count": int(r["tx_count"]),
                "revenue":  safe_float(r["revenue"]),
            }
            for r in payment_breakdown
        ],
        "tx_trend": [
            {
                "week":       str(r["week_start"]),
                "tx_count":   int(r["tx_count"]),
                "avg_ticket": safe_float(r["avg_ticket"]),
            }
            for r in tx_trend
        ],
        "forecast_alert": shap_result,
    }

    return jsonify(payload)

# ══════════════════════════════════════════════════════════════════════════════
#  SHAP Forecast Alert helpers
# ══════════════════════════════════════════════════════════════════════════════
def _compute_shap_forecast(sparkline: list) -> dict:
    revenues = [d["revenue"] for d in sparkline]
    dates    = [d["date"]    for d in sparkline]

    if not ML_AVAILABLE or len(revenues) < 14:
        return _simple_forecast_alert(revenues)

    try:
        X, y = [], []
        for i in range(7, len(revenues)):
            d           = datetime.strptime(dates[i], "%Y-%m-%d")
            lag1        = revenues[i-1]
            lag7        = revenues[i-7]
            roll_mean_7 = float(np.mean(revenues[i-7:i]))
            roll_std_7  = float(np.std(revenues[i-7:i]) + 1e-9)
            dow         = d.weekday()
            is_weekend  = int(dow >= 5)
            X.append([lag1, lag7, roll_mean_7, roll_std_7, dow, is_weekend])
            y.append(revenues[i])

        X = np.array(X, dtype=float)
        y = np.array(y, dtype=float)

        feature_names = ["lag_1d", "lag_7d", "rolling_mean_7d",
                         "rolling_std_7d", "day_of_week", "is_weekend"]

        model = GradientBoostingRegressor(
            n_estimators=60, max_depth=3, learning_rate=0.1, random_state=42
        )
        model.fit(X, y)

        last_date    = datetime.strptime(dates[-1], "%Y-%m-%d")
        tomorrow     = last_date + timedelta(days=1)
        roll_mean_t  = float(np.mean(revenues[-7:]))
        roll_std_t   = float(np.std(revenues[-7:]) + 1e-9)
        dow_t        = tomorrow.weekday()
        is_weekend_t = int(dow_t >= 5)

        X_pred    = np.array([[revenues[-1], revenues[-7], roll_mean_t,
                                roll_std_t, dow_t, is_weekend_t]])
        predicted = float(model.predict(X_pred)[0])

        explainer   = shap.TreeExplainer(model)
        shap_values = explainer.shap_values(X_pred)[0]

        shap_features = sorted(
            [{"feature": feature_names[i], "shap_value": round(float(shap_values[i]), 2)}
             for i in range(len(feature_names))],
            key=lambda x: abs(x["shap_value"]), reverse=True
        )

        avg_7d     = float(np.mean(revenues[-7:]))
        change_pct = ((predicted - avg_7d) / avg_7d * 100) if avg_7d else 0.0

        if change_pct >= 15:
            alert_type = "surge"
            message    = f"Revenue surge likely tomorrow — predicted ₱{predicted:,.0f} (+{change_pct:.1f}% vs 7-day avg)"
        elif change_pct <= -15:
            alert_type = "dip"
            message    = f"Revenue dip expected tomorrow — predicted ₱{predicted:,.0f} ({change_pct:.1f}% vs 7-day avg)"
        else:
            alert_type = "stable"
            message    = f"Revenue expected to be stable tomorrow — predicted ₱{predicted:,.0f} ({change_pct:+.1f}% vs 7-day avg)"

        return {
            "alert_type":    alert_type,
            "message":       message,
            "predicted":     round(predicted, 2),
            "avg_7d":        round(avg_7d, 2),
            "change_pct":    round(change_pct, 1),
            "tomorrow_date": tomorrow.strftime("%Y-%m-%d"),
            "shap_features": shap_features,
            "ml_powered":    True,
        }

    except Exception as e:
        return {**_simple_forecast_alert(revenues), "error": str(e)}


def _simple_forecast_alert(revenues: list) -> dict:
    if len(revenues) < 7:
        return {"alert_type": "stable", "message": "Insufficient data for forecast.", "ml_powered": False}

    avg7       = float(np.mean(revenues[-7:]))  if revenues else 0
    avg14      = float(np.mean(revenues[-14:])) if len(revenues) >= 14 else avg7
    change_pct = ((avg7 - avg14) / avg14 * 100) if avg14 else 0.0

    if change_pct >= 15:
        alert_type = "surge"
        msg = f"Trend indicates a revenue surge (7-day avg ₱{avg7:,.0f}, up {change_pct:.1f}% vs prior 7d)"
    elif change_pct <= -15:
        alert_type = "dip"
        msg = f"Trend indicates a revenue dip (7-day avg ₱{avg7:,.0f}, down {abs(change_pct):.1f}% vs prior 7d)"
    else:
        alert_type = "stable"
        msg = f"Revenue trend is stable (7-day avg ₱{avg7:,.0f})"

    return {
        "alert_type":    alert_type,
        "message":       msg,
        "predicted":     round(avg7, 2),
        "avg_7d":        round(avg7, 2),
        "change_pct":    round(change_pct, 1),
        "shap_features": [],
        "ml_powered":    False,
    }

# ══════════════════════════════════════════════════════════════════════════════
#  /api/health
# ══════════════════════════════════════════════════════════════════════════════
@dashboard_bp.route("/api/health")
def health():
    try:
        conn = get_db()
        conn.close()
        db_ok = True
    except:
        db_ok = False

    return jsonify({
        "status":       "ok" if db_ok else "db_error",
        "ml_available": ML_AVAILABLE,
        "timestamp":    datetime.now().isoformat(),
    })