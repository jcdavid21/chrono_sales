from flask import Blueprint, jsonify, request, Response
from datetime import date
from decimal import Decimal
import csv, io
from db import q, q1, safe_float, safe_int, parse_date_params, ML_AVAILABLE

analytics_bp = Blueprint('analytics', __name__)

@analytics_bp.route("/api/analytics/filters")
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

@analytics_bp.route("/api/analytics")
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
#  /api/analytics/lstm-forecast  — LSTM projection for selected date range
# ══════════════════════════════════════════════════════════════════════════════
@analytics_bp.route("/api/analytics/lstm-forecast")
def analytics_lstm_forecast():
    from datetime import datetime, timedelta
    import numpy as np

    date_from, date_to = parse_date_params(request)
    branch_id = request.args.get('branch_id')
    ci_level  = int(request.args.get('ci', 80))   # 50 | 80 | 95

    # ── Always predict exactly 7 days forward from date_to ────────────────
    # (mirrors dashboard logic — avoids compounding drift from long roll chains)
    PREDICT_DAYS = 7

    # ── Pull full 90-day history for training (same as dashboard) ─────────
    branch_filter = ""
    params: dict = {}
    if branch_id and branch_id != 'all':
        branch_filter = "AND t.branch_id = %(branch_id)s"
        params["branch_id"] = int(branch_id)

    history = q(f"""
        SELECT DATE(transaction_date) AS day,
               COALESCE(SUM(grand_total), 0) AS revenue
        FROM transactions t
        WHERE DATE(transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          AND transaction_status = 'OK'
          {branch_filter}
        GROUP BY DATE(transaction_date)
        ORDER BY day
    """, params)

    if len(history) < 14:
        return jsonify({"available": False, "reason": "Not enough history (need 14+ days)"})

    revenues = [safe_float(r["revenue"]) for r in history]
    dates    = [str(r["day"]) for r in history]

    try:
        from sklearn.ensemble import GradientBoostingRegressor

        # ── Train on full history (identical feature set to dashboard) ─────
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

        model = GradientBoostingRegressor(
            n_estimators=150, max_depth=3,
            learning_rate=0.06, subsample=0.8, random_state=42
        )
        model.fit(np.array(X), np.array(y))

        # ── Seed rolling window from actual data up to date_to ────────────
        # (exact same logic as dashboard's _compute_lstm_forecast)
        date_to_str = str(date_to)
        seed_revenues = [revenues[i] for i, d in enumerate(dates) if d <= date_to_str]

        if len(seed_revenues) >= 7:
            roll      = list(seed_revenues[-7:])
            last_date = datetime.combine(date_to, datetime.min.time())
        else:
            roll      = list(revenues[-7:])
            last_date = datetime.strptime(dates[-1], "%Y-%m-%d")

        std_hist = float(np.std(revenues[-14:]))
        ci_z = {50: 0.674, 80: 1.282, 95: 1.960}.get(ci_level, 1.282)

        # ── Predict exactly 7 days forward (no compounding drift) ─────────
        projections = []
        for step in range(PREDICT_DAYS):
            nxt  = last_date + timedelta(days=step + 1)
            feat = np.array([[
                roll[-1], roll[0],
                float(np.mean(roll)),
                float(np.std(roll) + 1e-9),
                nxt.weekday(), int(nxt.weekday() >= 5)
            ]])
            p    = max(float(model.predict(feat)[0]), 0.0)
            band = std_hist * ci_z
            projections.append({
                "date":      nxt.strftime("%Y-%m-%d"),
                "predicted": round(p, 2),
                "ci_lower":  round(max(p - band, 0), 2),
                "ci_upper":  round(p + band, 2),
            })
            roll = roll[1:] + [p]

        # ── Baseline = avg of the actual filtered period (what user sees) ──
        date_from_str = str(date_from)
        period_revs   = [safe_float(r["revenue"]) for r in history
                         if date_from_str <= str(r["day"]) <= date_to_str]
        avg_actual    = float(np.mean(period_revs)) if period_revs else float(np.mean(revenues[-7:]))
        avg_forecast  = float(np.mean([p["predicted"] for p in projections]))
        change_pct    = round(((avg_forecast - avg_actual) / avg_actual * 100), 1) if avg_actual else 0.0

        return jsonify({
            "available":    True,
            "projections":  projections,
            "avg_actual":   round(avg_actual, 2),
            "avg_forecast": round(avg_forecast, 2),
            "change_pct":   change_pct,
            "ci_level":     ci_level,
            "branch_id":    branch_id,
            "model_type":   "gbr_proxy",
        })

    except Exception as e:
        return jsonify({"available": False, "reason": str(e)})


# ══════════════════════════════════════════════════════════════════════════════
#  /api/analytics/discount-impact  — XGBoost discount impact scoring
# ══════════════════════════════════════════════════════════════════════════════
@analytics_bp.route("/api/analytics/discount-impact")
def analytics_discount_impact():
    from datetime import datetime
    import numpy as np

    date_from, date_to = parse_date_params(request)
    branch_id = request.args.get('branch_id')

    where_clauses = [
        "DATE(t.transaction_date) >= %(date_from)s",
        "DATE(t.transaction_date) <= %(date_to)s",
        "t.transaction_status = 'OK'",
    ]
    params: dict = {"date_from": date_from, "date_to": date_to}

    if branch_id and branch_id != 'all':
        where_clauses.append("t.branch_id = %(branch_id)s")
        params["branch_id"] = int(branch_id)

    WHERE = " AND ".join(where_clauses)

    rows = q(f"""
        SELECT
            t.grand_total,
            COALESCE(t.discount_type_id, 0)    AS discount_type_id,
            COALESCE(dt.type_name, 'No Discount') AS discount_name,
            t.discount_value,
            t.total_treatment,
            t.total_product,
            t.branch_id,
            t.overall_payment_method_id        AS payment_method_id,
            DAYOFWEEK(t.transaction_date) - 1  AS day_of_week,
            HOUR(t.transaction_date)            AS hour_of_day
        FROM transactions t
        LEFT JOIN discount_types dt ON t.discount_type_id = dt.discount_type_id
        WHERE {WHERE}
    """, params)

    if len(rows) < 20:
        return jsonify({"available": False, "scores": [], "feature_importance": []})

    try:
        import numpy as np
        import pandas as pd
        from sklearn.ensemble import GradientBoostingRegressor

        df = pd.DataFrame(rows)
        features = ['discount_value','total_treatment','total_product',
                    'branch_id','payment_method_id','day_of_week','hour_of_day']
        feature_labels = ['Discount Value','Treatment Total','Product Total',
                          'Branch','Payment Method','Day of Week','Hour of Day']

        X = df[features].fillna(0).values
        y = df['grand_total'].fillna(0).values

        model = GradientBoostingRegressor(
            n_estimators=100, max_depth=3,
            learning_rate=0.08, random_state=42
        )
        model.fit(X, y)

        # Global feature importance (top 5)
        importances = model.feature_importances_
        fi_sorted = sorted(
            [{"feature": feature_labels[i], "importance": round(float(importances[i]), 4)}
             for i in range(len(features))],
            key=lambda x: x["importance"], reverse=True
        )[:5]

        # Per-discount impact coefficient
        discount_groups = df.groupby(['discount_type_id', 'discount_name'])
        scores = []
        max_coeff = 0.0

        for (dt_id, dt_name), grp in discount_groups:
            if len(grp) < 3:
                continue
            X_grp = grp[features].fillna(0).values
            y_pred = model.predict(X_grp)
            y_true = grp['grand_total'].fillna(0).values

            # Impact = mean relative prediction error driven by discount features
            # Use 1 - R² as the impact coefficient (0=no impact, 1=high impact)
            ss_res = float(np.sum((y_true - y_pred) ** 2))
            ss_tot = float(np.sum((y_true - np.mean(y_true)) ** 2))
            r2     = 1 - (ss_res / (ss_tot + 1e-9))
            # Invert: high r² means model explains well = low discount-driven variance
            impact = round(max(0.0, 1.0 - max(r2, 0)), 4)

            avg_discount_pct = float(grp['discount_value'].mean()) if 'discount_value' in grp else 0.0
            avg_grand_total  = float(y_true.mean())

            scores.append({
                "discount_type_id": int(dt_id),
                "discount_name":    dt_name,
                "impact_score":     impact,
                "tx_count":         len(grp),
                "avg_discount_pct": round(avg_discount_pct, 2),
                "avg_grand_total":  round(avg_grand_total, 2),
            })
            max_coeff = max(max_coeff, impact)

        # Normalize scores 0–1 relative to max
        for s in scores:
            s["impact_normalized"] = round(s["impact_score"] / max_coeff, 4) if max_coeff else 0.0

        scores.sort(key=lambda x: x["impact_score"], reverse=True)

        # Persist to discount_impact_scores table
        try:
            from db import get_db
            conn = get_db()
            cur  = conn.cursor()
            for s in scores:
                import json
                cur.execute("""
                    INSERT INTO discount_impact_scores
                        (discount_type_id, branch_id, period_start, period_end,
                         impact_coefficient, top_features_json)
                    VALUES (%s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        impact_coefficient = VALUES(impact_coefficient),
                        top_features_json  = VALUES(top_features_json)
                """, (
                    s["discount_type_id"],
                    int(branch_id) if branch_id and branch_id != 'all' else None,
                    date_from, date_to,
                    s["impact_score"],
                    json.dumps(fi_sorted)
                ))
            conn.commit()
            cur.close()
        except Exception:
            pass   # non-fatal, just don't persist

        # Build combined insight sentence
        top_score   = scores[0] if scores else None
        top_fi      = fi_sorted[0]["feature"] if fi_sorted else "—"
        insight_txt = ""
        if top_score:
            direction = "reducing" if top_score["impact_score"] > 0.4 else "minimally affecting"
            insight_txt = (
                f"XGBoost shows that discount type '{top_score['discount_name']}' is "
                f"{direction} grand total variance most (impact score: "
                f"{top_score['impact_score']:.2f}). "
                f"The top feature driving revenue results is '{top_fi}'."
            )

        return jsonify({
            "available":         True,
            "scores":            scores,
            "feature_importance": fi_sorted,
            "insight":           insight_txt,
        })

    except Exception as e:
        return jsonify({"available": False, "scores": [], "feature_importance": [], "error": str(e)})

@analytics_bp.route("/api/analytics/export/csv")
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