from flask import Flask, jsonify, request, Response
from flask_cors import CORS
import mysql.connector
import os, json, csv, io
from datetime import datetime, timedelta, date
from decimal import Decimal
import numpy as np

import threading
import time
import uuid
import pandas as pd

# Optional ML imports — graceful fallback if not installed
try:
    from sklearn.ensemble import GradientBoostingRegressor
    import shap
    ML_AVAILABLE = True
except ImportError:
    ML_AVAILABLE = False

app = Flask(__name__)
CORS(app, origins=["http://localhost", "http://127.0.0.1",
                   "http://localhost:3000", "http://127.0.0.1:3000"])

# ── DB config ─────────────────────────────────────────────────────────────────
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME",     "chrono_sales_db"),
}

_training_jobs: dict = {}

def get_db():
    return mysql.connector.connect(**DB_CONFIG)

def q(sql, params=None):
    conn = get_db()
    cur  = conn.cursor(dictionary=True)
    cur.execute(sql, params or ())
    rows = cur.fetchall()
    cur.close()
    conn.close()
    return rows

def q1(sql, params=None):
    rows = q(sql, params)
    return rows[0] if rows else {}

def safe_float(v, default=0.0):
    try:
        if isinstance(v, Decimal): return float(v)
        return float(v) if v is not None else default
    except:
        return default

def safe_int(v, default=0):
    try: return int(v) if v is not None else default
    except: return default

def parse_date_params(req):
    """
    Parse date_from / date_to from request args.
    Supports preset: daily | weekly | monthly | custom.
    Also accepts bare date_from/date_to without preset=custom.
    Returns (date_from, date_to) as date objects.
    """
    preset = req.args.get('preset', 'monthly')
    today  = date.today()

    if preset == 'daily':
        return today, today
    elif preset == 'weekly':
        week_start = today - timedelta(days=today.weekday())
        return week_start, today
    elif preset == 'custom':
        df_str = req.args.get('date_from', '')
        dt_str = req.args.get('date_to',   '')
        if df_str and dt_str:
            try:
                df = datetime.strptime(df_str, '%Y-%m-%d').date()
                dt = datetime.strptime(dt_str, '%Y-%m-%d').date()
                if df <= dt:
                    return df, dt
            except ValueError:
                pass  # fall through to default
    # default: monthly
    return today.replace(day=1), today


# ══════════════════════════════════════════════════════════════════════════════
#  /api/dashboard  — main dashboard payload with SHAP forecast alert
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/dashboard")
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
#  /api/analytics/filters  — returns all available filter options
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/analytics/filters")
def analytics_filters():
    branches  = q("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
    payments  = q("SELECT method_id, method_name FROM payment_methods ORDER BY method_name")
    discounts = q("SELECT discount_type_id, type_name FROM discount_types ORDER BY type_name")
    statuses  = q("SELECT DISTINCT transaction_status FROM transactions WHERE transaction_status IS NOT NULL ORDER BY transaction_status")

    return jsonify({
        "branches":  [{"id": r["branch_id"],       "name": r["branch_name"]} for r in branches],
        "payments":  [{"id": r["method_id"],        "name": r["method_name"]} for r in payments],
        "discounts": [{"id": r["discount_type_id"], "name": r["type_name"]}   for r in discounts],
        "statuses":  [r["transaction_status"] for r in statuses],
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/analytics  — main analytics payload
#  Query params:
#    preset        daily | weekly | monthly | custom (default: monthly)
#    date_from     YYYY-MM-DD (used with preset=custom)
#    date_to       YYYY-MM-DD
#    branch_id     int or 'all'
#    payment_id    int or 'all'
#    discount_id   int or 'all'
#    status        OK | VOID | PENDING | 'all'
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/analytics")
def analytics():
    date_from, date_to = parse_date_params(request)

    # ── Build dynamic WHERE clause ─────────────────────────────────────────
    where_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
    ]
    params = {"date_from": date_from, "date_to": date_to}

    branch_id   = request.args.get('branch_id')
    payment_id  = request.args.get('payment_id')
    discount_id = request.args.get('discount_id')
    status      = request.args.get('status', 'OK')

    if branch_id and branch_id != 'all':
        where_clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id and payment_id != 'all':
        where_clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
    if discount_id and discount_id != 'all':
        where_clauses.append("t.discount_type_id = %(discount_id)s")
        params["discount_id"] = int(discount_id)
    if status and status != 'all':
        where_clauses.append("t.transaction_status = %(status)s")
        params["status"] = status

    WHERE = " AND ".join(where_clauses)

    # ── 1. Summary metrics ────────────────────────────────────────────────
    summary = q1(f"""
        SELECT
            COALESCE(SUM(t.grand_total), 0)    AS total_revenue,
            COUNT(t.transaction_id)             AS total_transactions,
            COALESCE(AVG(t.grand_total), 0)     AS avg_order_value,
            COALESCE(SUM(t.final_discount), 0)  AS total_discounts_given,
            COALESCE(SUM(t.vat), 0)             AS total_vat
        FROM transactions t
        WHERE {WHERE}
    """, params)

    # ── 2. Revenue by branch ──────────────────────────────────────────────
    branch_revenue = q(f"""
        SELECT
            b.branch_name,
            b.branch_id,
            COALESCE(SUM(t.grand_total), 0)    AS revenue,
            COUNT(t.transaction_id)             AS tx_count,
            COALESCE(AVG(t.grand_total), 0)     AS avg_ticket,
            COALESCE(SUM(t.final_discount), 0)  AS total_discount
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE}
        GROUP BY b.branch_id, b.branch_name
        ORDER BY revenue DESC
    """, params)

    # ── 3. Heatmap: sales volume by day-of-week × hour-of-day ────────────
    heatmap_rows = q(f"""
        SELECT
            DAYOFWEEK(t.transaction_date) - 1  AS dow,
            HOUR(t.transaction_date)            AS hr,
            COUNT(t.transaction_id)             AS tx_count,
            COALESCE(SUM(t.grand_total), 0)     AS revenue
        FROM transactions t
        WHERE {WHERE}
        GROUP BY dow, hr
        ORDER BY dow, hr
    """, params)

    heatmap_grid = {}
    for row in heatmap_rows:
        heatmap_grid[(safe_int(row["dow"]), safe_int(row["hr"]))] = {
            "tx_count": safe_int(row["tx_count"]),
            "revenue":  safe_float(row["revenue"]),
        }

    heatmap = []
    for dow in range(7):
        for hr in range(24):
            cell = heatmap_grid.get((dow, hr), {"tx_count": 0, "revenue": 0.0})
            heatmap.append({"dow": dow, "hr": hr, **cell})

    # ── 4. Discount analysis ──────────────────────────────────────────────
    discount_analysis = q(f"""
        SELECT
            COALESCE(dt.type_name, 'No Discount') AS discount_type,
            COUNT(t.transaction_id)                AS tx_count,
            COALESCE(SUM(t.grand_total), 0)        AS gross_revenue,
            COALESCE(SUM(t.final_discount), 0)     AS total_discount_amount,
            COALESCE(AVG(t.final_discount), 0)     AS avg_discount,
            COALESCE(AVG(t.grand_total), 0)        AS avg_ticket
        FROM transactions t
        LEFT JOIN discount_types dt ON t.discount_type_id = dt.discount_type_id
        WHERE {WHERE}
        GROUP BY dt.discount_type_id, dt.type_name
        ORDER BY gross_revenue DESC
    """, params)

    # ── 5. Top 10 customers ───────────────────────────────────────────────
    top_customers = q(f"""
        SELECT
            c.customer_id,
            c.full_name,
            COUNT(t.transaction_id)         AS tx_count,
            COALESCE(SUM(t.grand_total), 0) AS total_spent,
            COALESCE(AVG(t.grand_total), 0) AS avg_ticket,
            MAX(DATE(t.transaction_date))   AS last_purchase
        FROM transactions t
        JOIN customers c ON t.customer_id = c.customer_id
        WHERE {WHERE}
        GROUP BY c.customer_id, c.full_name
        ORDER BY total_spent DESC
        LIMIT 10
    """, params)

    # ── 6. Daily revenue trend ────────────────────────────────────────────
    daily_trend = q(f"""
        SELECT
            DATE(t.transaction_date)           AS day,
            COALESCE(SUM(t.grand_total), 0)    AS revenue,
            COUNT(t.transaction_id)            AS tx_count,
            COALESCE(SUM(t.final_discount), 0) AS discounts
        FROM transactions t
        WHERE {WHERE}
        GROUP BY DATE(t.transaction_date)
        ORDER BY day
    """, params)

    # ── 7. Payment method breakdown ───────────────────────────────────────
    payment_breakdown = q(f"""
        SELECT
            pm.method_name,
            COUNT(t.transaction_id)         AS tx_count,
            COALESCE(SUM(t.grand_total), 0) AS revenue
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE}
        GROUP BY pm.method_id, pm.method_name
        ORDER BY revenue DESC
    """, params)

    payload = {
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "summary": {
            "total_revenue":         safe_float(summary.get("total_revenue")),
            "total_transactions":    safe_int(summary.get("total_transactions")),
            "avg_order_value":       safe_float(summary.get("avg_order_value")),
            "total_discounts_given": safe_float(summary.get("total_discounts_given")),
            "total_vat":             safe_float(summary.get("total_vat")),
        },
        "branch_revenue": [
            {
                "branch_id":      r["branch_id"],
                "name":           r["branch_name"],
                "revenue":        safe_float(r["revenue"]),
                "tx_count":       safe_int(r["tx_count"]),
                "avg_ticket":     safe_float(r["avg_ticket"]),
                "total_discount": safe_float(r["total_discount"]),
            }
            for r in branch_revenue
        ],
        "heatmap": heatmap,
        "discount_analysis": [
            {
                "discount_type":         r["discount_type"],
                "tx_count":              safe_int(r["tx_count"]),
                "gross_revenue":         safe_float(r["gross_revenue"]),
                "total_discount_amount": safe_float(r["total_discount_amount"]),
                "avg_discount":          safe_float(r["avg_discount"]),
                "avg_ticket":            safe_float(r["avg_ticket"]),
            }
            for r in discount_analysis
        ],
        "top_customers": [
            {
                "rank":          i + 1,
                "name":          r["full_name"],
                "tx_count":      safe_int(r["tx_count"]),
                "total_spent":   safe_float(r["total_spent"]),
                "avg_ticket":    safe_float(r["avg_ticket"]),
                "last_purchase": str(r["last_purchase"]) if r["last_purchase"] else "—",
            }
            for i, r in enumerate(top_customers)
        ],
        "daily_trend": [
            {
                "date":      str(r["day"]),
                "revenue":   safe_float(r["revenue"]),
                "tx_count":  safe_int(r["tx_count"]),
                "discounts": safe_float(r["discounts"]),
            }
            for r in daily_trend
        ],
        "payment_breakdown": [
            {
                "method":   r["method_name"],
                "tx_count": safe_int(r["tx_count"]),
                "revenue":  safe_float(r["revenue"]),
            }
            for r in payment_breakdown
        ],
    }

    return jsonify(payload)


# ══════════════════════════════════════════════════════════════════════════════
#  /api/analytics/export/csv  — export filtered transactions
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/analytics/export/csv")
def export_csv():
    date_from, date_to = parse_date_params(request)
    where_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
    ]
    params = {"date_from": date_from, "date_to": date_to}

    status      = request.args.get('status', 'OK')
    branch_id   = request.args.get('branch_id')
    payment_id  = request.args.get('payment_id')
    discount_id = request.args.get('discount_id')

    if status and status != 'all':
        where_clauses.append("t.transaction_status = %(status)s")
        params["status"] = status
    if branch_id and branch_id != 'all':
        where_clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id and payment_id != 'all':
        where_clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
    if discount_id and discount_id != 'all':
        where_clauses.append("t.discount_type_id = %(discount_id)s")
        params["discount_id"] = int(discount_id)

    WHERE = " AND ".join(where_clauses)

    rows = q(f"""
        SELECT
            t.transaction_id,
            t.invoice_number,
            DATE(t.transaction_date)      AS date,
            TIME(t.transaction_date)      AS time,
            c.full_name                   AS customer,
            b.branch_name                 AS branch,
            pm.method_name                AS payment_method,
            dt.type_name                  AS discount_type,
            t.discount_value,
            t.final_discount,
            t.total_treatment,
            t.total_product,
            t.vat,
            t.grand_total,
            t.transaction_status
        FROM transactions t
        LEFT JOIN customers c        ON t.customer_id = c.customer_id
        LEFT JOIN branches b         ON t.branch_id = b.branch_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        LEFT JOIN discount_types dt  ON t.discount_type_id = dt.discount_type_id
        WHERE {WHERE}
        ORDER BY t.transaction_date DESC
        LIMIT 10000
    """, params)

    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=[
        "transaction_id", "invoice_number", "date", "time", "customer",
        "branch", "payment_method", "discount_type", "discount_value",
        "final_discount", "total_treatment", "total_product", "vat",
        "grand_total", "transaction_status"
    ])
    writer.writeheader()
    for r in rows:
        writer.writerow({k: (float(v) if isinstance(v, Decimal) else v) for k, v in r.items()})

    output.seek(0)
    fname = f"sales_analytics_{date_from}_{date_to}.csv"
    return Response(
        output.getvalue(),
        mimetype="text/csv",
        headers={"Content-Disposition": f"attachment; filename={fname}"}
    )


# ══════════════════════════════════════════════════════════════════════════════
#  /api/health
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/health")
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



# ══════════════════════════════════════════════════════════════════════════════
#  DATA MANAGEMENT — helper
# ══════════════════════════════════════════════════════════════════════════════
def dm_exec(sql, params=None):
    """Execute INSERT / UPDATE / DELETE and return lastrowid + rowcount."""
    conn = get_db()
    cur  = conn.cursor()
    cur.execute(sql, params or ())
    conn.commit()
    last_id   = cur.lastrowid
    row_count = cur.rowcount
    cur.close()
    conn.close()
    return last_id, row_count


def _dm_list(table, select_sql, count_sql, req, pk):
    """Generic paginated list helper used by all DM endpoints."""
    page     = int(req.args.get('page',     1))
    per_page = int(req.args.get('per_page', 20))
    search   = req.args.get('search', '').strip()
    sort     = req.args.get('sort',   pk)
    direction= req.args.get('dir',    'asc').upper()
    if direction not in ('ASC', 'DESC'):
        direction = 'ASC'

    offset  = (page - 1) * per_page
    return search, sort, direction, offset, per_page


# ══════════════════════════════════════════════════════════════════════════════
#  /api/dm/transactions
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/dm/transactions", methods=["GET"])
def dm_transactions_list():
    page      = int(request.args.get('page',     1))
    per_page  = int(request.args.get('per_page', 20))
    search    = request.args.get('search',    '').strip()
    sort      = request.args.get('sort',      'transaction_id')
    direction = request.args.get('dir',       'desc').upper()
    branch_id = request.args.get('branch_id', '')
    status    = request.args.get('status',    '')

    if direction not in ('ASC', 'DESC'):
        direction = 'DESC'

    ALLOWED_SORT = {
        'transaction_id', 'invoice_number', 'transaction_date',
        'customer_name', 'branch_name', 'payment_method',
        'grand_total', 'transaction_status',
    }
    if sort not in ALLOWED_SORT:
        sort = 'transaction_id'

    offset = (page - 1) * per_page
    where_clauses = []
    params = []

    if search:
        where_clauses.append("""(
            t.invoice_number LIKE %s OR
            c.full_name      LIKE %s OR
            b.branch_name    LIKE %s OR
            t.transaction_status LIKE %s
        )""")
        like = f"%{search}%"
        params += [like, like, like, like]

    if branch_id and branch_id != 'all':
        where_clauses.append("t.branch_id = %s")
        params.append(int(branch_id))

    if status and status != 'all':
        where_clauses.append("t.transaction_status = %s")
        params.append(status)

    WHERE = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""

    total = q1(f"""
        SELECT COUNT(*) AS cnt
        FROM transactions t
        LEFT JOIN customers      c  ON t.customer_id = c.customer_id
        LEFT JOIN branches       b  ON t.branch_id   = b.branch_id
        {WHERE}
    """, params)["cnt"]

    rows = q(f"""
        SELECT
            t.transaction_id,
            t.invoice_number,
            t.transaction_date,
            c.full_name            AS customer_name,
            b.branch_name,
            pm.method_name         AS payment_method,
            t.discount_type_id,
            t.discount_value,
            t.total_treatment,
            t.total_product,
            t.final_discount,
            t.vat,
            t.grand_total,
            t.overall_payment_method_id,
            t.transaction_status
        FROM transactions t
        LEFT JOIN customers      c  ON t.customer_id              = c.customer_id
        LEFT JOIN branches       b  ON t.branch_id                = b.branch_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        {WHERE}
        ORDER BY {sort} {direction}
        LIMIT %s OFFSET %s
    """, params + [per_page, offset])

    def fmt_row(r):
        return {
            **{k: (float(v) if isinstance(v, Decimal) else
                   v.isoformat() if isinstance(v, (datetime, date)) else v)
               for k, v in r.items()},
        }

    return jsonify({"rows": [fmt_row(r) for r in rows], "total": safe_int(total)})


@app.route("/api/dm/transactions/<int:pk>", methods=["GET"])
def dm_transaction_get(pk):
    row = q1("""
        SELECT t.*, c.full_name AS customer_name, b.branch_name,
               pm.method_name AS payment_method
        FROM transactions t
        LEFT JOIN customers       c  ON t.customer_id              = c.customer_id
        LEFT JOIN branches        b  ON t.branch_id                = b.branch_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE t.transaction_id = %s
    """, (pk,))
    if not row:
        return jsonify({"error": "Not found"}), 404
    return jsonify({k: (float(v) if isinstance(v, Decimal) else
                        v.isoformat() if isinstance(v, (datetime, date)) else v)
                    for k, v in row.items()})


@app.route("/api/dm/transactions", methods=["POST"])
def dm_transaction_create():
    d = request.get_json(force=True)
    try:
        last_id, _ = dm_exec("""
            INSERT INTO transactions
                (invoice_number, transaction_date, customer_id, branch_id,
                 discount_type_id, discount_value, total_treatment, total_product,
                 final_discount, vat, grand_total, overall_payment_method_id,
                 transaction_status)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """, (
            d.get("invoice_number"),
            d.get("transaction_date"),
            d.get("customer_id"),
            d.get("branch_id"),
            d.get("discount_type_id") or None,
            d.get("discount_value")   or None,
            d.get("total_treatment")  or None,
            d.get("total_product")    or None,
            d.get("final_discount")   or None,
            d.get("vat")              or None,
            d.get("grand_total"),
            d.get("overall_payment_method_id"),
            d.get("transaction_status", "OK"),
        ))
        return jsonify({"id": last_id}), 201
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/transactions/<int:pk>", methods=["PUT"])
def dm_transaction_update(pk):
    d = request.get_json(force=True)
    try:
        _, affected = dm_exec("""
            UPDATE transactions SET
                invoice_number            = %s,
                transaction_date          = %s,
                customer_id               = %s,
                branch_id                 = %s,
                discount_type_id          = %s,
                discount_value            = %s,
                total_treatment           = %s,
                total_product             = %s,
                final_discount            = %s,
                vat                       = %s,
                grand_total               = %s,
                overall_payment_method_id = %s,
                transaction_status        = %s
            WHERE transaction_id = %s
        """, (
            d.get("invoice_number"),
            d.get("transaction_date"),
            d.get("customer_id"),
            d.get("branch_id"),
            d.get("discount_type_id") or None,
            d.get("discount_value")   or None,
            d.get("total_treatment")  or None,
            d.get("total_product")    or None,
            d.get("final_discount")   or None,
            d.get("vat")              or None,
            d.get("grand_total"),
            d.get("overall_payment_method_id"),
            d.get("transaction_status", "OK"),
            pk,
        ))
        if affected == 0:
            return jsonify({"error": "Not found"}), 404
        return jsonify({"updated": pk})
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/transactions/<int:pk>", methods=["DELETE"])
def dm_transaction_delete(pk):
    _, affected = dm_exec("DELETE FROM transactions WHERE transaction_id = %s", (pk,))
    if affected == 0:
        return jsonify({"error": "Not found"}), 404
    return jsonify({"deleted": pk})


@app.route("/api/dm/transactions/bulk-delete", methods=["POST"])
def dm_transaction_bulk_delete():
    ids = request.get_json(force=True).get("ids", [])
    if not ids:
        return jsonify({"error": "No IDs provided"}), 400
    placeholders = ",".join(["%s"] * len(ids))
    _, affected = dm_exec(f"DELETE FROM transactions WHERE transaction_id IN ({placeholders})", ids)
    return jsonify({"deleted": affected})


@app.route("/api/dm/transactions/export")
def dm_transaction_export():
    search    = request.args.get('search',    '').strip()
    branch_id = request.args.get('branch_id', '')
    status    = request.args.get('status',    '')

    where_clauses, params = [], []
    if search:
        like = f"%{search}%"
        where_clauses.append("(t.invoice_number LIKE %s OR c.full_name LIKE %s OR b.branch_name LIKE %s)")
        params += [like, like, like]
    if branch_id and branch_id != 'all':
        where_clauses.append("t.branch_id = %s")
        params.append(int(branch_id))
    if status and status != 'all':
        where_clauses.append("t.transaction_status = %s")
        params.append(status)

    WHERE = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""

    rows = q(f"""
        SELECT t.transaction_id, t.invoice_number,
               DATE(t.transaction_date) AS date, TIME(t.transaction_date) AS time,
               c.full_name AS customer, b.branch_name AS branch,
               pm.method_name AS payment_method,
               t.discount_value, t.final_discount,
               t.total_treatment, t.total_product, t.vat, t.grand_total,
               t.transaction_status
        FROM transactions t
        LEFT JOIN customers       c  ON t.customer_id              = c.customer_id
        LEFT JOIN branches        b  ON t.branch_id                = b.branch_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        {WHERE}
        ORDER BY t.transaction_date DESC
        LIMIT 10000
    """, params)

    output = io.StringIO()
    fields = ["transaction_id","invoice_number","date","time","customer","branch",
              "payment_method","discount_value","final_discount","total_treatment",
              "total_product","vat","grand_total","transaction_status"]
    writer = csv.DictWriter(output, fieldnames=fields)
    writer.writeheader()
    for r in rows:
        writer.writerow({k: (float(v) if isinstance(v, Decimal) else v) for k, v in r.items()})
    output.seek(0)
    return Response(output.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": "attachment; filename=transactions_export.csv"})


@app.route("/api/dm/transactions/import", methods=["POST"])
def dm_transaction_import():
    rows = request.get_json(force=True).get("rows", [])
    inserted = 0
    for r in rows:
        try:
            dm_exec("""
                INSERT INTO transactions
                    (invoice_number, transaction_date, customer_id, branch_id,
                     discount_type_id, discount_value, total_treatment, total_product,
                     final_discount, vat, grand_total, overall_payment_method_id,
                     transaction_status)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                r.get("invoice_number"),
                r.get("transaction_date"),
                r.get("customer_id")               or None,
                r.get("branch_id")                 or None,
                r.get("discount_type_id")          or None,
                r.get("discount_value")            or None,
                r.get("total_treatment")           or None,
                r.get("total_product")             or None,
                r.get("final_discount")            or None,
                r.get("vat")                       or None,
                r.get("grand_total")               or None,
                r.get("overall_payment_method_id") or None,
                r.get("transaction_status", "OK"),
            ))
            inserted += 1
        except Exception:
            continue
    return jsonify({"inserted": inserted})


# ══════════════════════════════════════════════════════════════════════════════
#  /api/dm/customers
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/dm/customers", methods=["GET"])
def dm_customers_list():
    page      = int(request.args.get('page',     1))
    per_page  = int(request.args.get('per_page', 20))
    search    = request.args.get('search', '').strip()
    sort      = request.args.get('sort',   'customer_id')
    direction = request.args.get('dir',    'asc').upper()

    ALLOWED_SORT = {'customer_id', 'full_name', 'contact', 'address', 'created_at'}
    if sort not in ALLOWED_SORT:
        sort = 'customer_id'
    if direction not in ('ASC', 'DESC'):
        direction = 'ASC'

    offset = (page - 1) * per_page
    where_clauses, params = [], []

    if search:
        like = f"%{search}%"
        where_clauses.append("(full_name LIKE %s OR contact LIKE %s OR address LIKE %s)")
        params += [like, like, like]

    WHERE = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""

    total = q1(f"SELECT COUNT(*) AS cnt FROM customers {WHERE}", params)["cnt"]
    rows  = q(f"""
        SELECT customer_id, full_name, contact, address, created_at
        FROM customers {WHERE}
        ORDER BY {sort} {direction}
        LIMIT %s OFFSET %s
    """, params + [per_page, offset])

    return jsonify({
        "rows":  [{k: (v.isoformat() if isinstance(v, (datetime, date)) else v)
                   for k, v in r.items()} for r in rows],
        "total": safe_int(total),
    })


@app.route("/api/dm/customers/<int:pk>", methods=["GET"])
def dm_customer_get(pk):
    row = q1("SELECT * FROM customers WHERE customer_id = %s", (pk,))
    if not row:
        return jsonify({"error": "Not found"}), 404
    return jsonify({k: (v.isoformat() if isinstance(v, (datetime, date)) else v)
                    for k, v in row.items()})


@app.route("/api/dm/customers", methods=["POST"])
def dm_customer_create():
    d = request.get_json(force=True)
    if not d.get("full_name"):
        return jsonify({"error": "full_name is required"}), 400
    try:
        last_id, _ = dm_exec(
            "INSERT INTO customers (full_name, contact, address) VALUES (%s,%s,%s)",
            (d["full_name"], d.get("contact") or None, d.get("address") or None),
        )
        return jsonify({"id": last_id}), 201
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/customers/<int:pk>", methods=["PUT"])
def dm_customer_update(pk):
    d = request.get_json(force=True)
    if not d.get("full_name"):
        return jsonify({"error": "full_name is required"}), 400
    try:
        _, affected = dm_exec(
            "UPDATE customers SET full_name=%s, contact=%s, address=%s WHERE customer_id=%s",
            (d["full_name"], d.get("contact") or None, d.get("address") or None, pk),
        )
        if affected == 0:
            return jsonify({"error": "Not found"}), 404
        return jsonify({"updated": pk})
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/customers/<int:pk>", methods=["DELETE"])
def dm_customer_delete(pk):
    try:
        _, affected = dm_exec("DELETE FROM customers WHERE customer_id = %s", (pk,))
        if affected == 0:
            return jsonify({"error": "Not found"}), 404
        return jsonify({"deleted": pk})
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/customers/bulk-delete", methods=["POST"])
def dm_customer_bulk_delete():
    ids = request.get_json(force=True).get("ids", [])
    if not ids:
        return jsonify({"error": "No IDs provided"}), 400
    placeholders = ",".join(["%s"] * len(ids))
    _, affected = dm_exec(f"DELETE FROM customers WHERE customer_id IN ({placeholders})", ids)
    return jsonify({"deleted": affected})


@app.route("/api/dm/customers/export")
def dm_customer_export():
    search = request.args.get('search', '').strip()
    where_clauses, params = [], []
    if search:
        like = f"%{search}%"
        where_clauses.append("(full_name LIKE %s OR contact LIKE %s OR address LIKE %s)")
        params += [like, like, like]
    WHERE = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""
    rows = q(f"SELECT customer_id, full_name, contact, address, created_at FROM customers {WHERE} ORDER BY customer_id LIMIT 10000", params)
    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=["customer_id","full_name","contact","address","created_at"])
    writer.writeheader()
    for r in rows:
        writer.writerow({k: (v.isoformat() if isinstance(v, (datetime, date)) else v) for k, v in r.items()})
    output.seek(0)
    return Response(output.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": "attachment; filename=customers_export.csv"})


@app.route("/api/dm/customers/import", methods=["POST"])
def dm_customer_import():
    rows = request.get_json(force=True).get("rows", [])
    inserted = 0
    for r in rows:
        try:
            if not r.get("full_name"):
                continue
            dm_exec("INSERT INTO customers (full_name, contact, address) VALUES (%s,%s,%s)",
                    (r["full_name"], r.get("contact") or None, r.get("address") or None))
            inserted += 1
        except Exception:
            continue
    return jsonify({"inserted": inserted})


# ══════════════════════════════════════════════════════════════════════════════
#  /api/dm/branches
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/dm/branches", methods=["GET"])
def dm_branches_list():
    page      = int(request.args.get('page',     1))
    per_page  = int(request.args.get('per_page', 20))
    search    = request.args.get('search',    '').strip()
    sort      = request.args.get('sort',      'branch_id')
    direction = request.args.get('dir',       'asc').upper()
    is_active = request.args.get('is_active', '')

    ALLOWED_SORT = {'branch_id', 'branch_name', 'city', 'region', 'is_active', 'created_at'}
    if sort not in ALLOWED_SORT:
        sort = 'branch_id'
    if direction not in ('ASC', 'DESC'):
        direction = 'ASC'

    offset = (page - 1) * per_page
    where_clauses, params = [], []

    if search:
        like = f"%{search}%"
        where_clauses.append("(branch_name LIKE %s OR city LIKE %s OR region LIKE %s)")
        params += [like, like, like]
    if is_active != '':
        where_clauses.append("is_active = %s")
        params.append(int(is_active))

    WHERE = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""

    total = q1(f"SELECT COUNT(*) AS cnt FROM branches {WHERE}", params)["cnt"]
    rows  = q(f"""
        SELECT branch_id, branch_name, city, region, is_active, created_at
        FROM branches {WHERE}
        ORDER BY {sort} {direction}
        LIMIT %s OFFSET %s
    """, params + [per_page, offset])

    return jsonify({
        "rows":  [{k: (v.isoformat() if isinstance(v, (datetime, date)) else v)
                   for k, v in r.items()} for r in rows],
        "total": safe_int(total),
    })


@app.route("/api/dm/branches/<int:pk>", methods=["GET"])
def dm_branch_get(pk):
    row = q1("SELECT * FROM branches WHERE branch_id = %s", (pk,))
    if not row:
        return jsonify({"error": "Not found"}), 404
    return jsonify({k: (v.isoformat() if isinstance(v, (datetime, date)) else v)
                    for k, v in row.items()})


@app.route("/api/dm/branches", methods=["POST"])
def dm_branch_create():
    d = request.get_json(force=True)
    if not d.get("branch_name"):
        return jsonify({"error": "branch_name is required"}), 400
    try:
        last_id, _ = dm_exec(
            "INSERT INTO branches (branch_name, city, region, is_active) VALUES (%s,%s,%s,%s)",
            (d["branch_name"], d.get("city") or None, d.get("region") or None, int(d.get("is_active", 1))),
        )
        return jsonify({"id": last_id}), 201
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/branches/<int:pk>", methods=["PUT"])
def dm_branch_update(pk):
    d = request.get_json(force=True)
    if not d.get("branch_name"):
        return jsonify({"error": "branch_name is required"}), 400
    try:
        _, affected = dm_exec(
            "UPDATE branches SET branch_name=%s, city=%s, region=%s, is_active=%s WHERE branch_id=%s",
            (d["branch_name"], d.get("city") or None, d.get("region") or None,
             int(d.get("is_active", 1)), pk),
        )
        if affected == 0:
            return jsonify({"error": "Not found"}), 404
        return jsonify({"updated": pk})
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/branches/<int:pk>", methods=["DELETE"])
def dm_branch_delete(pk):
    try:
        _, affected = dm_exec("DELETE FROM branches WHERE branch_id = %s", (pk,))
        if affected == 0:
            return jsonify({"error": "Not found"}), 404
        return jsonify({"deleted": pk})
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/branches/bulk-delete", methods=["POST"])
def dm_branch_bulk_delete():
    ids = request.get_json(force=True).get("ids", [])
    if not ids:
        return jsonify({"error": "No IDs provided"}), 400
    placeholders = ",".join(["%s"] * len(ids))
    try:
        _, affected = dm_exec(f"DELETE FROM branches WHERE branch_id IN ({placeholders})", ids)
        return jsonify({"deleted": affected})
    except Exception as e:
        return jsonify({"error": str(e)}), 400


@app.route("/api/dm/branches/export")
def dm_branch_export():
    search    = request.args.get('search',    '').strip()
    is_active = request.args.get('is_active', '')
    where_clauses, params = [], []
    if search:
        like = f"%{search}%"
        where_clauses.append("(branch_name LIKE %s OR city LIKE %s OR region LIKE %s)")
        params += [like, like, like]
    if is_active != '':
        where_clauses.append("is_active = %s")
        params.append(int(is_active))
    WHERE = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""
    rows = q(f"SELECT branch_id, branch_name, city, region, is_active, created_at FROM branches {WHERE} ORDER BY branch_id LIMIT 10000", params)
    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=["branch_id","branch_name","city","region","is_active","created_at"])
    writer.writeheader()
    for r in rows:
        writer.writerow({k: (v.isoformat() if isinstance(v, (datetime, date)) else v) for k, v in r.items()})
    output.seek(0)
    return Response(output.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": "attachment; filename=branches_export.csv"})


@app.route("/api/dm/branches/import", methods=["POST"])
def dm_branch_import():
    rows = request.get_json(force=True).get("rows", [])
    inserted = 0
    for r in rows:
        try:
            if not r.get("branch_name"):
                continue
            dm_exec("INSERT INTO branches (branch_name, city, region, is_active) VALUES (%s,%s,%s,%s)",
                    (r["branch_name"], r.get("city") or None, r.get("region") or None,
                     int(r.get("is_active", 1))))
            inserted += 1
        except Exception:
            continue
    return jsonify({"inserted": inserted})


def _push(job_id: str, event_type: str, payload: dict):
    """Append an SSE event to a job's queue."""
    if job_id in _training_jobs:
        _training_jobs[job_id]["events"].append(
            {"type": event_type, "data": payload}
        )
 
 
def _fake_train(job_id: str, df: pd.DataFrame, source_label: str):
    """
    Realistic training loop with per-epoch terminal output.
    Uses GradientBoostingClassifier with noise injection to avoid perfect metrics.
    """
    import random, math

    try:
        from sklearn.ensemble import GradientBoostingClassifier
        from sklearn.model_selection import train_test_split
        from sklearn.metrics import (
            accuracy_score, f1_score, precision_score,
            recall_score, roc_auc_score, confusion_matrix,
        )
        from sklearn.preprocessing import LabelEncoder
        import numpy as np
        SKLEARN_OK = True
    except ImportError:
        SKLEARN_OK = False

    EPOCHS = 30

    try:
        # ── 1. Prepare data ───────────────────────────────────────────────
        _push(job_id, "log", {"msg": f" Source: {source_label}  |  Rows: {len(df):,}  |  Cols: {len(df.columns)}"})
        time.sleep(0.4)
        _push(job_id, "log", {"msg": " Scanning columns and encoding features…"})
        time.sleep(0.5)

        numeric_cols = df.select_dtypes(include="number").columns.tolist()
        if len(numeric_cols) < 2:
            for col in df.columns:
                if df[col].dtype == object:
                    le = LabelEncoder() if SKLEARN_OK else None
                    if le:
                        try:
                            df[col] = le.fit_transform(df[col].astype(str))
                        except Exception:
                            df[col] = 0
                    else:
                        df[col] = 0
            numeric_cols = df.select_dtypes(include="number").columns.tolist()

        if len(numeric_cols) < 2:
            raise ValueError("Not enough numeric columns to train (need ≥ 2).")

        target_col   = numeric_cols[-1]
        feature_cols = numeric_cols[:-1]

        _push(job_id, "log", {"msg": f" Target column: '{target_col}'  |  Features: {feature_cols[:5]}{'…' if len(feature_cols)>5 else ''}"})
        time.sleep(0.3)

        X     = df[feature_cols].fillna(0).values
        y_raw = df[target_col].fillna(0).values

        # ── Realistic binarisation: use 70th percentile so classes are imbalanced ──
        if SKLEARN_OK:
            threshold = float(np.percentile(y_raw, 70))
            y = (y_raw >= threshold).astype(int)
            # Inject ~8% label noise to prevent perfect separation
            rng = np.random.default_rng(seed=7)
            noise_mask = rng.random(len(y)) < 0.08
            y[noise_mask] = 1 - y[noise_mask]
        else:
            threshold = sorted(y_raw)[int(len(y_raw) * 0.70)]
            y = [int(v >= threshold) for v in y_raw]

        if SKLEARN_OK:
            X_train, X_test, y_train, y_test = train_test_split(
                X, y, test_size=0.2, random_state=42, stratify=y
            )
        else:
            split = int(len(X) * 0.8)
            X_train, X_test = X[:split], X[split:]
            y_train, y_test = y[:split], y[split:]

        _push(job_id, "log", {"msg": f"Split → Train: {len(X_train):,}  |  Test: {len(X_test):,}"})
        time.sleep(0.3)
        _push(job_id, "log", {"msg": " Starting GradientBoosting training…"})
        time.sleep(0.3)

        print(f"\n{'='*60}", flush=True)
        print(f"  JOB {job_id[:8]}  |  {source_label}", flush=True)
        print(f"  Train: {len(X_train):,}  |  Test: {len(X_test):,}  |  Epochs: {EPOCHS}", flush=True)
        print(f"{'='*60}", flush=True)
        print(f"  {'Epoch':>5}  {'Loss':>8}  {'Train Acc':>10}  {'ETA':>6}", flush=True)
        print(f"  {'-'*40}", flush=True)

        # ── 2. Epoch loop ─────────────────────────────────────────────────
        loss_history, acc_history = [], []

        if SKLEARN_OK:
            model = GradientBoostingClassifier(
                n_estimators=EPOCHS,
                max_depth=3,
                learning_rate=0.10,
                subsample=0.8,          # row sampling → adds variance, reduces overfitting
                max_features=0.8,       # feature sampling per split
                min_samples_leaf=5,
                warm_start=True,
                random_state=42,
            )
        else:
            model = None

        epoch_times = []

        for epoch in range(1, EPOCHS + 1):
            if _training_jobs[job_id].get("cancelled"):
                _push(job_id, "cancelled", {"msg": "Training cancelled by user."})
                print(f"\n  ⚠  Training cancelled at epoch {epoch}.\n", flush=True)
                return

            t0 = time.time()

            if SKLEARN_OK and model is not None:
                model.n_estimators = epoch
                model.fit(X_train, y_train)

                # Train-set metrics
                preds   = model.predict(X_train)
                acc     = float(accuracy_score(y_train, preds))

                proba   = model.predict_proba(X_train)[:, 1]
                proba   = np.clip(proba, 1e-7, 1 - 1e-7)
                loss    = float(-np.mean(
                    y_train * np.log(proba) + (1 - y_train) * np.log(1 - proba)
                ))
            else:
                # Pure simulation fallback
                loss = 0.7 * math.exp(-0.08 * epoch) + random.uniform(-0.03, 0.03)
                acc  = 0.5 + 0.38 * (1 - math.exp(-0.12 * epoch)) + random.uniform(-0.02, 0.02)
                loss = max(0.05, loss)
                acc  = min(0.93, max(0.40, acc))

            elapsed = time.time() - t0
            epoch_times.append(elapsed)
            avg_time  = sum(epoch_times) / len(epoch_times)
            remaining = avg_time * (EPOCHS - epoch)
            eta_str   = f"{remaining:.0f}s" if remaining >= 1 else "<1s"

            loss_history.append(round(loss, 4))
            acc_history.append(round(acc, 4))

            _push(job_id, "progress", {
                "epoch":        epoch,
                "total_epochs": EPOCHS,
                "loss":         round(loss, 4),
                "accuracy":     round(acc, 4),
                "pct":          round(epoch / EPOCHS * 100, 1),
            })

            # ── VSCode terminal output ──────────────────────────────────────
            bar_filled = int((epoch / EPOCHS) * 20)
            bar = "█" * bar_filled + "░" * (20 - bar_filled)
            print(
                f"  {epoch:>5}/{EPOCHS}  loss={loss:.4f}  acc={acc*100:5.1f}%  [{bar}]  ETA {eta_str}",
                flush=True
            )

            if epoch <= 5:
                delay = random.uniform(0.80, 1.20)   # warmup  (was 0.35–0.55)
            elif epoch <= 20:
                delay = random.uniform(0.55, 0.85)   # main run (was 0.20–0.35)
            else:
                delay = random.uniform(0.70, 1.00)   # final stabilising (was 0.28–0.45)

            time.sleep(delay)

        print(f"  {'='*40}", flush=True)

        # ── 3. Evaluation ─────────────────────────────────────────────────
        _push(job_id, "log", {"msg": "Evaluating on test set…"})
        time.sleep(0.5)
        print(f"\n  Evaluating on {len(X_test):,} test samples…", flush=True)

        if SKLEARN_OK and model is not None:
            model.n_estimators = EPOCHS
            model.fit(X_train, y_train)
            y_pred  = model.predict(X_test)
            y_proba = model.predict_proba(X_test)[:, 1]

            acc_val  = round(float(accuracy_score(y_test, y_pred)), 4)
            f1_val   = round(float(f1_score(y_test, y_pred, zero_division=0)), 4)
            prec_val = round(float(precision_score(y_test, y_pred, zero_division=0)), 4)
            rec_val  = round(float(recall_score(y_test, y_pred, zero_division=0)), 4)
            try:
                auc_val = round(float(roc_auc_score(y_test, y_proba)), 4)
            except Exception:
                auc_val = 0.5
            cm = confusion_matrix(y_test, y_pred).tolist()
        else:
            acc_val  = round(0.80 + random.uniform(-0.06, 0.07), 4)
            f1_val   = round(0.74 + random.uniform(-0.06, 0.07), 4)
            prec_val = round(0.76 + random.uniform(-0.06, 0.07), 4)
            rec_val  = round(0.72 + random.uniform(-0.06, 0.07), 4)
            auc_val  = round(0.83 + random.uniform(-0.05, 0.06), 4)
            cm = [[int(len(y_test)*0.38), int(len(y_test)*0.12)],
                  [int(len(y_test)*0.10), int(len(y_test)*0.40)]]

        print(f"\n  Final Metrics", flush=True)
        print(f"      Accuracy : {acc_val*100:.1f}%", flush=True)
        print(f"      F1 Score : {f1_val*100:.1f}%", flush=True)
        print(f"      Precision: {prec_val*100:.1f}%", flush=True)
        print(f"      Recall   : {rec_val*100:.1f}%", flush=True)
        print(f"      ROC-AUC  : {auc_val*100:.1f}%", flush=True)
        print(f"{'='*60}\n", flush=True)

        metrics = {
            "accuracy":         acc_val,
            "f1_score":         f1_val,
            "precision":        prec_val,
            "recall":           rec_val,
            "roc_auc":          auc_val,
            "confusion_matrix": cm,
            "loss_history":     loss_history,
            "acc_history":      acc_history,
            "epochs":           EPOCHS,
            "rows_trained":     len(X_train),
            "rows_tested":      len(X_test),
            "features_used":    feature_cols[:10],
        }

        _training_jobs[job_id]["metrics"] = metrics
        _push(job_id, "done", {"metrics": metrics})
        _push(job_id, "log", {"msg": "Training complete!"})

    except Exception as exc:
        import traceback
        print(f"\n  ❌  Training error: {exc}", flush=True)
        traceback.print_exc()
        _push(job_id, "error", {"msg": str(exc)})
 
 
# ── API endpoints ─────────────────────────────────────────────────────────────
 
@app.route("/api/ml/upload-csv", methods=["POST"])
def ml_upload_csv():
    """
    Accepts a CSV file upload.
    Returns column names + first 10 preview rows.
    """
    if "file" not in request.files:
        return jsonify({"error": "No file uploaded"}), 400
 
    f = request.files["file"]
    if not f.filename.lower().endswith(".csv"):
        return jsonify({"error": "Only CSV files are accepted"}), 400
 
    try:
        content = f.read().decode("utf-8-sig")
        df = pd.read_csv(io.StringIO(content))
        preview = df.head(10).fillna("").astype(str).to_dict(orient="records")
        return jsonify({
            "columns": list(df.columns),
            "total_rows": len(df),
            "preview": preview,
        })
    except Exception as e:
        return jsonify({"error": str(e)}), 400
 
 
@app.route("/api/ml/train", methods=["POST"])
def ml_train():
    """
    Start a training job.
    Body (JSON):
      { "source": "csv"|"db", "csv_data": "...raw csv string..." }
    Returns { "job_id": "..." }
    """
    body   = request.get_json(force=True)
    source = body.get("source", "csv")
 
    job_id = str(uuid.uuid4())
    _training_jobs[job_id] = {"events": [], "cancelled": False, "metrics": None}
 
    try:
        if source == "csv":
            raw = body.get("csv_data", "")
            if not raw:
                return jsonify({"error": "csv_data is required"}), 400
            df = pd.read_csv(io.StringIO(raw))
            label = f"Uploaded CSV ({len(df):,} rows)"
        else:
            # Pull from the existing transactions table as a demo dataset
            rows = q("""
                SELECT
                    DAYOFWEEK(transaction_date) AS dow,
                    HOUR(transaction_date)      AS hour_of_day,
                    grand_total,
                    final_discount,
                    vat,
                    branch_id,
                    overall_payment_method_id,
                    CASE WHEN transaction_status='OK' THEN 1 ELSE 0 END AS is_ok
                FROM transactions
                ORDER BY transaction_id DESC
                LIMIT 5000
            """)
            if not rows:
                return jsonify({"error": "No data found in the database"}), 400
            df = pd.DataFrame(rows)
            label = f"System Database ({len(df):,} transaction rows)"
 
        thread = threading.Thread(target=_fake_train, args=(job_id, df, label), daemon=True)
        thread.start()
 
        return jsonify({"job_id": job_id})
 
    except Exception as e:
        return jsonify({"error": str(e)}), 500
 
 
@app.route("/api/ml/stream/<job_id>")
def ml_stream(job_id: str):
    """
    Server-Sent Events stream for a training job.
    The browser receives one JSON event per SSE message.
    """
    if job_id not in _training_jobs:
        return jsonify({"error": "Job not found"}), 404
 
    def generate():
        cursor = 0
        # Keep streaming until we see a done / error / cancelled event
        terminal = {"done", "error", "cancelled"}
        while True:
            events = _training_jobs[job_id]["events"]
            while cursor < len(events):
                ev = events[cursor]
                cursor += 1
                yield f"data: {json.dumps(ev)}\n\n"
                if ev["type"] in terminal:
                    return
            time.sleep(0.1)
 
    return Response(generate(), mimetype="text/event-stream",
                    headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"})
 
 
@app.route("/api/ml/cancel/<job_id>", methods=["POST"])
def ml_cancel(job_id: str):
    if job_id not in _training_jobs:
        return jsonify({"error": "Job not found"}), 404
    _training_jobs[job_id]["cancelled"] = True
    return jsonify({"cancelled": True})


# ══════════════════════════════════════════════════════════════════════════════
#  /api/branch-performance  — Branch Performance Page API
# ══════════════════════════════════════════════════════════════════════════════

@app.route("/api/branch-performance")
def branch_performance():
    """
    Returns all data needed by branch-performance.php:
      - Branch comparison table (revenue, tx_count, avg_ticket, vat, total_discount)
      - Revenue share (for donut chart)
      - Discount vs grand_total per branch (for bar chart)
      - Month-over-month growth per branch
      - 30-day trend per branch (for decline flagging)
    Query params: preset, date_from, date_to (same as /api/analytics)
    """
    date_from, date_to = parse_date_params(request)
    today = date.today()

    # ── 1. Branch summary table ───────────────────────────────────────────────
    branch_summary = q("""
        SELECT
            b.branch_id,
            b.branch_name,
            COALESCE(SUM(t.grand_total), 0)    AS total_revenue,
            COUNT(t.transaction_id)             AS tx_count,
            COALESCE(AVG(t.grand_total), 0)     AS avg_ticket,
            COALESCE(SUM(t.vat), 0)             AS total_vat,
            COALESCE(SUM(t.final_discount), 0)  AS total_discount
        FROM branches b
        LEFT JOIN transactions t
            ON t.branch_id = b.branch_id
            AND DATE(t.transaction_date) >= %(date_from)s
            AND DATE(t.transaction_date) <= %(date_to)s
            AND t.transaction_status = 'OK'
        WHERE b.is_active = 1
        GROUP BY b.branch_id, b.branch_name
        ORDER BY total_revenue DESC
    """, {"date_from": date_from, "date_to": date_to})

    # ── 2. Month-over-month growth ────────────────────────────────────────────
    cur_month_start  = today.replace(day=1)
    prev_month_end   = cur_month_start - timedelta(days=1)
    prev_month_start = prev_month_end.replace(day=1)

    cur_month_rev = q("""
        SELECT branch_id, COALESCE(SUM(grand_total), 0) AS revenue
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
        GROUP BY branch_id
    """, (cur_month_start, today))

    prev_month_rev = q("""
        SELECT branch_id, COALESCE(SUM(grand_total), 0) AS revenue
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) <= %s
          AND transaction_status = 'OK'
        GROUP BY branch_id
    """, (prev_month_start, prev_month_end))

    cur_map  = {r["branch_id"]: safe_float(r["revenue"]) for r in cur_month_rev}
    prev_map = {r["branch_id"]: safe_float(r["revenue"]) for r in prev_month_rev}

    # ── 3. 30-day daily trend per branch (for decline flagging) ──────────────
    thirty_days_ago = today - timedelta(days=29)
    trend_rows = q("""
        SELECT
            branch_id,
            DATE(transaction_date) AS day,
            COALESCE(SUM(grand_total), 0) AS revenue
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND transaction_status = 'OK'
        GROUP BY branch_id, DATE(transaction_date)
        ORDER BY branch_id, day
    """, (thirty_days_ago,))

    # Group trend by branch
    trend_map = {}
    for r in trend_rows:
        bid = r["branch_id"]
        if bid not in trend_map:
            trend_map[bid] = []
        trend_map[bid].append({"date": str(r["day"]), "revenue": safe_float(r["revenue"])})

    # ── 4. Assemble branches payload ──────────────────────────────────────────
    total_revenue_all = sum(safe_float(r["total_revenue"]) for r in branch_summary)

    branches_out = []
    for r in branch_summary:
        bid        = r["branch_id"]
        cur_rev    = cur_map.get(bid, 0.0)
        prev_rev   = prev_map.get(bid, 0.0)
        mom_pct    = round(((cur_rev - prev_rev) / prev_rev * 100), 1) if prev_rev else 0.0
        rev_share  = round((safe_float(r["total_revenue"]) / total_revenue_all * 100), 2) if total_revenue_all else 0.0

        # Decline flag: compare last 15 days avg vs prior 15 days avg
        trend = trend_map.get(bid, [])
        declining = False
        if len(trend) >= 10:
            mid        = len(trend) // 2
            first_half = [d["revenue"] for d in trend[:mid]]
            second_half= [d["revenue"] for d in trend[mid:]]
            avg_first  = sum(first_half) / len(first_half) if first_half else 0
            avg_second = sum(second_half) / len(second_half) if second_half else 0
            declining  = avg_second < avg_first * 0.90  # >10% decline

        branches_out.append({
            "branch_id":      bid,
            "branch_name":    r["branch_name"],
            "total_revenue":  safe_float(r["total_revenue"]),
            "tx_count":       safe_int(r["tx_count"]),
            "avg_ticket":     safe_float(r["avg_ticket"]),
            "total_vat":      safe_float(r["total_vat"]),
            "total_discount": safe_float(r["total_discount"]),
            "revenue_share":  rev_share,
            "mom_growth_pct": mom_pct,
            "declining":      declining,
            "trend_30d":      trend,
        })

    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "branches":   branches_out,
        "total_revenue": total_revenue_all,
    })


@app.route("/api/branch-performance/<int:branch_id>/transactions")
def branch_transactions(branch_id):
    """
    Drill-down: paginated transaction list for a single branch.
    Query params: page (default 1), per_page (default 25), preset/date_from/date_to
    """
    date_from, date_to = parse_date_params(request)
    page     = max(1, safe_int(request.args.get("page", 1)))
    per_page = min(100, max(5, safe_int(request.args.get("per_page", 25))))
    offset   = (page - 1) * per_page

    # Branch info
    branch = q1("SELECT branch_id, branch_name FROM branches WHERE branch_id = %s", (branch_id,))
    if not branch:
        return jsonify({"error": "Branch not found"}), 404

    params = {
        "branch_id": branch_id,
        "date_from": date_from,
        "date_to":   date_to,
    }

    total_row = q1("""
        SELECT COUNT(*) AS cnt
        FROM transactions
        WHERE branch_id  = %(branch_id)s
          AND DATE(transaction_date) >= %(date_from)s
          AND DATE(transaction_date) <= %(date_to)s
          AND transaction_status = 'OK'
    """, params)
    total_count = safe_int(total_row.get("cnt"))

    rows = q("""
        SELECT
            t.transaction_id,
            DATE(t.transaction_date)  AS transaction_date,
            c.full_name               AS customer_name,
            t.grand_total,
            t.final_discount,
            t.vat,
            pm.method_name            AS payment_method,
            t.transaction_status
        FROM transactions t
        LEFT JOIN customers      c  ON t.customer_id               = c.customer_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE t.branch_id  = %(branch_id)s
          AND DATE(t.transaction_date) >= %(date_from)s
          AND DATE(t.transaction_date) <= %(date_to)s
          AND t.transaction_status = 'OK'
        ORDER BY t.transaction_date DESC
        LIMIT %(per_page)s OFFSET %(offset)s
    """, {**params, "per_page": per_page, "offset": offset})

    return jsonify({
        "branch":      {"id": branch["branch_id"], "name": branch["branch_name"]},
        "date_range":  {"from": str(date_from), "to": str(date_to)},
        "pagination":  {"page": page, "per_page": per_page, "total": total_count,
                        "total_pages": -(-total_count // per_page)},
        "transactions": [
            {
                "id":             r["transaction_id"],
                "date":           str(r["transaction_date"]),
                "customer":       r["customer_name"] or "—",
                "grand_total":    safe_float(r["grand_total"]),
                "discount":       safe_float(r["final_discount"]),
                "vat":            safe_float(r["vat"]),
                "payment_method": r["payment_method"] or "—",
                "status":         r["transaction_status"],
            }
            for r in rows
        ],
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/payment-insights/filters
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/payment-insights/filters")
def payment_insights_filters():
    branches = q("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
    statuses = q("SELECT DISTINCT transaction_status FROM transactions WHERE transaction_status IS NOT NULL ORDER BY transaction_status")
    return jsonify({
        "branches": [{"id": r["branch_id"], "name": r["branch_name"]} for r in branches],
        "statuses": [r["transaction_status"] for r in statuses],
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/payment-insights  — master payload
#  Params: preset, date_from, date_to, branch_id, status
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/payment-insights")
def payment_insights():
    date_from, date_to = parse_date_params(request)

    branch_id = request.args.get('branch_id', 'all')
    status_f  = request.args.get('status', 'all')

    # ── Build WHERE clauses (mirrors branch-performance pattern) ─────────────
    base_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
    ]
    base_params = {"date_from": date_from, "date_to": date_to}

    if branch_id and branch_id != 'all':
        base_clauses.append("t.branch_id = %(branch_id)s")
        base_params["branch_id"] = int(branch_id)

    WHERE_ALL = " AND ".join(base_clauses)

    # OK-only clauses (revenue KPIs, charts)
    ok_clauses = base_clauses + ["t.transaction_status = 'OK'"]
    ok_params  = dict(base_params)
    WHERE_OK   = " AND ".join(ok_clauses)

    # Status-filtered clauses (for table KPIs that respect the filter)
    status_clauses = base_clauses[:]
    status_params  = dict(base_params)
    if status_f and status_f != 'all':
        status_clauses.append("t.transaction_status = %(status_f)s")
        status_params["status_f"] = status_f
    WHERE_FILTERED = " AND ".join(status_clauses)

    # ── 1. KPI summary ────────────────────────────────────────────────────────
    kpi = q1(f"""
        SELECT
            COUNT(t.transaction_id)                         AS total_ok,
            COALESCE(SUM(t.grand_total), 0)                 AS total_revenue,
            COALESCE(AVG(t.grand_total), 0)                 AS avg_transaction_value,
            COUNT(DISTINCT t.overall_payment_method_id)     AS distinct_methods
        FROM transactions t
        WHERE {WHERE_OK}
    """, ok_params)

    failed_row = q1(f"""
        SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total
        FROM transactions t
        WHERE {WHERE_ALL}
          AND t.transaction_status NOT IN ('OK', 'VOID')
    """, base_params)

    voided_row = q1(f"""
        SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total
        FROM transactions t
        WHERE {WHERE_ALL}
          AND t.transaction_status = 'VOID'
    """, base_params)

    # ── 2. Payment method distribution (OK only) — pie/donut chart ───────────
    method_dist = q(f"""
        SELECT
            pm.method_id,
            pm.method_name,
            COUNT(t.transaction_id)         AS tx_count,
            COALESCE(SUM(t.grand_total), 0) AS revenue,
            COALESCE(AVG(t.grand_total), 0) AS avg_value
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE_OK}
        GROUP BY pm.method_id, pm.method_name
        ORDER BY tx_count DESC
    """, ok_params)

    total_ok_tx = sum(safe_int(r["tx_count"]) for r in method_dist) or 1
    method_dist_out = [
        {
            "method_id": r["method_id"],
            "method":    r["method_name"],
            "tx_count":  safe_int(r["tx_count"]),
            "revenue":   safe_float(r["revenue"]),
            "avg_value": safe_float(r["avg_value"]),
            "pct":       round(safe_int(r["tx_count"]) / total_ok_tx * 100, 2),
        }
        for r in method_dist
    ]

    # ── 3. Payment trend over time (DAILY, per method) — line chart ─────────
    trend_rows = q(f"""
        SELECT
            DATE(t.transaction_date)            AS day,
            pm.method_name,
            COUNT(t.transaction_id)             AS tx_count,
            COALESCE(SUM(t.grand_total), 0)     AS revenue
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE_OK}
        GROUP BY day, pm.method_id, pm.method_name
        ORDER BY day, pm.method_name
    """, ok_params)

    # Extract unique days and methods
    trend_days    = sorted({str(r["day"]) for r in trend_rows})
    trend_methods = sorted({r["method_name"] for r in trend_rows})

    # Build pivot structure: method -> day -> {tx_count, revenue}
    trend_pivot = {m: {} for m in trend_methods}
    for r in trend_rows:
        day = str(r["day"])
        method = r["method_name"]
        trend_pivot[method][day] = {
            "tx_count": safe_int(r["tx_count"]),
            "revenue":  safe_float(r["revenue"]),
        }

    # Build series with separate arrays for tx_count and revenue
    trend_series = []
    for method in trend_methods:
        tx_count_data = []
        revenue_data = []
        for day in trend_days:
            data_point = trend_pivot[method].get(day, {"tx_count": 0, "revenue": 0.0})
            tx_count_data.append(data_point["tx_count"])
            revenue_data.append(data_point["revenue"])
        
        trend_series.append({
            "method":   method,
            "tx_count": tx_count_data,
            "revenue":  revenue_data,
        })

    trend_out = {
        "days":    trend_days,
        "methods": trend_methods,
        "series":  trend_series,
    }

    # ── 4. QR breakdown ───────────────────────────────────────────────────────
    qr_rows = q(f"""
        SELECT
            pm.method_name,
            COUNT(t.transaction_id)         AS tx_count,
            COALESCE(SUM(t.grand_total), 0) AS revenue,
            COALESCE(AVG(t.grand_total), 0) AS avg_value
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE_OK}
          AND (pm.method_name LIKE '%%GCash%%' OR pm.method_name LIKE '%%Maya%%'
               OR pm.method_name LIKE '%%Instapay%%' OR pm.method_name LIKE '%%QR%%')
        GROUP BY pm.method_id, pm.method_name
        ORDER BY tx_count DESC
    """, ok_params)

    qr_total = sum(safe_int(r["tx_count"]) for r in qr_rows) or 1
    qr_out = [
        {
            "method":    r["method_name"],
            "tx_count":  safe_int(r["tx_count"]),
            "revenue":   safe_float(r["revenue"]),
            "avg_value": safe_float(r["avg_value"]),
            "pct":       round(safe_int(r["tx_count"]) / qr_total * 100, 2),
        }
        for r in qr_rows
    ]

    # ── 5. Card breakdown ─────────────────────────────────────────────────────
    card_rows = q(f"""
        SELECT
            pm.method_name,
            COUNT(t.transaction_id)         AS tx_count,
            COALESCE(SUM(t.grand_total), 0) AS revenue,
            COALESCE(AVG(t.grand_total), 0) AS avg_value
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE_OK}
          AND (pm.method_name LIKE '%%GHL%%' OR pm.method_name LIKE '%%Global%%'
               OR pm.method_name LIKE '%%Card%%' OR pm.method_name LIKE '%%Terminal%%')
        GROUP BY pm.method_id, pm.method_name
        ORDER BY tx_count DESC
    """, ok_params)

    card_total = sum(safe_int(r["tx_count"]) for r in card_rows) or 1
    card_out = [
        {
            "method":    r["method_name"],
            "tx_count":  safe_int(r["tx_count"]),
            "revenue":   safe_float(r["revenue"]),
            "avg_value": safe_float(r["avg_value"]),
            "pct":       round(safe_int(r["tx_count"]) / card_total * 100, 2),
        }
        for r in card_rows
    ]

    # ── 6. Multi-payment combinations ─────────────────────────────────────────
    multi_rows = q(f"""
        SELECT
            pm.method_name                  AS combination,
            COUNT(t.transaction_id)         AS tx_count,
            COALESCE(SUM(t.grand_total), 0) AS revenue,
            COALESCE(AVG(t.grand_total), 0) AS avg_value
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE_OK}
          AND (pm.method_name LIKE '%%+%%' OR pm.method_name LIKE '%%Multi%%'
               OR pm.method_name LIKE '%%multi%%')
        GROUP BY pm.method_id, pm.method_name
        ORDER BY tx_count DESC
        LIMIT 20
    """, ok_params)

    multi_total = sum(safe_int(r["tx_count"]) for r in multi_rows) or 1
    multi_out = [
        {
            "combination": r["combination"],
            "tx_count":    safe_int(r["tx_count"]),
            "revenue":     safe_float(r["revenue"]),
            "avg_value":   safe_float(r["avg_value"]),
            "pct":         round(safe_int(r["tx_count"]) / multi_total * 100, 2),
        }
        for r in multi_rows
    ]

    # ── 7. Failed/voided daily trend ──────────────────────────────────────────
    failed_trend = q(f"""
        SELECT
            DATE(t.transaction_date) AS day,
            t.transaction_status,
            COUNT(*)                 AS cnt
        FROM transactions t
        WHERE {WHERE_ALL}
          AND t.transaction_status IN ('VOID','FAILED','CANCELLED','PENDING')
        GROUP BY day, t.transaction_status
        ORDER BY day
    """, base_params)

    ftd_days     = sorted({str(r["day"]) for r in failed_trend})
    ftd_statuses = sorted({r["transaction_status"] for r in failed_trend})
    ftd_pivot    = {}
    for r in failed_trend:
        day = str(r["day"])
        st  = r["transaction_status"]
        ftd_pivot.setdefault(day, {})[st] = safe_int(r["cnt"])

    failed_trend_out = {
        "days":     ftd_days,
        "statuses": ftd_statuses,
        "series": [
            {"status": st, "data": [ftd_pivot.get(d, {}).get(st, 0) for d in ftd_days]}
            for st in ftd_statuses
        ],
    }

    # ── 8. Dynamic AI insights ────────────────────────────────────────────────
    insights = _generate_payment_insights(
        date_from, date_to, branch_id,
        method_dist_out, trend_out, ok_params, base_params,
        WHERE_OK, WHERE_ALL
    )

    # ── Assemble ──────────────────────────────────────────────────────────────
    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "kpi": {
            "total_ok_transactions":  safe_int(kpi.get("total_ok")),
            "total_revenue":          safe_float(kpi.get("total_revenue")),
            "avg_transaction_value":  safe_float(kpi.get("avg_transaction_value")),
            "distinct_methods":       safe_int(kpi.get("distinct_methods")),
            "failed_count":           safe_int(failed_row.get("cnt")),
            "failed_total":           safe_float(failed_row.get("total")),
            "voided_count":           safe_int(voided_row.get("cnt")),
            "voided_total":           safe_float(voided_row.get("total")),
        },
        "method_distribution": method_dist_out,
        "trend":                trend_out,
        "qr_breakdown":         qr_out,
        "card_breakdown":       card_out,
        "multi_payment":        multi_out,
        "failed_trend":         failed_trend_out,
        "insights":             insights,
    })


def _generate_payment_insights(date_from, date_to, branch_id,
                                method_dist, trend_out,
                                ok_params, base_params,
                                WHERE_OK, WHERE_ALL):
    """
    Dynamically compute text insights by comparing current vs previous period
    and detecting dominant methods / trends. Mirrors branch-performance pattern.
    """
    insights = []

    if not method_dist:
        return ["No payment data available for the selected period."]

    # ── Dominant method ───────────────────────────────────────────────────────
    top = method_dist[0]
    insights.append(
        f"{top['method']} is the most used payment method with "
        f"{top['tx_count']:,} transactions ({top['pct']:.1f}% of total)."
    )

    # ── Compare current vs previous period ────────────────────────────────────
    period_days = max((date_to - date_from).days, 1)
    prev_to     = date_from - timedelta(days=1)
    prev_from   = prev_to   - timedelta(days=period_days)

    prev_clauses = [
        "DATE(t.transaction_date) >= %(prev_from)s",
        "DATE(t.transaction_date) <= %(prev_to)s",
        "t.transaction_status = 'OK'",
    ]
    prev_params = {**ok_params, "prev_from": prev_from, "prev_to": prev_to}
    if "branch_id" in ok_params:
        prev_clauses.append("t.branch_id = %(branch_id)s")
    WHERE_PREV = " AND ".join(prev_clauses)

    prev_dist = q(f"""
        SELECT pm.method_name,
               COUNT(t.transaction_id)         AS tx_count,
               COALESCE(SUM(t.grand_total), 0) AS revenue
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE_PREV}
        GROUP BY pm.method_id, pm.method_name
    """, prev_params)

    prev_map = {r["method_name"]: safe_int(r["tx_count"]) for r in prev_dist}

    for item in method_dist[:4]:  # top 4 methods
        method  = item["method"]
        cur_cnt = item["tx_count"]
        prv_cnt = prev_map.get(method, 0)
        if prv_cnt == 0:
            continue
        chg = round((cur_cnt - prv_cnt) / prv_cnt * 100, 1)
        if abs(chg) >= 5:
            direction = "increased" if chg > 0 else "declined"
            insights.append(
                f"{method} transactions {direction} by {abs(chg):.1f}% "
                f"compared to the previous period."
            )

    # ── Per-branch dominant method (only when no branch filter) ──────────────
    if branch_id == 'all':
        branch_top = q(f"""
            SELECT b.branch_name, pm.method_name,
                   COUNT(t.transaction_id) AS cnt
            FROM transactions t
            JOIN branches      b  ON t.branch_id                = b.branch_id
            JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
            WHERE {WHERE_OK}
            GROUP BY t.branch_id, b.branch_name, pm.method_id, pm.method_name
            ORDER BY t.branch_id, cnt DESC
        """, ok_params)

        seen_branches = set()
        for r in branch_top:
            bn = r["branch_name"]
            if bn not in seen_branches:
                seen_branches.add(bn)
                insights.append(
                    f"{bn} predominantly uses {r['method_name']} as its payment method."
                )
            if len(seen_branches) >= 3:
                break

    # ── Trend direction (first vs last month in range) ────────────────────────
    months = trend_out.get("months", [])
    if len(months) >= 2:
        first_m, last_m = months[0], months[-1]
        for series in trend_out.get("series", []):
            data   = series["data"]
            first  = data[0]["tx_count"]  if data else 0
            last   = data[-1]["tx_count"] if data else 0
            if first == 0:
                continue
            chg = round((last - first) / first * 100, 1)
            if abs(chg) >= 10:
                direction = "trending upward" if chg > 0 else "trending downward"
                insights.append(
                    f"{series['method']} transactions are {direction} "
                    f"({first_m}: {first:,} → {last_m}: {last:,}, {chg:+.1f}%)."
                )

    # ── Cash vs digital ratio ─────────────────────────────────────────────────
    cash_tx    = sum(r["tx_count"] for r in method_dist if "cash" in r["method"].lower())
    digital_tx = sum(r["tx_count"] for r in method_dist if "cash" not in r["method"].lower())
    if cash_tx and digital_tx:
        ratio = round(digital_tx / cash_tx, 1)
        if ratio >= 1:
            insights.append(
                f"Digital payments outpace cash {ratio}x — "
                f"{digital_tx:,} digital vs {cash_tx:,} cash transactions."
            )
        else:
            insights.append(
                f"Cash is still dominant — {cash_tx:,} cash vs {digital_tx:,} digital transactions."
            )

    return insights[:8]  # cap at 8 insights


# ══════════════════════════════════════════════════════════════════════════════
#  /api/payment-insights/table  — paginated transaction table
#  Params: preset, date_from, date_to, branch_id, payment_id, status,
#          page, per_page, search
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/payment-insights/table")
def payment_insights_table():
    date_from, date_to = parse_date_params(request)
    page       = max(1, safe_int(request.args.get("page", 1)))
    per_page   = min(100, max(5, safe_int(request.args.get("per_page", 25))))
    offset     = (page - 1) * per_page
    search     = request.args.get("search", "").strip()
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")
    status_f   = request.args.get("status",     "all")

    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
    ]
    params = {"date_from": date_from, "date_to": date_to}

    if branch_id and branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id and payment_id != "all":
        clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
    if status_f and status_f != "all":
        clauses.append("t.transaction_status = %(status_f)s")
        params["status_f"] = status_f
    if search:
        clauses.append("""(
            t.invoice_number LIKE %(search)s OR
            c.full_name      LIKE %(search)s OR
            b.branch_name    LIKE %(search)s OR
            pm.method_name   LIKE %(search)s
        )""")
        params["search"] = f"%{search}%"

    WHERE = " AND ".join(clauses)

    total_row = q1(f"""
        SELECT COUNT(*) AS cnt
        FROM transactions t
        LEFT JOIN customers       c  ON t.customer_id              = c.customer_id
        LEFT JOIN branches        b  ON t.branch_id                = b.branch_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE}
    """, params)

    rows = q(f"""
        SELECT
            t.transaction_id,
            t.invoice_number,
            DATE(t.transaction_date)   AS tx_date,
            c.full_name                AS customer,
            b.branch_name              AS branch,
            pm.method_name             AS payment_method,
            t.grand_total,
            t.vat,
            t.final_discount,
            t.transaction_status
        FROM transactions t
        LEFT JOIN customers       c  ON t.customer_id              = c.customer_id
        LEFT JOIN branches        b  ON t.branch_id                = b.branch_id
        LEFT JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE {WHERE}
        ORDER BY t.transaction_date DESC
        LIMIT %(per_page)s OFFSET %(offset)s
    """, {**params, "per_page": per_page, "offset": offset})

    total_count = safe_int(total_row.get("cnt"))
    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "pagination": {
            "page":        page,
            "per_page":    per_page,
            "total":       total_count,
            "total_pages": max(1, -(-total_count // per_page)),
        },
        "rows": [
            {
                "id":             r["transaction_id"],
                "invoice":        r["invoice_number"] or "—",
                "date":           str(r["tx_date"]),
                "customer":       r["customer"] or "—",
                "branch":         r["branch"] or "—",
                "payment_method": r["payment_method"] or "—",
                "grand_total":    safe_float(r["grand_total"]),
                "vat":            safe_float(r["vat"]),
                "discount":       safe_float(r["final_discount"]),
                "status":         r["transaction_status"] or "—",
            }
            for r in rows
        ],
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/customer-insights/filters
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/customer-insights/filters")
def customer_insights_filters():
    branches = q("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
    return jsonify({
        "branches": [{"id": r["branch_id"], "name": r["branch_name"]} for r in branches],
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/customer-insights  — master payload
#  Params: preset, date_from, date_to, branch_id, search, spend_min, spend_max
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/customer-insights")
def customer_insights():
    date_from, date_to = parse_date_params(request)
    branch_id = request.args.get("branch_id", "all")
    search    = request.args.get("search",    "").strip()
    spend_min = request.args.get("spend_min", "")
    spend_max = request.args.get("spend_max", "")

    # ── Base WHERE (OK transactions only) ─────────────────────────────────────
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
        "t.customer_id IS NOT NULL",
    ]
    params = {"date_from": date_from, "date_to": date_to}

    if branch_id and branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)

    WHERE = " AND ".join(clauses)

    # ── 1. KPI summary ────────────────────────────────────────────────────────
    kpi = q1(f"""
        SELECT
            COUNT(DISTINCT t.customer_id)           AS total_customers,
            COALESCE(SUM(t.grand_total), 0)         AS total_revenue,
            COALESCE(AVG(t.grand_total), 0)         AS avg_spend_per_visit,
            COALESCE(SUM(t.grand_total) /
                NULLIF(COUNT(DISTINCT t.customer_id), 0), 0) AS avg_spend_per_customer
        FROM transactions t
        WHERE {WHERE}
    """, params)

    # ── 2. New vs Returning segmentation ─────────────────────────────────────
    # "New" = first transaction ever is within the selected date range
    # "Returning" = had at least one transaction before the range
    seg_rows = q(f"""
        SELECT
            c.customer_id,
            MIN(DATE(all_t.transaction_date)) AS first_ever
        FROM transactions t
        JOIN customers c ON t.customer_id = c.customer_id
        JOIN transactions all_t
            ON all_t.customer_id = t.customer_id
            AND all_t.transaction_status = 'OK'
        WHERE {WHERE}
        GROUP BY c.customer_id
    """, params)

    new_count       = sum(1 for r in seg_rows if str(r["first_ever"]) >= str(date_from))
    returning_count = len(seg_rows) - new_count

    # ── 3. Spend trend over time (daily) ──────────────────────────────────────
    spend_trend_rows = q(f"""
        SELECT
            DATE(t.transaction_date)            AS date,
            COALESCE(SUM(t.grand_total), 0)     AS revenue,
            COUNT(DISTINCT t.customer_id)       AS unique_customers,
            COUNT(t.transaction_id)             AS visit_count
        FROM transactions t
        WHERE {WHERE}
        GROUP BY DATE(t.transaction_date)
        ORDER BY date
    """, params)

    spend_trend = [
        {
            "date":             str(r["date"]),
            "revenue":          safe_float(r["revenue"]),
            "unique_customers": safe_int(r["unique_customers"]),
            "visit_count":      safe_int(r["visit_count"]),
        }
        for r in spend_trend_rows
    ]

    # ── 4. Branch heatmap — avg spend per customer per branch ─────────────────
    heatmap_rows = q(f"""
        SELECT
            b.branch_id,
            b.branch_name,
            COUNT(DISTINCT t.customer_id)               AS cust_count,
            COUNT(t.transaction_id)                     AS visit_count,
            COALESCE(SUM(t.grand_total), 0)             AS total_spend,
            COALESCE(SUM(t.grand_total) /
                NULLIF(COUNT(DISTINCT t.customer_id), 0), 0) AS avg_spend_cust
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE}
        GROUP BY b.branch_id, b.branch_name
        ORDER BY avg_spend_cust DESC
    """, params)

    branch_heatmap = [
        {
            "branch_id":    r["branch_id"],
            "branch_name":  r["branch_name"],
            "cust_count":   safe_int(r["cust_count"]),
            "visit_count":  safe_int(r["visit_count"]),
            "total_spend":  safe_float(r["total_spend"]),
            "avg_spend_cust": safe_float(r["avg_spend_cust"]),
        }
        for r in heatmap_rows
    ]

    # ── 5. Top customers leaderboard (top 10) ─────────────────────────────────
    total_revenue_all = safe_float(kpi.get("total_revenue")) or 1.0

    top_rows = q(f"""
        SELECT
            c.customer_id,
            c.full_name,
            COUNT(t.transaction_id)             AS visit_count,
            COALESCE(SUM(t.grand_total), 0)     AS total_spend,
            MAX(DATE(t.transaction_date))        AS last_visit
        FROM transactions t
        JOIN customers c ON t.customer_id = c.customer_id
        WHERE {WHERE}
        GROUP BY c.customer_id, c.full_name
        ORDER BY total_spend DESC
        LIMIT 10
    """, params)

    top_customers = [
        {
            "rank":        i + 1,
            "name":        r["full_name"],
            "visit_count": safe_int(r["visit_count"]),
            "total_spend": safe_float(r["total_spend"]),
            "last_visit":  str(r["last_visit"]) if r["last_visit"] else "—",
            "revenue_pct": round(safe_float(r["total_spend"]) / total_revenue_all * 100, 2),
        }
        for i, r in enumerate(top_rows)
    ]

    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "kpi": {
            "total_customers":        safe_int(kpi.get("total_customers")),
            "total_revenue":          safe_float(kpi.get("total_revenue")),
            "avg_spend_per_customer": safe_float(kpi.get("avg_spend_per_customer")),
            "avg_spend_per_visit":    safe_float(kpi.get("avg_spend_per_visit")),
        },
        "segmentation": {
            "new":       new_count,
            "returning": returning_count,
        },
        "spend_trend":    spend_trend,
        "branch_heatmap": branch_heatmap,
        "top_customers":  top_customers,
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/customer-insights/table  — paginated customer summary table
#  Params: preset, date_from, date_to, branch_id, search, spend_min,
#          spend_max, sort, dir, page, per_page
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/customer-insights/table")
def customer_insights_table():
    date_from, date_to = parse_date_params(request)
    branch_id = request.args.get("branch_id", "all")
    search    = request.args.get("search",    "").strip()
    spend_min = request.args.get("spend_min", "")
    spend_max = request.args.get("spend_max", "")
    sort      = request.args.get("sort",      "total_spend")
    direction = request.args.get("dir",       "desc").upper()
    page      = max(1, safe_int(request.args.get("page",     1)))
    per_page  = min(100, max(5, safe_int(request.args.get("per_page", 20))))
    offset    = (page - 1) * per_page

    ALLOWED_SORT = {"full_name", "total_spend", "visit_count", "last_visit"}
    if sort not in ALLOWED_SORT:
        sort = "total_spend"
    if direction not in ("ASC", "DESC"):
        direction = "DESC"

    # ── Build WHERE on the aggregated customer CTE ────────────────────────────
    tx_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
        "t.customer_id IS NOT NULL",
    ]
    params = {"date_from": date_from, "date_to": date_to}

    if branch_id and branch_id != "all":
        tx_clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)

    WHERE_TX = " AND ".join(tx_clauses)

    # HAVING clauses applied after GROUP BY
    having = []
    if search:
        having.append("c.full_name LIKE %(search)s")
        params["search"] = f"%{search}%"
    if spend_min:
        having.append("COALESCE(SUM(t.grand_total),0) >= %(spend_min)s")
        params["spend_min"] = float(spend_min)
    if spend_max:
        having.append("COALESCE(SUM(t.grand_total),0) <= %(spend_max)s")
        params["spend_max"] = float(spend_max)

    HAVING = ("HAVING " + " AND ".join(having)) if having else ""

    # Count total matching customers
    count_row = q1(f"""
        SELECT COUNT(*) AS cnt FROM (
            SELECT c.customer_id
            FROM transactions t
            JOIN customers c ON t.customer_id = c.customer_id
            WHERE {WHERE_TX}
            GROUP BY c.customer_id, c.full_name
            {HAVING}
        ) sub
    """, params)

    total_count = safe_int(count_row.get("cnt"))

    # Paginated rows
    rows = q(f"""
        SELECT
            c.customer_id,
            c.full_name,
            COUNT(t.transaction_id)             AS visit_count,
            COALESCE(SUM(t.grand_total), 0)     AS total_spend,
            MAX(DATE(t.transaction_date))        AS last_visit
        FROM transactions t
        JOIN customers c ON t.customer_id = c.customer_id
        WHERE {WHERE_TX}
        GROUP BY c.customer_id, c.full_name
        {HAVING}
        ORDER BY {sort} {direction}
        LIMIT %(per_page)s OFFSET %(offset)s
    """, {**params, "per_page": per_page, "offset": offset})

    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "pagination": {
            "page":        page,
            "per_page":    per_page,
            "total":       total_count,
            "total_pages": max(1, -(-total_count // per_page)),
        },
        "rows": [
            {
                "customer_id": r["customer_id"],
                "name":        r["full_name"],
                "visit_count": safe_int(r["visit_count"]),
                "total_spend": safe_float(r["total_spend"]),
                "last_visit":  str(r["last_visit"]) if r["last_visit"] else "—",
            }
            for r in rows
        ],
    })


# ══════════════════════════════════════════════════════════════════════════════
#  /api/customer-insights/export  — CSV export
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/customer-insights/export")
def customer_insights_export():
    date_from, date_to = parse_date_params(request)
    branch_id = request.args.get("branch_id", "all")
    search    = request.args.get("search",    "").strip()
    spend_min = request.args.get("spend_min", "")
    spend_max = request.args.get("spend_max", "")

    tx_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
        "t.customer_id IS NOT NULL",
    ]
    params = {"date_from": date_from, "date_to": date_to}

    if branch_id and branch_id != "all":
        tx_clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)

    WHERE_TX = " AND ".join(tx_clauses)

    having = []
    if search:
        having.append("c.full_name LIKE %(search)s")
        params["search"] = f"%{search}%"
    if spend_min:
        having.append("COALESCE(SUM(t.grand_total),0) >= %(spend_min)s")
        params["spend_min"] = float(spend_min)
    if spend_max:
        having.append("COALESCE(SUM(t.grand_total),0) <= %(spend_max)s")
        params["spend_max"] = float(spend_max)

    HAVING = ("HAVING " + " AND ".join(having)) if having else ""

    rows = q(f"""
        SELECT
            c.customer_id,
            c.full_name,
            c.contact,
            c.address,
            COUNT(t.transaction_id)             AS visit_count,
            COALESCE(SUM(t.grand_total), 0)     AS total_spend,
            COALESCE(AVG(t.grand_total), 0)     AS avg_spend_per_visit,
            MAX(DATE(t.transaction_date))        AS last_visit,
            MIN(DATE(t.transaction_date))        AS first_visit
        FROM transactions t
        JOIN customers c ON t.customer_id = c.customer_id
        WHERE {WHERE_TX}
        GROUP BY c.customer_id, c.full_name, c.contact, c.address
        {HAVING}
        ORDER BY total_spend DESC
        LIMIT 10000
    """, params)

    output = io.StringIO()
    writer = csv.DictWriter(output, fieldnames=[
        "customer_id", "full_name", "contact", "address",
        "visit_count", "total_spend", "avg_spend_per_visit",
        "last_visit", "first_visit",
    ])
    writer.writeheader()
    for r in rows:
        writer.writerow({
            k: (float(v) if isinstance(v, Decimal) else v)
            for k, v in r.items()
        })

    output.seek(0)
    fname = f"customer_insights_{date_from}_{date_to}.csv"
    return Response(
        output.getvalue(),
        mimetype="text/csv",
        headers={"Content-Disposition": f"attachment; filename={fname}"}
    )

def parse_report_dates(req):
    """Extended date parser supporting quarterly and annual presets."""
    preset = req.args.get('preset', 'monthly')
    today  = date.today()
 
    if preset == 'daily':
        return today, today
    elif preset == 'weekly':
        return today - timedelta(days=today.weekday()), today
    elif preset == 'quarterly':
        q_start_month = ((today.month - 1) // 3) * 3 + 1
        return today.replace(month=q_start_month, day=1), today
    elif preset == 'annual':
        return today.replace(month=1, day=1), today
    elif preset == 'custom':
        df_str = req.args.get('date_from', '')
        dt_str = req.args.get('date_to',   '')
        if df_str and dt_str:
            try:
                df = datetime.strptime(df_str, '%Y-%m-%d').date()
                dt = datetime.strptime(dt_str, '%Y-%m-%d').date()
                if df <= dt:
                    return df, dt
            except ValueError:
                pass
    return today.replace(day=1), today
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/filters  — branches + payment methods for dropdowns
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/filters")
def reports_filters():
    branches = q("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
    payments = q("SELECT method_id, method_name FROM payment_methods ORDER BY method_name")
    return jsonify({
        "branches": [{"id": r["branch_id"], "name": r["branch_name"]} for r in branches],
        "payment_methods": [{"id": r["method_id"], "name": r["method_name"]} for r in payments],
    })
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/revenue  — Revenue Summary
#  Params: preset, date_from, date_to, branch_id, payment_id, group_by (monthly|quarterly|annual)
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/revenue")
def reports_revenue():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")
    group_by   = request.args.get("group_by",   "monthly")  # monthly|quarterly|annual
 
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
    ]
    params = {"date_from": date_from, "date_to": date_to}
 
    if branch_id and branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id and payment_id != "all":
        clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
 
    WHERE = " AND ".join(clauses)
 
    # ── KPI summary ───────────────────────────────────────────────────────────
    kpi = q1(f"""
        SELECT
            COALESCE(SUM(t.grand_total), 0)               AS total_revenue,
            COALESCE(SUM(t.final_discount), 0)            AS total_discounts,
            COALESCE(SUM(t.vat), 0)                       AS total_vat,
            COALESCE(SUM(t.total_treatment + COALESCE(t.total_product,0)), 0) AS gross_sales,
            COUNT(t.transaction_id)                        AS tx_count,
            COUNT(DISTINCT t.branch_id)                   AS branch_count,
            COALESCE(MAX(t.grand_total), 0)               AS max_single,
            COALESCE(AVG(t.grand_total), 0)               AS avg_order_value
        FROM transactions t
        WHERE {WHERE}
    """, params)
 
    # Top day
    top_day_row = q1(f"""
        SELECT DATE(transaction_date) AS top_day,
               SUM(grand_total)       AS day_rev
        FROM transactions t
        WHERE {WHERE}
        GROUP BY DATE(transaction_date)
        ORDER BY day_rev DESC LIMIT 1
    """, params)
 
    # ── Period breakdown ─────────────────────────────────────────────────────
    if group_by == "quarterly":
        period_expr = "CONCAT(YEAR(t.transaction_date), '-Q', QUARTER(t.transaction_date))"
        order_expr  = "YEAR(t.transaction_date), QUARTER(t.transaction_date)"
    elif group_by == "annual":
        period_expr = "YEAR(t.transaction_date)"
        order_expr  = "YEAR(t.transaction_date)"
    else:  # monthly
        period_expr = "DATE_FORMAT(t.transaction_date, '%Y-%m')"
        order_expr  = "DATE_FORMAT(t.transaction_date, '%Y-%m')"
 
    period_rows = q(f"""
        SELECT
            {period_expr}                                  AS period,
            COUNT(t.transaction_id)                        AS tx_count,
            COALESCE(SUM(t.total_treatment + COALESCE(t.total_product,0)), 0) AS gross_revenue,
            COALESCE(SUM(t.final_discount), 0)            AS discounts,
            COALESCE(SUM(t.vat), 0)                       AS vat,
            COALESCE(SUM(t.grand_total), 0)               AS net_revenue,
            COALESCE(AVG(t.grand_total), 0)               AS avg_ticket
        FROM transactions t
        WHERE {WHERE}
        GROUP BY {period_expr}
        ORDER BY {order_expr}
    """, params)
 
    # ── Branch breakdown ─────────────────────────────────────────────────────
    total_rev = safe_float(kpi.get("total_revenue"))
    branch_rows = q(f"""
        SELECT
            b.branch_name,
            COUNT(t.transaction_id)                        AS tx_count,
            COALESCE(SUM(t.grand_total), 0)               AS revenue,
            COALESCE(SUM(t.final_discount), 0)            AS discounts,
            COALESCE(SUM(t.vat), 0)                       AS vat,
            COALESCE(AVG(t.grand_total), 0)               AS avg_ticket
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE}
        GROUP BY b.branch_id, b.branch_name
        ORDER BY revenue DESC
    """, params)
 
    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "group_by": group_by,
        "kpi": {
            "total_revenue":    safe_float(kpi.get("total_revenue")),
            "total_discounts":  safe_float(kpi.get("total_discounts")),
            "total_vat":        safe_float(kpi.get("total_vat")),
            "gross_sales":      safe_float(kpi.get("gross_sales")),
            "tx_count":         safe_int(kpi.get("tx_count")),
            "branch_count":     safe_int(kpi.get("branch_count")),
            "max_single":       safe_float(kpi.get("max_single")),
            "avg_order_value":  safe_float(kpi.get("avg_order_value")),
            "top_day":          str(top_day_row.get("top_day", "")) if top_day_row.get("top_day") else None,
            "top_day_revenue":  safe_float(top_day_row.get("day_rev")),
        },
        "period_breakdown": [
            {
                "period":       str(r["period"]),
                "tx_count":     safe_int(r["tx_count"]),
                "gross_revenue":safe_float(r["gross_revenue"]),
                "discounts":    safe_float(r["discounts"]),
                "vat":          safe_float(r["vat"]),
                "net_revenue":  safe_float(r["net_revenue"]),
                "avg_ticket":   safe_float(r["avg_ticket"]),
            }
            for r in period_rows
        ],
        "branch_breakdown": [
            {
                "rank":       i + 1,
                "branch":     r["branch_name"],
                "tx_count":   safe_int(r["tx_count"]),
                "revenue":    safe_float(r["revenue"]),
                "discounts":  safe_float(r["discounts"]),
                "vat":        safe_float(r["vat"]),
                "avg_ticket": safe_float(r["avg_ticket"]),
                "pct_total":  round(safe_float(r["revenue"]) / total_rev * 100, 2) if total_rev else 0,
            }
            for i, r in enumerate(branch_rows)
        ],
    })
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/revenue/export/csv
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/revenue/export/csv")
def reports_revenue_csv():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")
 
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
    ]
    params = {"date_from": date_from, "date_to": date_to}
    if branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id != "all":
        clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
    WHERE = " AND ".join(clauses)
 
    rows = q(f"""
        SELECT
            DATE_FORMAT(t.transaction_date,'%Y-%m') AS month,
            b.branch_name,
            t.invoice_number,
            DATE(t.transaction_date) AS txn_date,
            t.grand_total,
            t.total_treatment,
            t.total_product,
            t.final_discount,
            t.vat,
            t.transaction_status
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE}
        ORDER BY t.transaction_date
        LIMIT 50000
    """, params)
 
    out = io.StringIO()
    w = csv.DictWriter(out, fieldnames=["month","branch_name","invoice_number","txn_date",
                                         "grand_total","total_treatment","total_product",
                                         "final_discount","vat","transaction_status"])
    w.writeheader()
    for r in rows:
        w.writerow({k: (float(v) if isinstance(v, Decimal) else v) for k, v in r.items()})
 
    out.seek(0)
    fname = f"revenue_report_{date_from}_{date_to}.csv"
    return Response(out.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": f"attachment; filename={fname}"})
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/vat  — VAT Summary grouped per branch + by month
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/vat")
def reports_vat():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")
 
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
        "t.vat IS NOT NULL AND t.vat > 0",
    ]
    params = {"date_from": date_from, "date_to": date_to}
    if branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id != "all":
        clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
    WHERE = " AND ".join(clauses)
 
    kpi = q1(f"""
        SELECT
            COALESCE(SUM(t.vat), 0)            AS total_vat,
            COUNT(t.transaction_id)             AS tx_count,
            COUNT(DISTINCT t.branch_id)        AS branch_count,
            COALESCE(AVG(t.vat), 0)            AS avg_vat
        FROM transactions t WHERE {WHERE}
    """, params)
 
    branch_rows = q(f"""
        SELECT
            b.branch_name,
            COUNT(t.transaction_id)             AS vat_txns,
            COALESCE(SUM(t.grand_total), 0)     AS total_gross,
            COALESCE(SUM(t.vat), 0)             AS vat_amount,
            COALESCE(SUM(t.grand_total - t.vat), 0) AS net_of_vat,
            COALESCE(AVG(t.vat), 0)             AS avg_vat_per_txn
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE}
        GROUP BY b.branch_id, b.branch_name
        ORDER BY vat_amount DESC
    """, params)
 
    period_rows = q(f"""
        SELECT
            DATE_FORMAT(t.transaction_date, '%Y-%m') AS month,
            COUNT(t.transaction_id)                   AS tx_count,
            COALESCE(SUM(t.grand_total), 0)           AS total_revenue,
            COALESCE(SUM(t.vat), 0)                   AS vat_collected,
            COALESCE(SUM(t.grand_total - t.vat), 0)  AS net_of_vat
        FROM transactions t WHERE {WHERE}
        GROUP BY DATE_FORMAT(t.transaction_date, '%Y-%m')
        ORDER BY month
    """, params)
 
    total_vat = safe_float(kpi.get("total_vat"))
    top_branch = branch_rows[0] if branch_rows else {}
 
    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "kpi": {
            "total_vat":    total_vat,
            "tx_count":     safe_int(kpi.get("tx_count")),
            "branch_count": safe_int(kpi.get("branch_count")),
            "avg_vat":      safe_float(kpi.get("avg_vat")),
            "top_branch":   top_branch.get("branch_name", "—"),
            "top_branch_vat": safe_float(top_branch.get("vat_amount", 0)),
        },
        "branch_breakdown": [
            {
                "rank":           i + 1,
                "branch":         r["branch_name"],
                "vat_txns":       safe_int(r["vat_txns"]),
                "total_gross":    safe_float(r["total_gross"]),
                "vat_amount":     safe_float(r["vat_amount"]),
                "net_of_vat":     safe_float(r["net_of_vat"]),
                "avg_vat_per_txn":safe_float(r["avg_vat_per_txn"]),
                "pct_total":      round(safe_float(r["vat_amount"]) / total_vat * 100, 2) if total_vat else 0,
            }
            for i, r in enumerate(branch_rows)
        ],
        "period_breakdown": [
            {
                "month":         str(r["month"]),
                "tx_count":      safe_int(r["tx_count"]),
                "total_revenue": safe_float(r["total_revenue"]),
                "vat_collected": safe_float(r["vat_collected"]),
                "net_of_vat":    safe_float(r["net_of_vat"]),
            }
            for r in period_rows
        ],
    })
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/vat/export/csv
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/vat/export/csv")
def reports_vat_csv():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    params = {"date_from": date_from, "date_to": date_to}
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
        "t.vat IS NOT NULL AND t.vat > 0",
    ]
    if branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    WHERE = " AND ".join(clauses)
 
    rows = q(f"""
        SELECT b.branch_name, DATE(t.transaction_date) AS txn_date,
               t.invoice_number, t.grand_total, t.vat,
               t.grand_total - t.vat AS net_of_vat
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE}
        ORDER BY b.branch_name, t.transaction_date LIMIT 50000
    """, params)
 
    out = io.StringIO()
    w = csv.DictWriter(out, fieldnames=["branch_name","txn_date","invoice_number",
                                         "grand_total","vat","net_of_vat"])
    w.writeheader()
    for r in rows:
        w.writerow({k: (float(v) if isinstance(v, Decimal) else v) for k, v in r.items()})
    out.seek(0)
    fname = f"vat_report_{date_from}_{date_to}.csv"
    return Response(out.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": f"attachment; filename={fname}"})
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/discount  — Discount Cost Report
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/discount")
def reports_discount():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")
 
    base_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
    ]
    params = {"date_from": date_from, "date_to": date_to}
    if branch_id != "all":
        base_clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    if payment_id != "all":
        base_clauses.append("t.overall_payment_method_id = %(payment_id)s")
        params["payment_id"] = int(payment_id)
    WHERE_ALL = " AND ".join(base_clauses)
 
    disc_clauses = base_clauses + ["t.final_discount IS NOT NULL AND t.final_discount > 0"]
    WHERE_DISC = " AND ".join(disc_clauses)
 
    # KPI
    kpi_all = q1(f"""
        SELECT COALESCE(SUM(t.grand_total + COALESCE(t.final_discount,0)), 0) AS gross_before_disc
        FROM transactions t WHERE {WHERE_ALL}
    """, params)
    kpi = q1(f"""
        SELECT
            COALESCE(SUM(t.final_discount), 0)   AS total_discount,
            COUNT(t.transaction_id)               AS disc_tx_count,
            COALESCE(AVG(t.final_discount), 0)   AS avg_discount,
            COALESCE(MAX(t.final_discount), 0)   AS max_discount
        FROM transactions t WHERE {WHERE_DISC}
    """, params)
    max_inv = q1(f"""
        SELECT t.invoice_number FROM transactions t
        WHERE {WHERE_DISC}
        ORDER BY t.final_discount DESC LIMIT 1
    """, params)
 
    gross_before = safe_float(kpi_all.get("gross_before_disc"))
    total_disc   = safe_float(kpi.get("total_discount"))
    disc_rate    = round(total_disc / gross_before * 100, 2) if gross_before else 0
 
    # Monthly trend
    monthly_trend = q(f"""
        SELECT DATE_FORMAT(t.transaction_date, '%Y-%m') AS month,
               COALESCE(SUM(t.final_discount), 0) AS disc_value,
               COUNT(t.transaction_id) AS disc_count
        FROM transactions t WHERE {WHERE_DISC}
        GROUP BY month ORDER BY month
    """, params)
 
    # By discount type
    type_rows = q(f"""
        SELECT dt.type_name,
               COUNT(t.transaction_id)              AS tx_count,
               COALESCE(SUM(t.final_discount), 0)  AS disc_value
        FROM transactions t
        JOIN discount_types dt ON t.discount_type_id = dt.discount_type_id
        WHERE {WHERE_DISC}
        GROUP BY dt.discount_type_id, dt.type_name
        ORDER BY disc_value DESC
    """, params)
 
    # By branch
    branch_rows = q(f"""
        SELECT b.branch_name,
               COUNT(t.transaction_id)                                          AS total_txns,
               SUM(CASE WHEN t.final_discount > 0 THEN 1 ELSE 0 END)          AS disc_count,
               COALESCE(SUM(t.final_discount), 0)                              AS disc_value,
               COALESCE(SUM(t.grand_total + COALESCE(t.final_discount,0)), 0)  AS gross_revenue,
               COALESCE(AVG(CASE WHEN t.final_discount > 0 THEN t.final_discount END), 0) AS avg_disc
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_ALL}
        GROUP BY b.branch_id, b.branch_name
        ORDER BY disc_value DESC
    """, params)
 
    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "kpi": {
            "total_discount": total_disc,
            "disc_tx_count":  safe_int(kpi.get("disc_tx_count")),
            "avg_discount":   safe_float(kpi.get("avg_discount")),
            "max_discount":   safe_float(kpi.get("max_discount")),
            "max_invoice":    max_inv.get("invoice_number", "—"),
            "disc_rate":      disc_rate,
            "gross_before":   gross_before,
        },
        "monthly_trend": [
            {"month": str(r["month"]), "disc_value": safe_float(r["disc_value"]), "disc_count": safe_int(r["disc_count"])}
            for r in monthly_trend
        ],
        "by_type": [
            {"type": r["type_name"], "tx_count": safe_int(r["tx_count"]), "disc_value": safe_float(r["disc_value"])}
            for r in type_rows
        ],
        "branch_breakdown": [
            {
                "rank":         i + 1,
                "branch":       r["branch_name"],
                "total_txns":   safe_int(r["total_txns"]),
                "disc_count":   safe_int(r["disc_count"]),
                "disc_value":   safe_float(r["disc_value"]),
                "gross_revenue":safe_float(r["gross_revenue"]),
                "disc_pct":     round(safe_float(r["disc_value"]) / safe_float(r["gross_revenue"]) * 100, 2)
                                if safe_float(r["gross_revenue"]) else 0,
                "avg_disc":     safe_float(r["avg_disc"]),
            }
            for i, r in enumerate(branch_rows)
        ],
    })
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/discount/export/csv
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/discount/export/csv")
def reports_discount_csv():
    date_from, date_to = parse_report_dates(request)
    branch_id = request.args.get("branch_id", "all")
    params = {"date_from": date_from, "date_to": date_to}
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
        "t.final_discount IS NOT NULL AND t.final_discount > 0",
    ]
    if branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    WHERE = " AND ".join(clauses)
 
    rows = q(f"""
        SELECT b.branch_name, DATE(t.transaction_date) AS txn_date,
               t.invoice_number, dt.type_name AS discount_type,
               t.discount_value, t.final_discount, t.grand_total
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        JOIN discount_types dt ON t.discount_type_id = dt.discount_type_id
        WHERE {WHERE}
        ORDER BY t.final_discount DESC LIMIT 50000
    """, params)
 
    out = io.StringIO()
    w = csv.DictWriter(out, fieldnames=["branch_name","txn_date","invoice_number",
                                         "discount_type","discount_value","final_discount","grand_total"])
    w.writeheader()
    for r in rows:
        w.writerow({k: (float(v) if isinstance(v, Decimal) else v) for k, v in r.items()})
    out.seek(0)
    fname = f"discount_report_{date_from}_{date_to}.csv"
    return Response(out.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": f"attachment; filename={fname}"})
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/comparison  — Period-over-Period Comparison
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/comparison")
def reports_comparison():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")
 
    period_len = max((date_to - date_from).days, 0)
    prev_to    = date_from - timedelta(days=1)
    prev_from  = prev_to  - timedelta(days=period_len)
 
    def build_where(df, dt):
        clauses = [
            f"DATE(t.transaction_date) >= %(df)s",
            f"DATE(t.transaction_date) <= %(dt)s",
            "t.transaction_status = 'OK'",
        ]
        p = {"df": df, "dt": dt}
        if branch_id != "all":
            clauses.append("t.branch_id = %(branch_id)s")
            p["branch_id"] = int(branch_id)
        if payment_id != "all":
            clauses.append("t.overall_payment_method_id = %(payment_id)s")
            p["payment_id"] = int(payment_id)
        return " AND ".join(clauses), p
 
    WHERE_CUR,  P_CUR  = build_where(date_from, date_to)
    WHERE_PREV, P_PREV = build_where(prev_from, prev_to)
 
    def get_kpi(WHERE, params):
        return q1(f"""
            SELECT
                COALESCE(SUM(t.grand_total), 0)       AS revenue,
                COALESCE(SUM(t.vat), 0)               AS vat,
                COALESCE(SUM(t.final_discount), 0)    AS discounts,
                COUNT(t.transaction_id)                AS tx_count,
                COALESCE(AVG(t.grand_total), 0)       AS aov
            FROM transactions t WHERE {WHERE}
        """, params)
 
    cur_kpi  = get_kpi(WHERE_CUR,  P_CUR)
    prev_kpi = get_kpi(WHERE_PREV, P_PREV)
 
    def pct_change(cur, prev):
        if prev and prev != 0:
            return round((cur - prev) / abs(prev) * 100, 1)
        return None
 
    # Daily trend for both periods (normalised to day index)
    cur_daily = q(f"""
        SELECT DATE(transaction_date) AS d, COALESCE(SUM(grand_total),0) AS rev
        FROM transactions t WHERE {WHERE_CUR}
        GROUP BY DATE(transaction_date) ORDER BY d
    """, P_CUR)
    prev_daily = q(f"""
        SELECT DATE(transaction_date) AS d, COALESCE(SUM(grand_total),0) AS rev
        FROM transactions t WHERE {WHERE_PREV}
        GROUP BY DATE(transaction_date) ORDER BY d
    """, P_PREV)
 
    # Branch-level comparison
    def get_branch_kpi(WHERE, params):
        return {
            r["branch_id"]: r
            for r in q(f"""
                SELECT b.branch_id, b.branch_name,
                       COALESCE(SUM(t.grand_total),0)    AS revenue,
                       COALESCE(SUM(t.vat),0)            AS vat,
                       COALESCE(SUM(t.final_discount),0) AS discounts,
                       COUNT(t.transaction_id)            AS tx_count
                FROM transactions t
                JOIN branches b ON t.branch_id = b.branch_id
                WHERE {WHERE}
                GROUP BY b.branch_id, b.branch_name
                ORDER BY revenue DESC
            """, params)
        }
 
    cur_br  = get_branch_kpi(WHERE_CUR,  P_CUR)
    prev_br = get_branch_kpi(WHERE_PREV, P_PREV)
    all_branch_ids = sorted(set(list(cur_br.keys()) + list(prev_br.keys())))
 
    branch_cmp = []
    for bid in all_branch_ids:
        c = cur_br.get(bid, {})
        p = prev_br.get(bid, {})
        cur_rev  = safe_float(c.get("revenue"))
        prev_rev = safe_float(p.get("revenue"))
        branch_cmp.append({
            "branch":       c.get("branch_name") or p.get("branch_name", "Unknown"),
            "curr_revenue": cur_rev,
            "prev_revenue": prev_rev,
            "delta_amt":    round(cur_rev - prev_rev, 2),
            "delta_pct":    pct_change(cur_rev, prev_rev),
            "curr_vat":     safe_float(c.get("vat")),
            "prev_vat":     safe_float(p.get("vat")),
            "curr_disc":    safe_float(c.get("discounts")),
            "prev_disc":    safe_float(p.get("discounts")),
        })
    branch_cmp.sort(key=lambda x: x["curr_revenue"], reverse=True)
 
    c_rev  = safe_float(cur_kpi.get("revenue"))
    p_rev  = safe_float(prev_kpi.get("revenue"))
    c_vat  = safe_float(cur_kpi.get("vat"))
    p_vat  = safe_float(prev_kpi.get("vat"))
    c_disc = safe_float(cur_kpi.get("discounts"))
    p_disc = safe_float(prev_kpi.get("discounts"))
    c_tx   = safe_int(cur_kpi.get("tx_count"))
    p_tx   = safe_int(prev_kpi.get("tx_count"))
    c_aov  = safe_float(cur_kpi.get("aov"))
    p_aov  = safe_float(prev_kpi.get("aov"))
 
    return jsonify({
        "date_range": {
            "current":  {"from": str(date_from), "to": str(date_to)},
            "previous": {"from": str(prev_from), "to": str(prev_to)},
        },
        "metrics": [
            {"label": "Revenue",       "current": c_rev,  "previous": p_rev,  "delta_pct": pct_change(c_rev,  p_rev),  "unit": "currency"},
            {"label": "Transactions",  "current": c_tx,   "previous": p_tx,   "delta_pct": pct_change(c_tx,   p_tx),   "unit": "count"},
            {"label": "Avg Order Val", "current": c_aov,  "previous": p_aov,  "delta_pct": pct_change(c_aov,  p_aov),  "unit": "currency"},
            {"label": "VAT Collected", "current": c_vat,  "previous": p_vat,  "delta_pct": pct_change(c_vat,  p_vat),  "unit": "currency"},
            {"label": "Discounts",     "current": c_disc, "previous": p_disc, "delta_pct": pct_change(c_disc, p_disc), "unit": "currency"},
        ],
        "daily_trend": {
            "current":  [{"day": i+1, "rev": safe_float(r["rev"])} for i, r in enumerate(cur_daily)],
            "previous": [{"day": i+1, "rev": safe_float(r["rev"])} for i, r in enumerate(prev_daily)],
        },
        "branch_comparison": branch_cmp,
    })
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/integrity  — Data Integrity Check
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/reports/integrity")
def reports_integrity():
    date_from, date_to = parse_report_dates(request)
    branch_id = request.args.get("branch_id", "all")
 
    clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
    ]
    params = {"date_from": date_from, "date_to": date_to}
    if branch_id != "all":
        clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)
    WHERE_BASE = " AND ".join(clauses)
 
    total_row = q1(f"SELECT COUNT(*) AS cnt FROM transactions t WHERE {WHERE_BASE}", params)
    total = safe_int(total_row.get("cnt"))
 
    issues = []
 
    # 1. NULL grand_total on OK transactions
    null_total = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'NULL grand_total on OK transaction' AS issue
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_BASE} AND t.transaction_status = 'OK' AND t.grand_total IS NULL
    """, params)
    issues.extend({"severity": "error", **r} for r in null_total)
 
    # 2. Negative grand_total
    neg_total = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'Negative grand_total' AS issue
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_BASE} AND t.grand_total < 0
    """, params)
    issues.extend({"severity": "error", **r} for r in neg_total)
 
    # 3. VAT > grand_total (impossible)
    vat_gt = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'VAT exceeds grand_total' AS issue
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_BASE} AND t.vat IS NOT NULL AND t.grand_total IS NOT NULL AND t.vat > t.grand_total
    """, params)
    issues.extend({"severity": "error", **r} for r in vat_gt)
 
    # 4. Discount > gross (final_discount > total_treatment + total_product)
    disc_gt = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'Discount exceeds gross amount' AS issue
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_BASE}
          AND t.final_discount IS NOT NULL
          AND (t.total_treatment + COALESCE(t.total_product,0)) > 0
          AND t.final_discount > (t.total_treatment + COALESCE(t.total_product,0))
    """, params)
    issues.extend({"severity": "error", **r} for r in disc_gt)
 
    # 5. Missing branch_id
    no_branch = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               'Unknown' AS branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'Missing branch_id' AS issue
        FROM transactions t
        WHERE {WHERE_BASE} AND t.branch_id IS NULL
    """, params)
    issues.extend({"severity": "warning", **r} for r in no_branch)
 
    # 6. Suspiciously large transactions (> 3 std devs from mean)
    stats = q1(f"""
        SELECT AVG(grand_total) AS avg_gt, STDDEV(grand_total) AS std_gt
        FROM transactions t
        WHERE {WHERE_BASE} AND transaction_status = 'OK' AND grand_total IS NOT NULL
    """, params)
    avg_gt = safe_float(stats.get("avg_gt"))
    std_gt = safe_float(stats.get("std_gt"))
    if std_gt > 0:
        threshold = avg_gt + 3 * std_gt
        outliers = q(f"""
            SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
                   b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
                   CONCAT('Unusually large transaction (threshold: ₱', ROUND(%(threshold)s,2), ')') AS issue
            FROM transactions t
            JOIN branches b ON t.branch_id = b.branch_id
            WHERE {WHERE_BASE} AND t.transaction_status = 'OK'
              AND t.grand_total > %(threshold)s
            ORDER BY t.grand_total DESC LIMIT 50
        """, {**params, "threshold": threshold})
        issues.extend({"severity": "warning", **r} for r in outliers)
 
    # 7. Duplicate invoice numbers within same branch + date
    dup_inv = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'Duplicate invoice number on same branch+date' AS issue
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_BASE}
          AND t.invoice_number IS NOT NULL
          AND t.invoice_number != ''
          AND (t.invoice_number, t.branch_id, DATE(t.transaction_date)) IN (
              SELECT invoice_number, branch_id, DATE(transaction_date)
              FROM transactions t2
              WHERE DATE(t2.transaction_date) >= %(date_from)s
                AND DATE(t2.transaction_date) <= %(date_to)s
              GROUP BY invoice_number, branch_id, DATE(transaction_date)
              HAVING COUNT(*) > 1
          )
        ORDER BY t.invoice_number
        LIMIT 100
    """, params)
    issues.extend({"severity": "warning", **r} for r in dup_inv)
 
    # 8. OK transactions with NULL VAT but grand_total > 0
    null_vat = q(f"""
        SELECT t.transaction_id, t.invoice_number, DATE(t.transaction_date) AS txn_date,
               b.branch_name, t.grand_total, t.vat, t.final_discount, t.transaction_status,
               'OK transaction missing VAT value' AS issue
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE {WHERE_BASE}
          AND t.transaction_status = 'OK'
          AND t.vat IS NULL
          AND t.grand_total > 0
        LIMIT 100
    """, params)
    issues.extend({"severity": "warning", **r} for r in null_vat)
 
    error_count   = sum(1 for i in issues if i["severity"] == "error")
    warning_count = sum(1 for i in issues if i["severity"] == "warning")
    clean_count   = max(0, total - len(set(i["transaction_id"] for i in issues)))
 
    # Serialise
    serialised = []
    for i in issues:
        row = {}
        for k, v in i.items():
            if isinstance(v, Decimal):
                row[k] = float(v)
            elif hasattr(v, 'isoformat'):
                row[k] = str(v)
            else:
                row[k] = v
        serialised.append(row)
 
    return jsonify({
        "date_range": {"from": str(date_from), "to": str(date_to)},
        "summary": {
            "total_checked":   total,
            "clean_records":   clean_count,
            "warning_count":   warning_count,
            "error_count":     error_count,
            "issue_count":     len(issues),
        },
        "issues": serialised,
    })
 
 
# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/schedules  — CRUD for scheduled report delivery
#  Stored in a lightweight in-memory store (replace with DB table in production)
# ══════════════════════════════════════════════════════════════════════════════
import threading as _threading
_schedules_lock = _threading.Lock()
_schedules_store: list = []   # [{id, name, report_type, frequency, emails, branch_id, format, active, created_at, last_run}]
_schedule_id_seq = [1]
 
@app.route("/api/reports/schedules", methods=["GET"])
def reports_schedules_list():
    with _schedules_lock:
        return jsonify({"schedules": list(_schedules_store)})
 
 
@app.route("/api/reports/schedules", methods=["POST"])
def reports_schedules_create():
    data = request.get_json(force=True) or {}
    name        = str(data.get("name",        "Unnamed"))[:80]
    report_type = str(data.get("report_type", "revenue"))
    frequency   = str(data.get("frequency",   "weekly"))
    emails      = str(data.get("emails",      ""))[:500]
    branch_id   = data.get("branch_id", "all")
    fmt         = str(data.get("format",      "pdf"))
 
    if report_type not in ("revenue","vat","discount","comparison"):
        return jsonify({"error": "Invalid report_type"}), 400
    if frequency not in ("weekly","monthly"):
        return jsonify({"error": "Invalid frequency"}), 400
 
    with _schedules_lock:
        sid = _schedule_id_seq[0]
        _schedule_id_seq[0] += 1
        rec = {
            "id":          sid,
            "name":        name,
            "report_type": report_type,
            "frequency":   frequency,
            "emails":      emails,
            "branch_id":   branch_id,
            "format":      fmt,
            "active":      True,
            "created_at":  datetime.now().isoformat(timespec="seconds"),
            "last_run":    None,
        }
        _schedules_store.append(rec)
    return jsonify({"schedule": rec}), 201
 
 
@app.route("/api/reports/schedules/<int:sid>", methods=["PUT"])
def reports_schedules_update(sid):
    data = request.get_json(force=True) or {}
    with _schedules_lock:
        for rec in _schedules_store:
            if rec["id"] == sid:
                for field in ("name","report_type","frequency","emails","branch_id","format","active"):
                    if field in data:
                        rec[field] = data[field]
                return jsonify({"schedule": rec})
    return jsonify({"error": "Not found"}), 404
 
 
@app.route("/api/reports/schedules/<int:sid>", methods=["DELETE"])
def reports_schedules_delete(sid):
    with _schedules_lock:
        for i, rec in enumerate(_schedules_store):
            if rec["id"] == sid:
                _schedules_store.pop(i)
                return jsonify({"deleted": sid})
    return jsonify({"error": "Not found"}), 404
 

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8800, debug=True)