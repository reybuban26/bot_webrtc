<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Password Reset OTP</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f9;
            color: #333333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            padding: 40px;
            text-align: center;
        }
        .header {
            font-size: 24px;
            font-weight: bold;
            color: #4f46e5;
            margin-bottom: 20px;
        }
        .text {
            font-size: 16px;
            line-height: 1.5;
            color: #555555;
            margin-bottom: 30px;
        }
        .otp-box {
            display: inline-block;
            background-color: #f9fafb;
            border: 2px dashed #4f46e5;
            color: #111827;
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 4px;
            padding: 15px 30px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
        .footer {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Password Reset Request</div>
        <div class="text">
            We received a request to reset your password. Please use the One-Time Password (OTP) below to securely verify your identity. This code is valid for <strong>15 minutes</strong>.
        </div>
        <div class="otp-box">
            {{ $otp }}
        </div>
        <div class="text">
            If you did not request a password reset, please ignore this email or contact support if you have concerns.
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
