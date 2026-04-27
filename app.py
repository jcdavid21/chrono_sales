from flask import Flask, jsonify, request, Response
from flask_cors import CORS
import mysql.connector
import os, json, csv, io
from datetime import datetime, timedelta, date
from decimal import Decimal
import numpy as np

# Optional ML imports — graceful fallback if not installed
try:
    from sklearn.ensemble import GradientBoostingRegressor
    import shap
    ML_AVAILABLE = True
except ImportError:
    ML_AVAILABLE = False

app = Flask(__name__)
CORS(app, origins=["http://localhost", "http://127.0.0.1"])

# ── DB config ─────────────────────────────────────────────────────────────────
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME",     "chrono_sales_db"),
}

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
        try:
            df = datetime.strptime(req.args.get('date_from', ''), '%Y-%m-%d').date()
            dt = datetime.strptime(req.args.get('date_to',   ''), '%Y-%m-%d').date()
            return df, dt
        except:
            pass
    # default: monthly
    return today.replace(day=1), today


# ══════════════════════════════════════════════════════════════════════════════
#  /api/dashboard  — main dashboard payload with SHAP forecast alert
# ══════════════════════════════════════════════════════════════════════════════
@app.route("/api/dashboard")
def dashboard():
    today       = date.today()
    week_start  = today - timedelta(days=today.weekday())   # Monday
    month_start = today.replace(day=1)

    # ── 1. Revenue metric cards ───────────────────────────────────────────────
    rev_today = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) = %s
          AND transaction_status = 'OK'
    """, (today,))

    rev_week = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND transaction_status = 'OK'
    """, (week_start,))

    rev_month = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND transaction_status = 'OK'
    """, (month_start,))

    # Prior month for % change
    prior_month_start = (month_start - timedelta(days=1)).replace(day=1)
    rev_prior_month = q1("""
        SELECT COALESCE(SUM(grand_total),0) AS revenue
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND DATE(transaction_date) < %s
          AND transaction_status = 'OK'
    """, (prior_month_start, month_start))

    prior_rev  = safe_float(rev_prior_month.get("revenue"))
    cur_rev    = safe_float(rev_month.get("revenue"))
    mom_change = round(((cur_rev - prior_rev) / prior_rev * 100), 1) if prior_rev else 0.0

    # ── 2. 30-day sparkline ───────────────────────────────────────────────────
    sparkline_rows = q("""
        SELECT DATE(transaction_date) AS day,
               COALESCE(SUM(grand_total),0) AS revenue,
               COUNT(*) AS tx_count
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND transaction_status = 'OK'
        GROUP BY DATE(transaction_date)
        ORDER BY day
    """, (today - timedelta(days=29),))

    day_map   = {str(r["day"]): r for r in sparkline_rows}
    sparkline = []
    for i in range(30):
        d = str(today - timedelta(days=29 - i))
        if d in day_map:
            sparkline.append({
                "date":    d,
                "revenue": safe_float(day_map[d]["revenue"]),
                "tx":      int(day_map[d]["tx_count"]),
            })
        else:
            sparkline.append({"date": d, "revenue": 0.0, "tx": 0})

    # ── 3. Top branches ───────────────────────────────────────────────────────
    top_branches = q("""
        SELECT b.branch_name,
               COALESCE(SUM(t.grand_total),0) AS revenue,
               COUNT(t.transaction_id)         AS tx_count,
               COALESCE(AVG(t.grand_total),0)  AS avg_ticket
        FROM transactions t
        JOIN branches b ON t.branch_id = b.branch_id
        WHERE DATE(t.transaction_date) >= %s
          AND t.transaction_status = 'OK'
        GROUP BY b.branch_id, b.branch_name
        ORDER BY revenue DESC
        LIMIT 8
    """, (month_start,))

    # ── 4. Payment method breakdown ───────────────────────────────────────────
    payment_breakdown = q("""
        SELECT pm.method_name,
               COUNT(t.transaction_id)        AS tx_count,
               COALESCE(SUM(t.grand_total),0) AS revenue
        FROM transactions t
        JOIN payment_methods pm ON t.overall_payment_method_id = pm.method_id
        WHERE DATE(t.transaction_date) >= %s
          AND t.transaction_status = 'OK'
        GROUP BY pm.method_id, pm.method_name
        ORDER BY revenue DESC
    """, (month_start,))

    # ── 5. Avg ticket + tx count trend (weekly buckets, last 12 weeks) ────────
    tx_trend = q("""
        SELECT YEARWEEK(transaction_date, 1)  AS yw,
               MIN(DATE(transaction_date))    AS week_start,
               COUNT(*)                       AS tx_count,
               COALESCE(AVG(grand_total), 0)  AS avg_ticket
        FROM transactions
        WHERE DATE(transaction_date) >= %s
          AND transaction_status = 'OK'
        GROUP BY yw
        ORDER BY yw
        LIMIT 12
    """, (today - timedelta(weeks=12),))

    # ── 6. SHAP-powered forecast alert ───────────────────────────────────────
    shap_result = _compute_shap_forecast(sparkline)

    # ── Assemble ──────────────────────────────────────────────────────────────
    payload = {
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


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8800, debug=True)