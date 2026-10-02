<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="rtl" lang="ar">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>تنبيه تراجع مؤشر السمعة</title>
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
            border: 1px solid #fee2e2;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background: linear-gradient(135deg, #991b1b 0%, #b91c1c 100%);
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
            color: #fecaca;
            text-align: center !important;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            background-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
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
        .alert-box {
            background-color: #fef2f2;
            border-right: 4px solid #ef4444;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .alert-box p {
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
            color: #991b1b;
            font-weight: 500;
        }
        .kpi-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background-color: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 12px;
            padding: 16px;
            text-align: center !important;
        }
        .kpi-value {
            font-size: 24px;
            font-weight: 800;
            color: #be123c;
            margin-bottom: 4px;
            direction: ltr !important;
            display: block;
        }
        .kpi-label {
            font-size: 12px;
            color: #881337;
            font-weight: 600;
        }
        .recommendation-box {
            background-color: #fffbeb;
            border-right: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 28px;
        }
        .recommendation-box h4 {
            margin: 0 0 6px 0;
            font-size: 13px;
            color: #b45309;
            font-weight: 700;
        }
        .recommendation-box p {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: #92400e;
        }
        .btn-wrapper {
            text-align: center !important;
            margin: 32px 0 16px 0;
        }
        .btn-danger {
            display: inline-block;
            background-color: #dc2626;
            color: #ffffff !important;
            font-size: 14px;
            font-weight: 700;
            padding: 14px 32px;
            border-radius: 10px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
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
                        <div class="badge">⚠️ إنذار فوري ومباشر</div>
                        <h1>تنبيه تراجع مؤشر السمعة العام</h1>
                        <p>{{ $tenantName }} • تم الرصد الآن</p>
                    </div>

                    <!-- Content -->
                    <div class="content" dir="rtl">
                        <div class="greeting">مرحباً، {{ $recipientName }}</div>

                        <!-- Alert Notice -->
                        <div class="alert-box">
                            <p>
                                رصدت خوارزميات الاستشعار والتحليل تراجعاً ملحوظاً في مؤشر السمعة الرقمي لـ <strong>{{ $tenantName }}</strong>، حيث انخفض المؤشر الحالي دون الحد الأدنى الحرج المحدد في إعدادات المنظومة ({{ $threshold }}%).
                            </p>
                        </div>

                        <!-- KPI Summary Cards -->
                        <table class="kpi-table" role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                <td width="33%" class="kpi-card">
                                    <span class="kpi-value">{{ $currentScore }}%</span>
                                    <span class="kpi-label">المؤشر الحالي</span>
                                </td>
                                <td width="33%" class="kpi-card" style="background-color: #f8fafc; border-color: #e2e8f0;">
                                    <span class="kpi-value" style="color: #475569;">{{ $threshold }}%</span>
                                    <span class="kpi-label" style="color: #64748b;">الحد الأدنى</span>
                                </td>
                                <td width="33%" class="kpi-card">
                                    <span class="kpi-value">{{ $negativeCount }}</span>
                                    <span class="kpi-label">إشارات سلبية</span>
                                </td>
                            </tr>
                        </table>

                        <!-- Urgent Recommendation -->
                        <div class="recommendation-box">
                            <h4>🚨 إجراءات الاستجابة السريعة الموصى بها:</h4>
                            <p>
                                يوصى بتدخل فريق العلاقات العامة والاتصال المؤسسي لمراجعة مصادر المنشورات السلبية والشكاوى الأكثر تداولاً، وتقديم الردود والحلول الرسمية بشكل عاجل للحد من اتساع الفجوة وتصحيح المسار.
                            </p>
                        </div>

                        <!-- CTA Button -->
                        <div class="btn-wrapper">
                            <a href="{{ $actionUrl }}" class="btn-danger" target="_blank">الانتقال إلى لوحة السمعة الرقمية والشكاوى</a>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="footer" dir="rtl">
                        <p>تم إرسال هذا التنبيه الفوري بناءً على تفعيل خيار إشعارات مؤشر السمعة لمساحة عملكم.</p>
                        <p>© {{ date('Y') }} منصة مرآة للرصد وتحليل الرأي العام. كافة الحقوق محفوظة.</p>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
