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