<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Bot Dashboard | Live Status</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #25D366;
            --primary-dark: #128C7E;
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: hidden;
        }

        .container {
            width: 100%;
            max-width: 500px;
            padding: 20px;
            text-align: center;
        }

        .card {
            background: var(--card-bg);
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(37, 211, 102, 0.1) 0%, transparent 70%);
            z-index: 0;
        }

        .content {
            position: relative;
            z-index: 1;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(37, 211, 102, 0.1);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 100px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
            border: 1px solid rgba(37, 211, 102, 0.2);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            margin-right: 8px;
            box-shadow: 0 0 10px var(--primary);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }

        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
            background: linear-gradient(to right, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-box {
            background: rgba(255,255,255,0.03);
            padding: 16px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            display: block;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .footer {
            font-size: 12px;
            color: var(--text-muted);
        }

        .logo-ws {
            width: 60px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="content">
                <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WhatsApp" class="logo-ws">
                <div class="status-badge">
                    <div class="status-dot"></div>
                    SISTEMA ONLINE
                </div>
                <h1>Bot de WhatsApp Vivo</h1>
                <p>Tu infraestructura de Laravel 11 está conectada correctamente con Meta Business API.</p>
                
                <div class="stats">
                    <div class="stat-box">
                        <span class="stat-value">Puerto 3001</span>
                        <span class="stat-label">Conexión</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value">MySQL</span>
                        <span class="stat-label">Database</span>
                    </div>
                </div>

                <div class="footer">
                    &copy; 2026 UP STORE | Desarrollado con Laravel 11
                </div>
            </div>
        </div>
    </div>
</body>
</html>
