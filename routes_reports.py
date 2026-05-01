from flask import Blueprint, jsonify, request, Response
from datetime import date, datetime, timedelta
from decimal import Decimal
import csv, io
from db import q, q1, safe_float, safe_int

reports_bp = Blueprint('reports', __name__)

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
@reports_bp.route("/api/reports/filters")
def reports_filters():
    branches = q("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
    payments = q("SELECT method_id, method_name FROM payment_methods ORDER BY method_name")
    return jsonify({
        "branches": [{"id": r["branch_id"], "name": r["branch_name"]} for r in branches],
        "payment_methods": [{"id": r["method_id"], "name": r["method_name"]} for r in payments],
    })

@reports_bp.route("/api/reports/revenue")
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

@reports_bp.route("/api/reports/revenue/export/csv")
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

@reports_bp.route("/api/reports/vat")
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

@reports_bp.route("/api/reports/vat/export/csv")
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

@reports_bp.route("/api/reports/discount")
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

@reports_bp.route("/api/reports/comparison")
def reports_comparison():
    date_from, date_to = parse_report_dates(request)
    branch_id  = request.args.get("branch_id",  "all")
    payment_id = request.args.get("payment_id", "all")

    span      = (date_to - date_from).days + 1
    prev_to   = date_from - timedelta(days=1)
    prev_from = prev_to - timedelta(days=span - 1)

    def get_kpi_and_where(df, dt):
        clauses = [
            "DATE(t.transaction_date) >= %(date_from)s",
            "DATE(t.transaction_date) <= %(date_to)s",
            "t.transaction_status = 'OK'",
        ]
        params = {"date_from": df, "date_to": dt}
        if branch_id != "all":
            clauses.append("t.branch_id = %(branch_id)s")
            params["branch_id"] = int(branch_id)
        if payment_id != "all":
            clauses.append("t.overall_payment_method_id = %(payment_id)s")
            params["payment_id"] = int(payment_id)
        WHERE = " AND ".join(clauses)
        kpi = q1(f"""
            SELECT
                COALESCE(SUM(t.grand_total),0)      AS revenue,
                COUNT(t.transaction_id)              AS tx_count,
                COALESCE(SUM(t.vat),0)              AS vat,
                COALESCE(SUM(t.final_discount),0)   AS discounts,
                COALESCE(AVG(t.grand_total),0)      AS avg_order
            FROM transactions t WHERE {WHERE}
        """, params)
        return kpi, WHERE, params

    def delta(c, p):
        c, p = safe_float(c), safe_float(p)
        return round((c - p) / p * 100, 2) if p else None

    def daily_trend(WHERE, params):
        rows = q(f"""
            SELECT DATE(t.transaction_date) AS day,
                   COALESCE(SUM(t.grand_total),0) AS rev
            FROM transactions t WHERE {WHERE}
            GROUP BY DATE(t.transaction_date) ORDER BY day
        """, params)
        return [{"day": str(r["day"]), "rev": safe_float(r["rev"])} for r in rows]

    def branch_kpi(WHERE, params):
        rows = q(f"""
            SELECT b.branch_name,
                   COALESCE(SUM(t.grand_total),0)    AS revenue,
                   COALESCE(SUM(t.vat),0)            AS vat,
                   COALESCE(SUM(t.final_discount),0) AS disc
            FROM transactions t
            JOIN branches b ON t.branch_id = b.branch_id
            WHERE {WHERE}
            GROUP BY b.branch_id, b.branch_name
        """, params)
        return {r["branch_name"]: r for r in rows}

    curr_kpi, curr_where, curr_params = get_kpi_and_where(date_from, date_to)
    prev_kpi, prev_where, prev_params = get_kpi_and_where(prev_from, prev_to)

    metrics = [
        {"label": "Revenue",      "unit": "currency", "current": safe_float(curr_kpi.get("revenue")),   "previous": safe_float(prev_kpi.get("revenue")),   "delta_pct": delta(curr_kpi.get("revenue"),   prev_kpi.get("revenue"))},
        {"label": "Transactions", "unit": "count",    "current": safe_int(curr_kpi.get("tx_count")),    "previous": safe_int(prev_kpi.get("tx_count")),    "delta_pct": delta(curr_kpi.get("tx_count"),   prev_kpi.get("tx_count"))},
        {"label": "VAT",          "unit": "currency", "current": safe_float(curr_kpi.get("vat")),       "previous": safe_float(prev_kpi.get("vat")),       "delta_pct": delta(curr_kpi.get("vat"),        prev_kpi.get("vat"))},
        {"label": "Discounts",    "unit": "currency", "current": safe_float(curr_kpi.get("discounts")), "previous": safe_float(prev_kpi.get("discounts")), "delta_pct": delta(curr_kpi.get("discounts"),  prev_kpi.get("discounts"))},
        {"label": "Avg Order",    "unit": "currency", "current": safe_float(curr_kpi.get("avg_order")), "previous": safe_float(prev_kpi.get("avg_order")), "delta_pct": delta(curr_kpi.get("avg_order"),  prev_kpi.get("avg_order"))},
    ]

    curr_b = branch_kpi(curr_where, curr_params)
    prev_b = branch_kpi(prev_where, prev_params)
    all_branches = sorted(set(list(curr_b.keys()) + list(prev_b.keys())))
    branch_comparison = []
    for name in all_branches:
        c = curr_b.get(name, {})
        p = prev_b.get(name, {})
        cr = safe_float(c.get("revenue")); pr = safe_float(p.get("revenue"))
        branch_comparison.append({
            "branch":       name,
            "curr_revenue": cr,
            "prev_revenue": pr,
            "delta_amt":    cr - pr,
            "delta_pct":    delta(cr, pr),
            "curr_vat":     safe_float(c.get("vat")),
            "prev_vat":     safe_float(p.get("vat")),
            "curr_disc":    safe_float(c.get("disc")),
            "prev_disc":    safe_float(p.get("disc")),
        })

    return jsonify({
        "date_range": {
            "current":  {"from": str(date_from), "to": str(date_to)},
            "previous": {"from": str(prev_from),  "to": str(prev_to)},
        },
        "metrics":           metrics,
        "daily_trend":       {"current": daily_trend(curr_where, curr_params), "previous": daily_trend(prev_where, prev_params)},
        "branch_comparison": branch_comparison,
    })
 

# ══════════════════════════════════════════════════════════════════════════════
#  /api/reports/discount/export/csv
# ══════════════════════════════════════════════════════════════════════════════
@reports_bp.route("/api/reports/discount/export/csv")
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

@reports_bp.route("/api/reports/integrity")
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
 
@reports_bp.route("/api/reports/schedules", methods=["GET"])
def reports_schedules_list():
    with _schedules_lock:
        return jsonify({"schedules": list(_schedules_store)})
 
 
@reports_bp.route("/api/reports/schedules", methods=["POST"])
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
 
 
@reports_bp.route("/api/reports/schedules/<int:sid>", methods=["PUT"])
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
 
 
@reports_bp.route("/api/reports/schedules/<int:sid>", methods=["DELETE"])
def reports_schedules_delete(sid):
    with _schedules_lock:
        for i, rec in enumerate(_schedules_store):
            if rec["id"] == sid:
                _schedules_store.pop(i)
                return jsonify({"deleted": sid})
    return jsonify({"error": "Not found"}), 404