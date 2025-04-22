<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Password Reset Code</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f9f9f9;
            color: #333;
            padding: 30px;
        }

        .container {
            max-width: 600px;
            margin: auto;
            background: white;
            padding: 25px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .code {
            font-size: 28px;
            font-weight: bold;
            color: #1a73e8;
            letter-spacing: 2px;
        }

        .footer {
            margin-top: 30px;
            font-size: 13px;
            color: #777;
        }

        .highlight {
            font-weight: bold;
            color: #000;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2>Password Reset Request</h2>

        <p>Hello,</p>

        <p>You requested to reset your password. Please use the code below to reset your password:</p>

        <p class="code">{{ $code }}</p>

        <p>This code is valid for the next <span class="highlight">15 minutes</span>. If you didn’t request this, you
            can safely ignore this email.</p>

        <p>Thank you,<br>
            {{ config('app.name') }} Team</p>

        <div class="footer">
            <p>If you have any questions, contact our support team.</p>
        </div>
    </div>
</body>

</html>