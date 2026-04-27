# Chrono Sales System

## How to Set Up

1. **Start XAMPP Services**
   - Open XAMPP and start both **Apache** and **MySQL**.

2. **Create the Database**
   - Open phpMyAdmin (usually at http://localhost/phpmyadmin).
   - Create a new database named `chrono_sales_db`.
   - Import the `chrono_sales_db.sql` file from this folder into the database.

3. **Set Up Python Environment**
   - Open a terminal in the project folder.
   - For Windows:
     - Install virtual environment: `python -m venv .venv`
     - Activate: `.venv\Scripts\activate`
   - For macOS/Linux:
     - Install virtual environment: `python3 -m venv .venv`
     - Activate: `source .venv/bin/activate`
   - Install requirements: `pip install -r requirements.txt`

4. **Run the Backend**
   - Start the Python backend: `python app.py`

5. **Access the Application**
   - Open your browser and go to `http://localhost/index.php` (or the correct path if not in web root).
   - Log in with:
     - **Username:** admin@chrono.sales.com
     - **Password:** admin123

---


## System Overview & Page Functionality

### Main Files & Folders
- **app.py**: Python backend server (API, ML/SHAP logic, analytics endpoints)
- **backend/**: PHP backend scripts for database and authentication
- **public/**: Main PHP pages for the web interface
- **assets/**: CSS, JS, and images
- **chrono_sales_db.sql**: Database schema and sample data

---

## Page Features & Core Modules

### Dashboard (`dashboard.php`)
- Real-time sales summary: revenue today, this week, this month
- 30-day sales trend sparkline
- Top-performing branches (by revenue)
- Payment method breakdown (pie/donut chart)
- Transaction trend (weekly)
- **SHAP-powered Forecast Alert:**
   - Uses machine learning (GradientBoostingRegressor + SHAP) to predict tomorrow’s revenue
   - Explains which factors (recent sales, day of week, etc.) drive the forecast up or down
   - Shows alert if a surge or dip is likely
   - Visualizes SHAP feature importance (why the forecast changed)

### Sales Analytics (`sales-analytics.php`)
- Deep-dive analytics with flexible filters:
   - Date range (daily, weekly, monthly, custom)
   - Branch, payment method, discount type, transaction status
- Revenue by branch (bar chart)
- Daily revenue trend (line chart)
- Sales heatmap (day-of-week × hour)
- Discount analysis (by type)
- Top 10 customers (table)
- Payment method breakdown
- Quick insights (top branch, customer, payment)
- Export analytics to CSV or PDF

### Data Management (`data-management.php`)
- Admin interface for managing all sales data
- Tabs for Transactions, Customers, Branches
- Add, edit, delete, and bulk import/export records (CSV)
- Search, filter, and sort records
- Bulk actions (delete, import)
- Data validation and error reporting
- Audit log and integrity checks

### Other Core Pages
- **Forecast**: Advanced time-series forecasting (future revenue, model selection, what-if analysis)
- **Branch Performance**: Compare branches, growth, and risk
- **Payment Insights**: Analyze payment trends and breakdowns
- **Customer Insights**: Track top/repeat customers, spending patterns
- **Reports**: Generate and export formal business reports (monthly, quarterly, annual)
- **Settings**: Manage branches, users, forecasting preferences, and alert rules

### Backend (backend/)
- **db.php**: Database connection logic
- **login.php**: Handles user login authentication
- **logout.php**: Handles user logout
- **api_proxy.php**: Bridges frontend requests to backend APIs

### Assets
- **assets/css/**: Stylesheets for different pages
- **assets/js/**: JavaScript for page interactivity
- **assets/imgs/**: Images used in the UI

---

## Machine Learning & SHAP Core
- The dashboard uses a machine learning model (GradientBoostingRegressor) to forecast next-day revenue.
- **SHAP (SHapley Additive exPlanations)** explains which features (recent sales, day of week, etc.) most influenced the forecast.
- If ML libraries are not installed, the system falls back to a simple trend-based forecast.
- SHAP feature importance is visualized for transparency and actionable insights.

---

## Default Admin Credentials
- **Username:** admin@chrono.sales.com
- **Password:** admin123

---

## Notes
- Make sure Apache and MySQL are running before accessing the site.
- The Python backend must be running for analytics and ML features to work.
- If you need to reset the admin user, run `create_dummy.php` once.
