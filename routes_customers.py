from flask import Blueprint, jsonify, request, Response
from datetime import date
from decimal import Decimal
import csv, io
from db import q, q1, safe_float, safe_int, parse_date_params, ML_AVAILABLE

customers_bp = Blueprint('customers', __name__)


@customers_bp.route("/api/customer-insights/filters")
def customer_insights_filters():
    branches = q("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
    return jsonify({
        "branches": [{"id": r["branch_id"], "name": r["branch_name"]} for r in branches],
    })


@customers_bp.route("/api/customer-insights")
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


@customers_bp.route("/api/customer-insights/table")
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


@customers_bp.route("/api/customer-insights/export")
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
