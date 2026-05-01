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