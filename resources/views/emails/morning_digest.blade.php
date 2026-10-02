<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="rtl" lang="ar">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>الملخص الصباحي اليومي</title>
    <style>
        body, table, td, p, a, li, blockquote {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            direction: rtl !important;
            text-align: right !important;
            font-family: 'Segoe UI', Tahoma, Arial, 'Cairo', 'Tajawal', sans-serif;
        }
        body {
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 32px 28px;
            text-align: center !important;
            color: #ffffff;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 0;
            font-size: 13px;
            color: #94a3b8;
            text-align: center !important;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            background-color: rgba(255, 255, 255, 0.12);
            color: #38bdf8;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .content {
            padding: 32px 28px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }
        .intro-text {
            font-size: 14px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 24px;
        }
        .kpi-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            text-align: center !important;
        }
        .kpi-value {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            direction: ltr !important;
            display: block;
        }
        .kpi-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }
        .sentiment-bar-container {
            background-color: #f1f5f9;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }
        .sentiment-item {
            display: inline-block;
            margin-left: 16px;
            font-size: 12px;
            font-weight: 600;
        }
        .recommendation-box {
            background-color: #f0fdf4;
            border-right: 4px solid #16a34a;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 28px;
        }
        .recommendation-box h4 {
            margin: 0 0 6px 0;
            font-size: 13px;
            color: #15803d;
            font-weight: 700;
        }
        .recommendation-box p {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: #166534;
        }
        .btn-wrapper {
            text-align: center !important;
            margin: 32px 0 16px 0;
        }
        .btn-primary {
            display: inline-block;
            background-color: #0f172a;
            color: #ffffff !important;
            font-size: 14px;
            font-weight: 700;
            padding: 14px 32px;
            border-radius: 10px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 24px;
            text-align: center !important;
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.6;
        }
        .footer p {
            margin: 4px 0;
            text-align: center !important;
        }
    </style>
</head>
<body>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f8fafc; padding: 30px 10px;">
        <tr>
            <td align="center">
                <div class="container" dir="rtl">
                    <!-- Header -->
                    <div class="header">
                        <div class="badge">☀️ تقرير يومي موحّد</div>
                        <h1>الملخص الصباحي لرصد الرأي العام</h1>
                        <p>{{ $tenantName }} • {{ $date }}</p>
                    </div>

                    <!-- Content -->
                    <div class="content" dir="rtl">
                        <div class="greeting">صباح الخير، {{ $recipientName }}</div>
                        <div class="intro-text">
                            إليك الإيجاز الصباحي الموحّد لنتائج الرصد وتحليلات الرأي العام الرقمي خلال آخر 24 ساعة لـ <strong>{{ $tenantName }}</strong> عبر منصة مرآة:
                        </div>

                        <!-- KPI Summary Cards -->
                        <table class="kpi-table" role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                <td width="50%" class="kpi-card">
                                    <span class="kpi-value">{{ number_format($articlesCount) }}</span>
                                    <span class="kpi-label">إجمالي المواد المرصودة</span>
                                </td>
                                <td width="50%" class="kpi-card">
                                    <span class="kpi-value">{{ number_format($interactionsCount) }}</span>
                                    <span class="kpi-label">حجم التفاعل الجماهيري</span>
                                </td>
                            </tr>
                        </table>

                        <!-- Sentiment Breakdown -->
                        <div class="sentiment-bar-container">
                            <div class="section-title">📊 تحليل نبرة المشاعر الرقمية:</div>
                            <table width="100%" cellspacing="0" cellpadding="0" style="margin-top: 8px;">
                                <tr>
                                    <td width="33%" style="text-align: center !important;">
                                        <div style="color: #16a34a; font-size: 18px; font-weight: 800; direction: ltr !important;">{{ $positivePct }}%</div>
                                        <div style="color: #475569; font-size: 11px;">إيجابي</div>
                                    </td>
                                    <td width="33%" style="text-align: center !important;">
                                        <div style="color: #64748b; font-size: 18px; font-weight: 800; direction: ltr !important;">{{ $neutralPct }}%</div>
                                        <div style="color: #475569; font-size: 11px;">محايد</div>
                                    </td>
                                    <td width="33%" style="text-align: center !important;">
                                        <div style="color: #dc2626; font-size: 18px; font-weight: 800; direction: ltr !important;">{{ $negativePct }}%</div>
                                        <div style="color: #475569; font-size: 11px;">سلبي</div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- AI Insight -->
                        <div class="recommendation-box">
                            <h4>💡 الملخص التنفيذي وتوصيات المنظومة:</h4>
                            <p>
                                استقرار إيجابي عام في الانطباعات العامة مع تسجيل نشاط تفاعلي ملحوظ. يُنصح بمواصلة نشر المحتوى التفاعلي والتجاوب السريع مع استفسارات الجمهور لتعزيز الحضور الرقمي الاستراتيجي.
                            </p>
                        </div>

                        <!-- CTA Button -->
                        <div class="btn-wrapper">
                            <a href="{{ $actionUrl }}" class="btn-primary" target="_blank">الانتقال إلى لوحة المراقبة والتحليلات</a>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="footer" dir="rtl">
                        <p>يصلكم هذا التقرير دورياً كل صباح في تمام الساعة 08:00 صباحاً وفق إعدادات التنبيهات لمساحة العمل.</p>
                        <p>© {{ date('Y') }} منصة مرآة للرصد وتحليل الرأي العام. كافة الحقوق محفوظة.</p>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
