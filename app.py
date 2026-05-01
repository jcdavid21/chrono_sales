from flask import Flask
from flask_cors import CORS
from db import (get_db, q, q1, safe_float, safe_int,
                parse_date_params, parse_report_dates,
                ML_AVAILABLE, _training_jobs, DB_CONFIG)

app = Flask(__name__)
CORS(app, origins=["http://localhost", "http://127.0.0.1",
                   "http://localhost:3000", "http://127.0.0.1:3000"])

from routes_dashboard import dashboard_bp
from routes_analytics import analytics_bp
from routes_dm import dm_bp
from routes_payments import payments_bp
from routes_reports import reports_bp
from routes_customers import customers_bp

app.register_blueprint(dashboard_bp)
app.register_blueprint(analytics_bp)
app.register_blueprint(dm_bp)
app.register_blueprint(payments_bp)
app.register_blueprint(reports_bp)
app.register_blueprint(customers_bp)

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8800, debug=True, use_reloader=False)