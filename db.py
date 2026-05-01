import os
import mysql.connector
from datetime import datetime, timedelta, date
from decimal import Decimal

try:
    from sklearn.ensemble import GradientBoostingRegressor
    import shap
    ML_AVAILABLE = True
except ImportError:
    ML_AVAILABLE = False

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
                pass
    return today.replace(day=1), today

def parse_report_dates(req):
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